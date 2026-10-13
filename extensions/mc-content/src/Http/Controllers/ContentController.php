<?php

namespace McContent\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use McContent\Models\Install;
use McContent\Services\GameVersionDetector;
use McContent\Services\InstallDetector;
use McContent\Services\ModrinthClient;
use McContent\Services\ServerFiles;
use McContent\Support\ContentKind;
use McContent\Support\Updates;
use Pterodactyl\Contracts\Files\CreatesDirectories;
use Pterodactyl\Contracts\Files\DeletesFiles;
use Pterodactyl\Contracts\Files\PullsFiles;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\Extensions\ExtensionSettingsRegistry;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ContentController extends Controller
{
    private const string VERSION_PATTERN = '/^\d+(\.\d+){1,2}([-.][A-Za-z0-9.]+)?$/';

    private const string JAR_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._+\-()\[\] ]{0,200}\.jar$/';

    private const array SORTS = ['relevance', 'downloads', 'follows', 'newest', 'updated'];

    private const int MAX_DEPENDENCIES = 10;

    /** At most this many extra version lists are fetched per page load to find stable updates. */
    private const int MAX_UPDATE_LOOKUPS = 15;

    public function __construct(
        private readonly ModrinthClient $modrinth,
        private readonly ExtensionSettingsRegistry $settings,
        private readonly ServerFiles $files,
    ) {}

    /**
     * What the page needs to start: the detected loader and Minecraft version, the
     * loaders the user can pick from, and the release versions Modrinth knows about.
     */
    public function context(Request $request, Server $server, string $kind, GameVersionDetector $detector): JsonResponse
    {
        $type = ContentKind::from($kind);
        $this->authorizeRead($request, $server);
        $detected = $detector->detect($server);
        $platform = $this->files->platform($server);

        try {
            $gameVersions = array_slice($this->modrinth->gameVersions(), 0, 80);
        } catch (HttpException) {
            $gameVersions = [];
        }

        return new JsonResponse([
            'kind' => $type->value,
            'directory' => $type->directory(),
            'loaders' => $type->loaders(),
            'detected_loader' => $this->files->loader($server, $type),
            // What the server's files say it runs. When that is the other kind of
            // content, the page says so and refuses installs into the wrong folder.
            'platform' => $platform,
            'detected_game_version' => $detected['version'],
            'game_version_source' => $detected['source'],
            'game_versions' => $gameVersions,
            'egg' => $server->egg?->name,
        ]);
    }

    /**
     * Which content pages this server should show: what its files say it runs, or,
     * before it has ever started, what its egg tags say. Light enough for the
     * navigation to ask on every server page.
     */
    public function platform(Request $request, Server $server): JsonResponse
    {
        $this->authorizeRead($request, $server);

        $platform = $this->files->platform($server);
        if ($platform !== null) {
            return new JsonResponse(['platform' => $platform, 'kinds' => [$platform['kind']]]);
        }

        $kinds = [];
        foreach ([ContentKind::Plugins, ContentKind::Mods] as $type) {
            if ($type->detectLoader($server) !== null || in_array($type->value, array_map('strtolower', $server->tagSlugs()), true)) {
                $kinds[] = $type->value;
            }
        }

        return new JsonResponse(['platform' => null, 'kinds' => $kinds]);
    }

    /**
     * Remember the Minecraft version for this server, so nobody has to pick it again.
     * Sending null forgets it and goes back to automatic detection.
     */
    public function preferences(Request $request, Server $server, string $kind, GameVersionDetector $detector): JsonResponse
    {
        abort_unless($request->user()->can('file.create', $server), 403, 'You do not have permission to change this server\'s content settings.');

        $data = $request->validate([
            'game_version' => ['present', 'nullable', 'string', 'regex:'.self::VERSION_PATTERN],
        ]);

        $detector->save($server, $data['game_version']);
        $detector->forget($server);

        return new JsonResponse($detector->detect($server));
    }

    public function search(Request $request, Server $server, string $kind): JsonResponse
    {
        $type = ContentKind::from($kind);
        $this->authorizeRead($request, $server);
        $data = $this->validateSearch($request, $type);

        $limit = max(5, min(50, (int) ($this->setting('results_per_page') ?? 20)));
        $page = max(1, (int) ($data['page'] ?? 1));

        $result = $this->modrinth->search(
            $type,
            (string) ($data['query'] ?? ''),
            ContentKind::compatibleLoaders($data['loader']),
            ContentKind::isProxy($data['loader']) ? null : ($data['game_version'] ?? null),
            $data['sort'] ?? 'relevance',
            ($page - 1) * $limit,
            $limit,
        );

        // Mark the projects already on this server so the page can offer an
        // update or version change instead of a second copy.
        $installed = $this->installedByProject($server, $type);
        $result['hits'] = array_map(fn (array $hit): array => [
            ...$hit,
            'installed' => $installed[$hit['project_id']] ?? null,
        ], $result['hits']);

        return new JsonResponse($result);
    }

    public function versions(Request $request, Server $server, string $kind, string $project): JsonResponse
    {
        $type = ContentKind::from($kind);
        $this->authorizeRead($request, $server);
        $data = $this->validateSearch($request, $type);

        $gameVersion = ContentKind::isProxy($data['loader']) ? null : ($data['game_version'] ?? null);
        $versions = $this->modrinth->versions($project, ContentKind::compatibleLoaders($data['loader']), $gameVersion);
        $versions = array_values(array_filter($versions, fn (array $version): bool => $version['file'] !== null));

        if (! $this->setting('allow_prereleases')) {
            $releases = array_values(array_filter($versions, fn (array $version): bool => $version['version_type'] === 'release'));
            // Some projects only publish betas; show those rather than nothing.
            $versions = $releases !== [] ? $releases : $versions;
        }

        $current = Install::query()
            ->where('server_id', $server->id)
            ->where('kind', $type->value)
            ->where('project_id', $project)
            ->first();

        return new JsonResponse([
            'data' => array_slice($versions, 0, 50),
            'installed' => $current === null ? null : [
                'filename' => $current->filename,
                'version_id' => $current->version_id,
                'version_number' => $current->version_number,
            ],
        ]);
    }

    /**
     * The .jar files currently in the plugins or mods folder, with Modrinth details
     * and available updates for the ones this extension installed.
     */
    public function installed(
        Request $request,
        Server $server,
        string $kind,
        InstallDetector $detector,
        GameVersionDetector $versions,
    ): JsonResponse
    {
        $type = ContentKind::from($kind);
        $this->authorizeRead($request, $server);

        $loader = $request->query('loader');
        // No loader known: list the files, but check no updates rather than guess one.
        $loader = is_string($loader) && in_array($loader, $type->loaders(), true) ? $loader : $this->files->loader($server, $type);
        $gameVersion = $request->query('game_version');
        $gameVersion = is_string($gameVersion) && preg_match(self::VERSION_PATTERN, $gameVersion) === 1 ? $gameVersion : null;
        // Without a version, "latest" could be a build for another Minecraft version.
        $gameVersion ??= $versions->detect($server)['version'];
        if ($loader !== null && ContentKind::isProxy($loader)) {
            $gameVersion = null;
        }

        $files = $this->files->list($server, $type->directory());
        if ($files === null) {
            return new JsonResponse(['data' => [], 'directory_exists' => false, 'pending' => 0, 'loader' => $loader]);
        }

        $jars = $this->jars($files);

        // Recognise jars that were added by hand; large folders are done in batches.
        $tracked = Install::query()->where('server_id', $server->id)->where('kind', $type->value)->pluck('filename')->all();
        $pending = $detector->identify($server, $type, $jars, $tracked, 25);

        $records = Install::query()
            ->where('server_id', $server->id)
            ->where('kind', $type->value)
            ->get()
            ->keyBy('filename');

        // Forget installs whose file was deleted or renamed outside the panel.
        $stale = $records->keys()->diff(array_keys($jars));
        if ($stale->isNotEmpty()) {
            Install::query()->where('server_id', $server->id)->where('kind', $type->value)->whereIn('filename', $stale->all())->delete();
            $records = $records->except($stale->all());
        }

        $updates = [];
        $current = [];
        $hashes = $records->pluck('sha1')->filter()->values()->all();
        $allowPrereleases = (bool) $this->setting('allow_prereleases');
        $lookups = 0;
        if ($hashes !== [] && $loader !== null) {
            try {
                $updates = $this->modrinth->latestForHashes($hashes, ContentKind::compatibleLoaders($loader), $gameVersion);
                $current = $this->modrinth->versionsByIds($records->pluck('version_id')->filter()->values()->all());
            } catch (HttpException $exception) {
                Log::warning('mc-content: update check failed', ['message' => $exception->getMessage()]);
            }
        }

        $rows = [];
        foreach ($jars as $name => $file) {
            /** @var Install|null $record */
            $record = $records->get($name);
            $latest = $record?->sha1 !== null ? ($updates[$record->sha1] ?? null) : null;
            $installedVersion = $record !== null ? ($current[$record->version_id] ?? null) : null;

            // The bulk lookup ignores release type, and can name a build for another
            // Minecraft version. When its answer does not qualify, look further down
            // the project's own version list for one that does.
            if ($latest !== null && $record !== null && ! Updates::acceptable($installedVersion, $latest, $allowPrereleases, $gameVersion)) {
                $latest = null;
                if ($lookups < self::MAX_UPDATE_LOOKUPS) {
                    $lookups++;
                    try {
                        $latest = Updates::pick($installedVersion, $this->modrinth->versions($record->project_id, ContentKind::compatibleLoaders($loader), $gameVersion), $allowPrereleases, $gameVersion);
                    } catch (HttpException) {
                        $latest = null;
                    }
                }
            }

            $rows[] = [
                'filename' => $name,
                'size' => (int) ($file['size'] ?? 0),
                'modified' => $file['modified'] ?? null,
                'disabled' => str_ends_with(strtolower($name), '.disabled'),
                'tracked' => $record !== null,
                'project_id' => $record?->project_id,
                'title' => $record?->title,
                'version_number' => $record?->version_number,
                'icon_url' => $record?->icon_url,
                'update' => $latest !== null && $record !== null && $latest['id'] !== $record->version_id ? [
                    'id' => $latest['id'],
                    'version_number' => $latest['version_number'],
                ] : null,
            ];
        }

        usort($rows, fn (array $a, array $b): int => strcasecmp($a['title'] ?? $a['filename'], $b['title'] ?? $b['filename']));

        return new JsonResponse(['data' => $rows, 'directory_exists' => true, 'pending' => $pending, 'loader' => $loader]);
    }

    /**
     * Have Wings download a version's .jar from the Modrinth CDN into the server's
     * plugins or mods folder, plus its required dependencies when enabled.
     */
    public function install(
        Request $request,
        Server $server,
        string $kind,
        PullsFiles $puller,
        CreatesDirectories $directories,
        DeletesFiles $deleter,
    ): JsonResponse {
        $type = ContentKind::from($kind);
        abort_unless($request->user()->can('file.create', $server), 403, 'You do not have permission to create files on this server.');

        $platform = $this->files->platform($server);
        if ($platform !== null && $platform['kind'] !== $type->value) {
            throw new HttpException(409, 'This server runs '.ucfirst($platform['loader']).', which uses '.$platform['kind'].', not '.$type->value.'. Use the '.ucfirst($platform['kind']).' page instead.');
        }

        $data = $request->validate([
            'version_id' => ['required', 'string', 'regex:/^[A-Za-z0-9]{8}$/'],
            'loader' => ['required', 'string', 'in:'.implode(',', $type->loaders())],
            'game_version' => ['nullable', 'string', 'regex:'.self::VERSION_PATTERN],
            'replace' => ['nullable', 'string', 'regex:/^[A-Za-z0-9][A-Za-z0-9._+\-()\[\] ]{0,200}\.jar(\.disabled)?$/'],
        ]);

        $loaders = ContentKind::compatibleLoaders($data['loader']);
        if (ContentKind::isProxy($data['loader'])) {
            $data['game_version'] = null;
        }
        $existing = $this->existingFiles($server, $type, $directories);

        // A project can only be installed once. Installing another version of a
        // project that is already here replaces the old file instead.
        $requested = $this->modrinth->version($data['version_id']);
        $current = Install::query()
            ->where('server_id', $server->id)
            ->where('kind', $type->value)
            ->where('project_id', (string) ($requested['project_id'] ?? ''))
            ->first();

        if ($current !== null && in_array($current->filename, $existing, true)) {
            if ($current->version_id === $data['version_id']) {
                throw new HttpException(409, $current->title.' '.$current->version_number.' is already installed.');
            }

            $data['replace'] = $current->filename;
        }

        if (! empty($data['replace'])) {
            abort_unless($request->user()->can('file.delete', $server), 403, 'Changing the installed version needs permission to delete files on this server.');
        }

        $installed = [];
        $installed[] = $this->installVersion($server, $type, $puller, $data['version_id'], $loaders, $existing, $data['replace'] ?? null);

        if (! empty($data['replace']) && $data['replace'] !== $installed[0]['filename'] && in_array($data['replace'], $existing, true)) {
            // The record of the old file goes first: if deleting it fails, the new
            // version is still the one the panel tracks. The panel logs the delete.
            Install::query()->where('server_id', $server->id)->where('kind', $type->value)->where('filename', $data['replace'])->delete();
            try {
                $deleter->delete($server, $type->directory(), [$data['replace']]);
            } catch (DaemonConnectionException) {
                throw new HttpException(502, $installed[0]['title'].' '.$installed[0]['version_number'].' was installed, but the old file '.$data['replace'].' could not be deleted. Delete it in the file manager, or both versions will load.');
            }
        }

        $skipped = [];
        if ($this->setting('install_dependencies') ?? true) {
            $this->installDependencies($server, $type, $puller, $installed[0]['dependencies'], $loaders, $data['game_version'] ?? null, $existing, $installed, $skipped);
        }

        return new JsonResponse([
            'installed' => array_map(fn (array $item): array => array_diff_key($item, ['dependencies' => true]), $installed),
            'skipped_dependencies' => $skipped,
        ]);
    }

    public function uninstall(Request $request, Server $server, string $kind, DeletesFiles $deleter): JsonResponse
    {
        $type = ContentKind::from($kind);
        abort_unless($request->user()->can('file.delete', $server), 403, 'You do not have permission to delete files on this server.');

        $data = $request->validate([
            'filename' => ['required', 'string', 'regex:/^[A-Za-z0-9][A-Za-z0-9._+\-()\[\] ]{0,200}\.jar(\.disabled)?$/'],
        ]);

        // The panel's delete action records this in the activity log.
        $deleter->delete($server, $type->directory(), [$data['filename']]);
        Install::query()->where('server_id', $server->id)->where('kind', $type->value)->where('filename', $data['filename'])->delete();

        return new JsonResponse([], 204);
    }

    /**
     * @param  list<string>  $loaders
     * @param  list<string>  $existing  file names already in the folder, updated in place
     * @return array<string, mixed>
     */
    private function installVersion(Server $server, ContentKind $type, PullsFiles $puller, string $versionId, array $loaders, array &$existing, ?string $replacing = null): array
    {
        $version = $this->modrinth->version($versionId);

        $versionLoaders = array_values(array_filter((array) ($version['loaders'] ?? []), 'is_string'));
        if (array_intersect($versionLoaders, $loaders) === []) {
            throw new HttpException(422, 'This version is built for '.implode(', ', $versionLoaders).', not for this server ('.implode(', ', $loaders).').');
        }

        $jar = $this->modrinth->primaryJar($version);
        if ($jar === null) {
            throw new HttpException(422, 'This version has no .jar file to install.');
        }

        $parts = parse_url($jar['url']);
        if (($parts['scheme'] ?? null) !== 'https' || ! in_array($parts['host'] ?? '', ModrinthClient::DOWNLOAD_HOSTS, true)) {
            throw new HttpException(422, 'Refusing to download from an unexpected host.');
        }

        $filename = basename($jar['filename']);
        if (preg_match(self::JAR_PATTERN, $filename) !== 1) {
            $stem = trim((string) preg_replace('/[^A-Za-z0-9._+-]/', '_', pathinfo($filename, PATHINFO_FILENAME)), '._');
            $filename = ($stem !== '' ? $stem : (string) $version['id']).'.jar';
        }

        // Never overwrite a file that belongs to another project.
        if (in_array($filename, $existing, true) && $filename !== $replacing) {
            $owner = Install::query()->where('server_id', $server->id)->where('kind', $type->value)->where('filename', $filename)->first();
            if ($owner === null || $owner->project_id !== (string) $version['project_id']) {
                throw new HttpException(409, $filename.' already exists and is not this project. Rename or remove it first.');
            }
        }

        // The panel's pull action records this in the activity log.
        $puller->pull($server, $jar['url'], $type->directory(), [
            'filename' => $filename,
            'foreground' => true,
            'timeout' => 120,
        ]);

        $project = [];
        try {
            $project = $this->modrinth->project((string) $version['project_id']);
        } catch (HttpException) {
            // The file is installed; a missing title only affects how it is listed.
        }

        $icon = $project['icon_url'] ?? null;
        $icon = is_string($icon) && parse_url($icon, PHP_URL_HOST) === 'cdn.modrinth.com' ? $icon : null;

        Install::query()->updateOrCreate(
            ['server_id' => $server->id, 'kind' => $type->value, 'filename' => $filename],
            [
                'project_id' => (string) $version['project_id'],
                'version_id' => (string) $version['id'],
                'title' => mb_substr((string) ($project['title'] ?? $version['name'] ?? $filename), 0, 255),
                'version_number' => mb_substr((string) ($version['version_number'] ?? ''), 0, 128),
                'icon_url' => $icon,
                'sha1' => $jar['sha1'],
            ],
        );

        $existing[] = $filename;

        return [
            'filename' => $filename,
            'project_id' => (string) $version['project_id'],
            'title' => (string) ($project['title'] ?? $version['name'] ?? $filename),
            'version_number' => (string) ($version['version_number'] ?? ''),
            'dependencies' => $this->modrinth->summarizeVersion($version)['dependencies'],
        ];
    }

    /**
     * Install each required dependency once, skipping projects that are already
     * installed by this extension or whose file is already present.
     *
     * @param  list<array<string, mixed>>  $dependencies
     * @param  list<string>  $loaders
     * @param  list<string>  $existing
     * @param  list<array<string, mixed>>  $installed
     * @param  list<array<string, mixed>>  $skipped
     */
    private function installDependencies(
        Server $server,
        ContentKind $type,
        PullsFiles $puller,
        array $dependencies,
        array $loaders,
        ?string $gameVersion,
        array &$existing,
        array &$installed,
        array &$skipped,
    ): void {
        $tracked = Install::query()->where('server_id', $server->id)->where('kind', $type->value)->pluck('project_id')->all();

        foreach ($dependencies as $dependency) {
            if (($dependency['type'] ?? null) !== 'required' || count($installed) >= self::MAX_DEPENDENCIES) {
                continue;
            }

            $projectId = is_string($dependency['project_id'] ?? null) ? $dependency['project_id'] : null;
            if ($projectId !== null && in_array($projectId, $tracked, true)) {
                continue;
            }

            try {
                $versionId = is_string($dependency['version_id'] ?? null) ? $dependency['version_id'] : null;
                if ($versionId === null && $projectId !== null) {
                    $candidates = $this->modrinth->versions($projectId, $loaders, $gameVersion);
                    $releases = array_values(array_filter($candidates, fn (array $v): bool => $v['version_type'] === 'release' && $v['file'] !== null));
                    // Prefer a release; a project that only publishes betas still gets its newest beta.
                    $versionId = ($releases !== [] ? $releases[0]['id'] : ($candidates[0]['id'] ?? null));
                }

                if ($versionId === null) {
                    $skipped[] = ['project_id' => $projectId, 'reason' => 'No compatible version found.'];

                    continue;
                }

                $version = $this->modrinth->version($versionId);
                $jar = $this->modrinth->primaryJar($version);
                if ($jar !== null && in_array(basename($jar['filename']), $existing, true)) {
                    continue;
                }

                $result = $this->installVersion($server, $type, $puller, $versionId, $loaders, $existing);
                $installed[] = $result;
                $tracked[] = $result['project_id'];
            } catch (HttpException|DaemonConnectionException $exception) {
                $skipped[] = ['project_id' => $projectId, 'reason' => $exception->getMessage()];
            }
        }
    }

    /**
     * Projects this extension installed whose file is still in the folder, keyed by project ID.
     *
     * @return array<string, array{filename: string, version_id: string, version_number: string}>
     */
    private function installedByProject(Server $server, ContentKind $type): array
    {
        try {
            $jars = $this->jars($this->files->list($server, $type->directory()) ?? []);
        } catch (DaemonConnectionException) {
            return []; // Node unreachable: nothing to mark.
        }

        // Only files already recognised; hashing new ones is the Installed tab's job,
        // so a search never reads jars through Wings.
        $result = [];
        $records = Install::query()->where('server_id', $server->id)->where('kind', $type->value)->whereIn('filename', array_keys($jars))->get();
        foreach ($records as $record) {
            $result[$record->project_id] = [
                'filename' => $record->filename,
                'version_id' => $record->version_id,
                'version_number' => $record->version_number,
            ];
        }

        return $result;
    }

    /**
     * The .jar (and disabled .jar) files in a directory listing, keyed by name.
     *
     * @param  list<array<string, mixed>>  $files
     * @return array<string, array<string, mixed>>
     */
    private function jars(array $files): array
    {
        $jars = [];
        foreach ($files as $file) {
            $name = (string) ($file['name'] ?? '');
            $lower = strtolower($name);
            if ((bool) ($file['file'] ?? true) && (str_ends_with($lower, '.jar') || str_ends_with($lower, '.jar.disabled'))) {
                $jars[$name] = $file;
            }
        }

        return $jars;
    }

    /**
     * File names in the content folder, creating the folder when it does not exist yet.
     *
     * @return list<string>
     */
    private function existingFiles(Server $server, ContentKind $type, CreatesDirectories $directories): array
    {
        $files = $this->files->list($server, $type->directory());
        if ($files !== null) {
            return array_values(array_map(fn (array $file): string => (string) ($file['name'] ?? ''), $files));
        }

        $directories->create($server, ltrim($type->directory(), '/'), '/');
        $this->files->forget($server);

        return [];
    }

    /**
     * @return array{query?: string|null, loader: string, game_version?: string|null, sort?: string, page?: int}
     */
    private function validateSearch(Request $request, ContentKind $type): array
    {
        /** @var array{query?: string|null, loader: string, game_version?: string|null, sort?: string, page?: int} */
        return $request->validate([
            'query' => ['nullable', 'string', 'max:100'],
            'loader' => ['required', 'string', 'in:'.implode(',', $type->loaders())],
            'game_version' => ['nullable', 'string', 'regex:'.self::VERSION_PATTERN],
            'sort' => ['nullable', 'string', 'in:'.implode(',', self::SORTS)],
            'page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);
    }

    private function authorizeRead(Request $request, Server $server): void
    {
        abort_unless($request->user()->can('file.read', $server), 403, 'You do not have permission to read files on this server.');
    }

    private function setting(string $key): mixed
    {
        return $this->settings->get('mc-content')?->get($key);
    }
}
