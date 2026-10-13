<?php

namespace McContent\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use McContent\Support\ContentKind;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A thin, cached wrapper around the public Modrinth API (https://docs.modrinth.com).
 * The Panel calls Modrinth on the user's behalf so the browser never talks to it
 * directly, and Wings downloads the selected file itself.
 */
class ModrinthClient
{
    private const string BASE_URL = 'https://api.modrinth.com/v2';

    /** Hosts Wings is allowed to download from. Modrinth serves every file from its CDN. */
    public const array DOWNLOAD_HOSTS = ['cdn.modrinth.com'];

    /**
     * @param  list<string>  $loaders  any of these loaders matches
     * @return array{hits: list<array<string, mixed>>, total: int, offset: int, limit: int}
     */
    public function search(ContentKind $kind, string $query, array $loaders, ?string $gameVersion, string $sort, int $offset, int $limit): array
    {
        $facets = [
            array_map(fn (string $loader): string => 'categories:'.$loader, $loaders),
        ];

        if ($gameVersion !== null) {
            $facets[] = ['versions:'.$gameVersion];
        }

        if ($kind === ContentKind::Mods) {
            // Leave out mods that only run on the client.
            $facets[] = ['server_side:required', 'server_side:optional'];
        }

        $params = [
            'query' => $query,
            'facets' => json_encode($facets, JSON_THROW_ON_ERROR),
            'index' => $sort,
            'offset' => $offset,
            'limit' => $limit,
        ];

        $data = $this->cached('search', $params, 300, fn () => $this->request()->get('/search', $params)->throw()->json());

        return [
            'hits' => array_values(array_map(fn (array $hit): array => [
                'project_id' => (string) ($hit['project_id'] ?? ''),
                'slug' => (string) ($hit['slug'] ?? ''),
                'title' => (string) ($hit['title'] ?? ''),
                'description' => (string) ($hit['description'] ?? ''),
                'author' => (string) ($hit['author'] ?? ''),
                'downloads' => (int) ($hit['downloads'] ?? 0),
                'icon_url' => $this->safeImage($hit['icon_url'] ?? null),
                'categories' => array_values(array_filter((array) ($hit['display_categories'] ?? []), 'is_string')),
                'date_modified' => $hit['date_modified'] ?? null,
            ], array_filter((array) ($data['hits'] ?? []), 'is_array'))),
            'total' => (int) ($data['total_hits'] ?? 0),
            'offset' => (int) ($data['offset'] ?? $offset),
            'limit' => (int) ($data['limit'] ?? $limit),
        ];
    }

    /**
     * Versions of a project that run on one of the given loaders and, if set, the given game version.
     *
     * @param  list<string>  $loaders
     * @return list<array<string, mixed>>
     */
    public function versions(string $project, array $loaders, ?string $gameVersion, bool $withChangelog = false): array
    {
        $params = ['loaders' => json_encode($loaders, JSON_THROW_ON_ERROR)];
        if ($gameVersion !== null) {
            $params['game_versions'] = json_encode([$gameVersion], JSON_THROW_ON_ERROR);
        }

        $data = $this->cached('versions:'.$project, $params, 300, fn () => $this->request()
            ->get('/project/'.rawurlencode($project).'/version', $params)->throw()->json());

        return array_values(array_map(
            fn (array $version): array => $this->summarizeVersion($version, $withChangelog),
            array_filter((array) $data, 'is_array'),
        ));
    }

    /**
     * Everything the project popup shows, trimmed to what is safe and useful to pass on.
     *
     * @return array<string, mixed>
     */
    public function projectDetails(string $id): array
    {
        $project = $this->project($id);
        $links = [];
        foreach (['source_url' => 'Source', 'wiki_url' => 'Wiki', 'issues_url' => 'Issues', 'discord_url' => 'Discord'] as $key => $label) {
            if (self::isWebUrl($project[$key] ?? null)) {
                $links[] = ['label' => $label, 'url' => $project[$key]];
            }
        }
        foreach (array_slice((array) ($project['donation_urls'] ?? []), 0, 5) as $donation) {
            if (is_array($donation) && self::isWebUrl($donation['url'] ?? null)) {
                $links[] = ['label' => 'Donate ('.mb_substr((string) ($donation['platform'] ?? 'link'), 0, 30).')', 'url' => $donation['url']];
            }
        }

        $gallery = [];
        foreach (array_slice((array) ($project['gallery'] ?? []), 0, 30) as $image) {
            if (is_array($image) && self::isWebUrl($image['url'] ?? null)) {
                $gallery[] = [
                    'url' => $image['url'],
                    'raw_url' => self::isWebUrl($image['raw_url'] ?? null) ? $image['raw_url'] : $image['url'],
                    'title' => is_string($image['title'] ?? null) ? mb_substr($image['title'], 0, 200) : null,
                    'description' => is_string($image['description'] ?? null) ? mb_substr($image['description'], 0, 500) : null,
                    'featured' => (bool) ($image['featured'] ?? false),
                ];
            }
        }
        usort($gallery, fn (array $a, array $b): int => (int) $b['featured'] <=> (int) $a['featured']);

        $license = is_array($project['license'] ?? null) ? $project['license'] : [];

        return [
            'id' => (string) ($project['id'] ?? $id),
            'slug' => (string) ($project['slug'] ?? $id),
            'project_type' => (string) ($project['project_type'] ?? ''),
            'title' => (string) ($project['title'] ?? ''),
            'description' => (string) ($project['description'] ?? ''),
            // Markdown; the browser sanitises it before showing it.
            'body' => mb_substr((string) ($project['body'] ?? ''), 0, 200_000),
            'icon_url' => self::isWebUrl($project['icon_url'] ?? null) ? $project['icon_url'] : null,
            'downloads' => (int) ($project['downloads'] ?? 0),
            'followers' => (int) ($project['followers'] ?? 0),
            'categories' => array_values(array_filter((array) ($project['categories'] ?? []), 'is_string')),
            'loaders' => array_values(array_filter((array) ($project['loaders'] ?? []), 'is_string')),
            'client_side' => (string) ($project['client_side'] ?? 'unknown'),
            'server_side' => (string) ($project['server_side'] ?? 'unknown'),
            'published' => $project['published'] ?? null,
            'updated' => $project['updated'] ?? null,
            'license' => ['id' => (string) ($license['id'] ?? ''), 'name' => (string) ($license['name'] ?? ''), 'url' => self::isWebUrl($license['url'] ?? null) ? $license['url'] : null],
            'links' => $links,
            'gallery' => $gallery,
        ];
    }

    /**
     * Projects a set of project ids are, for naming dependencies.
     *
     * @param  list<string>  $ids
     * @return array<string, array{title: string, slug: string, icon_url: ?string}>
     */
    public function projectNames(array $ids): array
    {
        $result = [];
        foreach ($this->projects($ids) as $id => $project) {
            $result[$id] = [
                'title' => (string) ($project['title'] ?? $id),
                'slug' => (string) ($project['slug'] ?? $id),
                'icon_url' => self::isWebUrl($project['icon_url'] ?? null) ? $project['icon_url'] : null,
            ];
        }

        return $result;
    }

    private static function isWebUrl(mixed $url): bool
    {
        return is_string($url) && strlen($url) < 2048 && preg_match('#^https?://[^\s]+$#i', $url) === 1;
    }

    /**
     * @return array<string, mixed>
     */
    public function version(string $id): array
    {
        $data = $this->cached('version:'.$id, [], 600, fn () => $this->request()
            ->get('/version/'.rawurlencode($id))->throw()->json());

        return (array) $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function project(string $id): array
    {
        $data = $this->cached('project:'.$id, [], 600, fn () => $this->request()
            ->get('/project/'.rawurlencode($id))->throw()->json());

        return (array) $data;
    }

    /**
     * Find the newest version for each of the given SHA-1 file hashes.
     *
     * @param  list<string>  $hashes
     * @param  list<string>  $loaders
     * @return array<string, array<string, mixed>> keyed by the hash that was asked about
     */
    public function latestForHashes(array $hashes, array $loaders, ?string $gameVersion): array
    {
        if ($hashes === []) {
            return [];
        }

        $body = ['hashes' => array_values($hashes), 'algorithm' => 'sha1', 'loaders' => $loaders];
        if ($gameVersion !== null) {
            $body['game_versions'] = [$gameVersion];
        }

        $data = $this->cached('updates', $body, 300, fn () => $this->request()
            ->post('/version_files/update', $body)->throw()->json());

        $result = [];
        foreach ((array) $data as $hash => $version) {
            if (is_string($hash) && is_array($version)) {
                $result[$hash] = $this->summarizeVersion($version);
            }
        }

        return $result;
    }

    /**
     * The Modrinth version each SHA-1 file hash belongs to, for files that are on Modrinth.
     *
     * @param  list<string>  $hashes
     * @return array<string, array<string, mixed>> raw versions keyed by hash
     */
    public function versionsForHashes(array $hashes): array
    {
        if ($hashes === []) {
            return [];
        }

        $body = ['hashes' => array_values($hashes), 'algorithm' => 'sha1'];
        $data = $this->cached('hashes', $body, 3600, fn () => $this->request()->post('/version_files', $body)->throw()->json());

        return array_filter((array) $data, fn ($version, $hash): bool => is_string($hash) && is_array($version), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Several versions in one request, keyed by version ID.
     *
     * @param  list<string>  $ids
     * @return array<string, array<string, mixed>>
     */
    public function versionsByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $params = ['ids' => json_encode(array_values(array_unique($ids)), JSON_THROW_ON_ERROR)];
        $data = $this->cached('versions-by-id', $params, 3600, fn () => $this->request()->get('/versions', $params)->throw()->json());

        $result = [];
        foreach ((array) $data as $version) {
            if (is_array($version) && is_string($version['id'] ?? null)) {
                $result[$version['id']] = $version;
            }
        }

        return $result;
    }

    /**
     * Several projects in one request, keyed by project ID.
     *
     * @param  list<string>  $ids
     * @return array<string, array<string, mixed>>
     */
    public function projects(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $params = ['ids' => json_encode(array_values(array_unique($ids)), JSON_THROW_ON_ERROR)];
        $data = $this->cached('projects', $params, 3600, fn () => $this->request()->get('/projects', $params)->throw()->json());

        $result = [];
        foreach ((array) $data as $project) {
            if (is_array($project) && is_string($project['id'] ?? null)) {
                $result[$project['id']] = $project;
            }
        }

        return $result;
    }

    /**
     * Release game versions, newest first.
     *
     * @return list<string>
     */
    public function gameVersions(): array
    {
        $data = $this->cached('game_versions', [], 3600, fn () => $this->request()->get('/tag/game_version')->throw()->json());

        return array_values(array_map(
            fn (array $row): string => (string) $row['version'],
            array_filter((array) $data, fn ($row): bool => is_array($row) && ($row['version_type'] ?? null) === 'release' && is_string($row['version'] ?? null)),
        ));
    }

    /**
     * The primary downloadable .jar of a version, or null if it has none.
     *
     * @param  array<string, mixed>  $version
     * @return array{url: string, filename: string, sha1: ?string, size: int}|null
     */
    public function primaryJar(array $version): ?array
    {
        $files = array_values(array_filter((array) ($version['files'] ?? []), fn ($file): bool => is_array($file)
            && is_string($file['url'] ?? null)
            && is_string($file['filename'] ?? null)
            && str_ends_with(strtolower($file['filename']), '.jar')));

        if ($files === []) {
            return null;
        }

        $primary = array_values(array_filter($files, fn (array $file): bool => ($file['primary'] ?? false) === true))[0] ?? $files[0];

        return [
            'url' => $primary['url'],
            'filename' => $primary['filename'],
            'sha1' => is_string($primary['hashes']['sha1'] ?? null) ? $primary['hashes']['sha1'] : null,
            'size' => (int) ($primary['size'] ?? 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $version
     * @return array<string, mixed>
     */
    public function summarizeVersion(array $version, bool $withChangelog = false): array
    {
        $jar = $this->primaryJar($version);

        $summary = [
            'id' => (string) ($version['id'] ?? ''),
            'project_id' => (string) ($version['project_id'] ?? ''),
            'name' => (string) ($version['name'] ?? ''),
            'version_number' => (string) ($version['version_number'] ?? ''),
            'version_type' => (string) ($version['version_type'] ?? 'release'),
            'game_versions' => array_values(array_filter((array) ($version['game_versions'] ?? []), 'is_string')),
            'loaders' => array_values(array_filter((array) ($version['loaders'] ?? []), 'is_string')),
            'date_published' => $version['date_published'] ?? null,
            'downloads' => (int) ($version['downloads'] ?? 0),
            'file' => $jar === null ? null : ['filename' => $jar['filename'], 'size' => $jar['size']],
            'dependencies' => array_values(array_map(fn (array $dep): array => [
                'project_id' => $dep['project_id'] ?? null,
                'version_id' => $dep['version_id'] ?? null,
                'type' => $dep['dependency_type'] ?? 'optional',
            ], array_filter((array) ($version['dependencies'] ?? []), 'is_array'))),
        ];

        if ($withChangelog) {
            $summary['changelog'] = mb_substr((string) ($version['changelog'] ?? ''), 0, 20_000);
        }

        return $summary;
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->withUserAgent('pterodactyl-mc-content/1.0 (Pterodactyl Panel extension)')
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(15)
            ->retry(2, 250, fn (\Throwable $e): bool => $e instanceof \Illuminate\Http\Client\ConnectionException
                || ($e instanceof RequestException && $e->response->serverError()), throw: false);
    }

    /**
     * @param  array<array-key, mixed>  $params
     */
    private function cached(string $key, array $params, int $ttl, \Closure $callback): mixed
    {
        $cacheKey = 'ext:mc-content:modrinth:'.$key.':'.sha1(json_encode($params, JSON_THROW_ON_ERROR));

        try {
            return Cache::remember($cacheKey, $ttl, $callback);
        } catch (RequestException $exception) {
            $status = $exception->response->status();
            if ($status === 404) {
                throw new HttpException(404, 'That project or version does not exist on Modrinth.');
            }

            throw new HttpException(502, 'Modrinth returned an error ('.$status.'). Try again in a moment.', $exception);
        } catch (\Illuminate\Http\Client\ConnectionException $exception) {
            throw new HttpException(504, 'Could not reach Modrinth from the Panel.', $exception);
        }
    }

    private function safeImage(mixed $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);

        return parse_url($url, PHP_URL_SCHEME) === 'https' && $host === 'cdn.modrinth.com' ? $url : null;
    }
}
