<?php

namespace ValheimTools\Services;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Pterodactyl\Models\Server;
use Symfony\Component\HttpKernel\Exception\HttpException;
use ValheimTools\Support\DependencyPlan;
use ValheimTools\Support\PackageLayout;
use ValheimTools\Support\Thunderstore;
use ValheimTools\Support\Valheim;

/**
 * BepInEx mods from Thunderstore. Mods go to BepInEx/plugins/Owner-Name (and patchers,
 * monomod) as the mod managers lay them out. BepInEx itself (BepInEx/core, the loader
 * files, the BepInExPack_Valheim package) is never installed, changed or removed here.
 */
class Mods
{
    public const string ROOT = '/BepInEx';

    /** Folders under BepInEx whose contents are listed and managed. */
    public const array KINDS = ['plugins', 'patchers', 'monomod'];

    private const int MAX_PACKAGES = 25;

    private const int MAX_DEPTH = 6;

    private const int MANIFEST_MAX = 64 * 1024;

    public function __construct(
        private readonly ServerFiles $files,
        private readonly ThunderstoreClient $store,
    ) {}

    public function available(Server $server): bool
    {
        return $this->files->directoryExists($server, self::ROOT);
    }

    /**
     * What is installed: a folder per mod, or a loose file.
     *
     * @return list<array{id: string, folder: string, kind: string, file: bool, name: string, version: ?string, owner: ?string, package: ?string, description: ?string, website: ?string, size: int, modified: ?string}>
     */
    public function installed(Server $server): array
    {
        $mods = [];
        foreach (self::KINDS as $kind) {
            $directory = self::ROOT.'/'.$kind;
            foreach ($this->files->entries($server, $directory) as $entry) {
                $name = $entry['name'];
                if (! Valheim::plainName($name)) {
                    continue;
                }
                if ($entry['file'] && ! str_ends_with(strtolower($name), '.dll')) {
                    continue;
                }

                $reference = $entry['file'] ? null : Thunderstore::parseFolder($name);
                $manifest = null;
                if (! $entry['file'] && $this->files->exists($server, $directory.'/'.$name, 'manifest.json')) {
                    $contents = $this->files->read($server, $directory.'/'.$name, 'manifest.json', self::MANIFEST_MAX);
                    $manifest = $contents === null ? null : Thunderstore::manifest($contents);
                }

                $mods[] = [
                    'id' => $kind.'/'.$name,
                    'folder' => $name,
                    'kind' => $kind,
                    'file' => $entry['file'],
                    'name' => $manifest['name'] ?? ($reference['name'] ?? preg_replace('/\.dll$/i', '', $name)),
                    'version' => ($manifest['version'] ?? '') !== '' ? $manifest['version'] : null,
                    'owner' => $reference['namespace'] ?? null,
                    'package' => $reference !== null ? $name : null,
                    'description' => ($manifest['description'] ?? '') !== '' ? $manifest['description'] : null,
                    'website' => $manifest['website'] ?? null,
                    'size' => $entry['file'] ? $entry['size'] : 0,
                    'modified' => $entry['modified'],
                ];
            }
        }

        usort($mods, fn (array $a, array $b): int => [strtolower($a['name']), $a['kind']] <=> [strtolower($b['name']), $b['kind']]);

        return $mods;
    }

    /**
     * For installed mods that came from Thunderstore: the newest version there.
     *
     * @return array<string, array{latest: string, update: bool}> by package folder (Owner-Name)
     */
    public function updates(Server $server): array
    {
        $result = [];
        $checked = 0;
        foreach ($this->installed($server) as $mod) {
            if ($mod['package'] === null || $mod['version'] === null || isset($result[$mod['package']])) {
                continue;
            }
            if (++$checked > 30) {
                break;
            }
            $reference = Thunderstore::parseFolder($mod['package']);
            if ($reference === null || Thunderstore::isBepInEx($reference['namespace'], $reference['name'])) {
                continue;
            }
            try {
                $latest = $this->store->package($reference['namespace'], $reference['name'])['version'];
            } catch (HttpException) {
                // Not on Thunderstore (a private or renamed mod), or Thunderstore is slow: no update info.
                continue;
            }
            $result[$mod['package']] = ['latest' => $latest, 'update' => Thunderstore::compareVersions($latest, $mod['version']) > 0];
        }

        return $result;
    }

    /**
     * Install a mod and the mods it needs.
     *
     * @return array{installed: list<array{package: string, version: string, updated: bool}>, notes: list<string>}
     */
    public function install(Server $server, string $reference, ?string $version): array
    {
        $parsed = Thunderstore::parseReference($reference);
        if ($parsed === null) {
            throw new HttpException(422, 'Enter a mod as Owner-Name, or paste its Thunderstore link.');
        }
        if (Thunderstore::isBepInEx($parsed['namespace'], $parsed['name'])) {
            throw new HttpException(422, 'BepInEx is part of the server already. This page does not change it.');
        }
        if (! $this->available($server)) {
            throw new HttpException(409, 'This server has no BepInEx folder.');
        }

        return $this->locked($server, function () use ($server, $parsed, $version): array {
            $installed = [];
            foreach ($this->installed($server) as $mod) {
                if ($mod['package'] !== null && $mod['version'] !== null && ! $mod['file']) {
                    $installed[strtolower($mod['package'])] = $mod['version'];
                }
            }

            try {
                $resolved = DependencyPlan::build(
                    fn (string $namespace, string $name, ?string $version): array => $this->store->package($namespace, $name, $version),
                    $parsed['namespace'],
                    $parsed['name'],
                    $version ?? $parsed['version'],
                    $installed,
                    self::MAX_DEPTH,
                    self::MAX_PACKAGES,
                );
            } catch (\RuntimeException $exception) {
                if ($exception instanceof HttpException) {
                    throw $exception;
                }

                throw new HttpException(422, $exception->getMessage());
            }

            // Check every download first, so a package that is too big stops the install before anything changes.
            foreach ($resolved['plan'] as $package) {
                $this->assertDownloadSize($package);
            }

            $done = [];
            $notes = $resolved['notes'];
            foreach ($resolved['plan'] as $package) {
                $folder = Thunderstore::folderName($package['namespace'], $package['name']);
                $updated = isset($installed[strtolower($folder)]);
                $notes = array_merge($notes, $this->installOne($server, $package, $folder));
                $done[] = ['package' => $folder, 'version' => $package['version'], 'updated' => $updated];
            }

            return ['installed' => $done, 'notes' => array_values(array_unique($notes))];
        });
    }

    /** Remove an installed mod (the folder or file directly inside plugins, patchers or monomod). */
    public function remove(Server $server, string $kind, string $name): void
    {
        if (! in_array($kind, self::KINDS, true) || ! Valheim::plainName($name)) {
            throw new HttpException(422, 'That is not an installed mod.');
        }
        $reference = Thunderstore::parseFolder($name);
        if ($reference !== null && Thunderstore::isBepInEx($reference['namespace'], $reference['name'])) {
            throw new HttpException(422, 'BepInEx is part of the server. This page does not remove it.');
        }

        $this->locked($server, function () use ($server, $kind, $name): void {
            $directory = self::ROOT.'/'.$kind;
            if (! $this->files->exists($server, $directory, $name)) {
                throw new HttpException(404, 'That mod is not installed (any more).');
            }

            $this->files->delete($server, $directory, [$name]);
        });
    }

    /**
     * @param  array{namespace: string, name: string, version: string, url: string, size?: ?int}  $package
     */
    private function assertDownloadSize(array $package): void
    {
        $label = $package['namespace'].'-'.$package['name'];
        $size = $package['size'] ?? null;
        if ($size === null) {
            $size = $this->store->downloadSize(Thunderstore::downloadUrl($package['namespace'], $package['name'], $package['version']));
        }
        if (Thunderstore::tooLarge($size)) {
            throw new HttpException(422, $label.' is bigger than '.(Thunderstore::MAX_DOWNLOAD / 1024 / 1024).' MB. Install it with the file manager instead.');
        }
    }

    /**
     * @param  array{namespace: string, name: string, version: string, url: string, size?: ?int}  $package
     * @return list<string> notes for the person
     */
    private function installOne(Server $server, array $package, string $folder): array
    {
        $token = Str::lower(Str::random(12));
        $work = Valheim::TEMP_DIR.'/'.$token;
        $unpacked = $work.'/files';
        $zip = $folder.'.zip';
        $notes = [];
        $moved = [];

        try {
            $this->files->ensureDirectory($server, $unpacked);
            $this->files->pull($server, Thunderstore::downloadUrl($package['namespace'], $package['name'], $package['version']), $unpacked, $zip);
            if (! $this->files->exists($server, $unpacked, $zip)) {
                throw new HttpException(502, 'The download of '.$folder.' did not arrive.');
            }
            $downloaded = $this->files->entry($server, $unpacked, $zip);
            if ($downloaded !== null && Thunderstore::tooLarge($downloaded['size'])) {
                throw new HttpException(422, $folder.' is bigger than '.(Thunderstore::MAX_DOWNLOAD / 1024 / 1024).' MB. Install it with the file manager instead.');
            }
            $this->files->decompress($server, $unpacked, $zip);
            $this->files->delete($server, $unpacked, [$zip]);

            $entries = array_map(
                fn (array $entry): array => ['name' => $entry['name'], 'file' => $entry['file']],
                $this->files->entries($server, $unpacked),
            );
            $plan = PackageLayout::plan($entries, $folder);
            if ($plan['error'] !== null) {
                throw new HttpException(422, $folder.': '.$plan['error']);
            }
            foreach ($plan['skipped'] as $skipped) {
                if (strcasecmp((PackageLayout::segments($skipped) ?? [''])[0], 'core') === 0) {
                    $notes[] = $folder.' ships BepInEx core files. They were left out, the server keeps its own.';
                }
            }

            // An update replaces the old copy of the mod; its config files stay. The old copy is
            // renamed to "<folder>.old" first and only deleted once the new one is in place.
            foreach (self::KINDS as $kind) {
                $directory = self::ROOT.'/'.$kind;
                if ($this->files->exists($server, $directory, $folder)) {
                    if ($this->files->exists($server, $directory, $folder.'.old')) {
                        $this->files->delete($server, $directory, [$folder.'.old']);
                    }
                    $this->files->move($server, $directory, $folder, $directory, $folder.'.old');
                    $moved[] = $kind;
                }
            }

            foreach ($plan['moves'] as $move) {
                $this->apply($server, $unpacked, $move, $notes, $folder);
            }
        } catch (\Throwable $exception) {
            $this->rollback($server, $folder, $moved);
            $this->cleanup($server, $token);

            throw $exception;
        }

        foreach ($moved as $kind) {
            try {
                $this->files->delete($server, self::ROOT.'/'.$kind, [$folder.'.old']);
            } catch (\Throwable) {
                $notes[] = 'The old copy of '.$folder.' is still in '.$kind.' as '.$folder.'.old. Delete it with the file manager.';
            }
        }
        $this->cleanup($server, $token);

        return $notes;
    }

    /**
     * @param  array{from: string, to: string, dir: bool, merge: bool}  $move
     * @param  list<string>  $notes
     */
    private function apply(Server $server, string $unpacked, array $move, array &$notes, string $folder): void
    {
        $target = self::ROOT.'/'.$move['to'];

        if ($move['merge']) {
            // config/: keep the files the server already has, so settings are not overwritten.
            $from = $this->files->path($unpacked, $move['from']);
            foreach ($this->files->entries($server, $from) as $entry) {
                if (! Valheim::plainName($entry['name'])) {
                    continue;
                }
                if ($this->files->exists($server, $target, $entry['name'])) {
                    $notes[] = 'Kept the existing config '.$entry['name'].'.';

                    continue;
                }
                $this->files->ensureDirectory($server, $target);
                $this->files->move($server, $from, $entry['name'], $target, $entry['name']);
            }

            return;
        }

        $parent = dirname($target);
        if (str_starts_with($move['to'], 'config/') && $this->files->exists($server, $parent, basename($target))) {
            $notes[] = 'Kept the existing config '.basename($target).'.';

            return;
        }

        $this->files->ensureDirectory($server, $parent);
        $this->files->move($server, $unpacked, $move['from'], $parent, basename($target));
    }

    /**
     * Put the old copy of a mod back after a failed update: whatever the new copy left is
     * removed, then "<folder>.old" gets its name back.
     *
     * @param  list<string>  $kinds  the folders under BepInEx where an old copy was set aside
     */
    private function rollback(Server $server, string $folder, array $kinds): void
    {
        foreach ($kinds as $kind) {
            $directory = self::ROOT.'/'.$kind;
            try {
                if ($this->files->exists($server, $directory, $folder)) {
                    $this->files->delete($server, $directory, [$folder]);
                }
                $this->files->move($server, $directory, $folder.'.old', $directory, $folder);
            } catch (\Throwable) {
                // The old copy stays as "<folder>.old", so it is never lost.
            }
        }
    }

    private function cleanup(Server $server, string $token): void
    {
        try {
            $this->files->delete($server, Valheim::TEMP_DIR, [$token]);
        } catch (\Throwable) {
            // A leftover temporary folder is harmless.
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $run
     * @return T
     */
    private function locked(Server $server, callable $run): mixed
    {
        try {
            return Cache::lock('ext:valheim-tools:mods:'.$server->uuid, 900)->block(5, $run);
        } catch (LockTimeoutException) {
            throw new HttpException(409, 'Another mod action is running. Try again in a moment.');
        }
    }
}
