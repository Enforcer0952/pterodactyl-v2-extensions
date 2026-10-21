<?php

namespace ValheimTools\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Pterodactyl\Models\Server;
use Symfony\Component\HttpKernel\Exception\HttpException;
use ValheimTools\Support\Valheim;
use ValheimTools\Support\WorldFiles;

/**
 * Worlds: list, switch, back up, restore and delete.
 *
 * Valheim keeps worlds in <save folder>/worlds_local: NAME.db + NAME.fwl before 1.0, a
 * NAME folder from 1.0 on. Backups made here are folders in /valheim-backups, one per
 * backup, holding the same files. Valheim's own automatic backups stay where the game
 * puts them and can be restored from.
 */
class Worlds
{
    public function __construct(
        private readonly ServerFiles $files,
        private readonly StartupVariables $variables,
        private readonly Preferences $preferences,
    ) {}

    public function worldsDirectory(Server $server): string
    {
        return Valheim::saveDirectory((string) $server->startup).'/worlds_local';
    }

    /** The world the server loads: the WORLD variable, else -world in the startup command. */
    public function activeName(Server $server): ?string
    {
        $value = $this->variables->get($server, 'WORLD');
        if ($value === null && preg_match('/(?:^|\s)-world\s+(?:"([^"{}$]*)"|([^\s"{}$]+))/', (string) $server->startup, $match) === 1) {
            $value = ($match[1] ?? '') !== '' ? $match[1] : ($match[2] ?? '');
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function overview(Server $server): array
    {
        $directory = $this->worldsDirectory($server);
        $groups = WorldFiles::group($this->files->entries($server, $directory));
        $active = $this->activeName($server);

        $worlds = [];
        foreach ($groups['worlds'] as $world) {
            $autos = array_filter($groups['backups'], fn (array $backup): bool => $backup['world'] === $world['name']);
            $worlds[] = $world + [
                'active' => $active !== null && $active === $world['name'],
                'automatic_backups' => count($autos),
            ];
        }
        foreach ($worlds as &$world) {
            if ($world['format'] === 'folder') {
                $world['size'] = $this->files->folderSize($server, $this->files->path($directory, $world['name']));
            }
        }
        unset($world);

        $automatic = [];
        foreach ($groups['backups'] as $backup) {
            if ($backup['format'] === 'folder') {
                $backup['size'] = $this->files->folderSize($server, $this->files->path($directory, $backup['id']));
            }
            $time = WorldFiles::stampTime($backup['stamp']);
            $automatic[] = $backup + ['time' => $time?->format(DATE_ATOM)];
        }

        $backups = [];
        foreach ($this->files->folders($server, Valheim::BACKUPS_DIR) as $folder) {
            $parsed = WorldFiles::parseBackupFolder($folder['name']);
            if ($parsed === null) {
                continue;
            }
            $backups[] = [
                'id' => $folder['name'],
                'world' => $parsed['world'],
                'kind' => $parsed['kind'],
                'size' => $this->files->folderSize($server, $this->files->path(Valheim::BACKUPS_DIR, $folder['name'])),
                'modified' => $folder['modified'],
            ];
        }
        usort($backups, fn (array $a, array $b): int => [(int) strtotime((string) $b['modified']), $b['id']] <=> [(int) strtotime((string) $a['modified']), $a['id']]);

        $names = array_column($groups['worlds'], 'name');

        return [
            'worlds' => $worlds,
            'backups' => $backups,
            'automatic_backups' => $automatic,
            'originals' => $groups['originals'],
            'active' => $active,
            'active_exists' => $active !== null && in_array($active, $names, true),
            'worlds_directory' => ltrim($directory, '/'),
            'backups_directory' => ltrim(Valheim::BACKUPS_DIR, '/'),
            'keep' => $this->preferences->keep(),
            'variable' => ['exists' => $this->variables->exists($server, 'WORLD'), 'editable' => $this->variables->editable($server, 'WORLD')],
        ];
    }

    /**
     * Make a world the one the server loads. A name that has no files yet is a new world,
     * which Valheim creates when it starts.
     */
    public function switch(Server $server, string $name, bool $create): void
    {
        $this->locked($server, function () use ($server, $name, $create): void {
            // The name ends up in the start command, so it must be valid whether the world exists or not.
            if (! Valheim::validWorldName($name)) {
                throw new HttpException(422, 'Use letters, numbers, spaces, dashes, underscores and dots in a world name, 20 characters at most.');
            }
            if ($this->find($server, $name) === null && ! $create) {
                throw new HttpException(404, 'There is no world called '.$name.' on this server.');
            }

            if (! $this->variables->exists($server, 'WORLD')) {
                throw new HttpException(422, 'This server has no WORLD startup variable, so the world is set in the startup command.');
            }

            // Valheim refuses to start when the password is part of the world name.
            $password = (string) $this->variables->get($server, 'PASSWORD');
            if ($password !== '' && mb_stripos($name, $password) !== false) {
                throw new HttpException(422, 'The server password is part of that world name, and Valheim will not start like that. Change the password first.');
            }

            $this->variables->setMany($server, ['WORLD' => $name]);
        });
    }

    /**
     * Copy a world into a new backup folder. Returns the folder's name.
     */
    public function backup(Server $server, string $name): string
    {
        return $this->locked($server, function () use ($server, $name): string {
            $world = $this->requireWorld($server, $name);
            $folder = $this->makeBackup($server, $world, '');
            $this->prune($server);

            return $folder;
        });
    }

    /**
     * Put a backup back as its world. The world it replaces is backed up first.
     *
     * @return array{world: string, safety: ?string}
     */
    public function restore(Server $server, string $id): array
    {
        return $this->locked($server, function () use ($server, $id): array {
            $parsed = WorldFiles::parseBackupFolder($id);
            $directory = $this->files->path(Valheim::BACKUPS_DIR, $id);
            if ($parsed === null || ! $this->files->directoryExists($server, $directory)) {
                throw new HttpException(404, 'That backup does not exist (any more).');
            }

            $world = $parsed['world'];
            $names = [];
            foreach ($this->files->entries($server, $directory) as $entry) {
                if (! Valheim::plainName($entry['name'])) {
                    continue;
                }
                $own = $entry['name'] === $world || $entry['name'] === $world.'.db' || $entry['name'] === $world.'.fwl';
                if (! $own) {
                    throw new HttpException(422, 'That backup has files that do not belong to '.$world.'. Restore it by hand with the file manager.');
                }
                $names[$entry['name']] = $entry['name'];
            }
            if ($names === []) {
                throw new HttpException(422, 'That backup is empty.');
            }

            return ['world' => $world, 'safety' => $this->putBack($server, $world, $directory, $names)];
        });
    }

    /**
     * Restore one of Valheim's own automatic backups as its world.
     *
     * @return array{world: string, safety: ?string}
     */
    public function restoreAutomatic(Server $server, string $id): array
    {
        return $this->locked($server, function () use ($server, $id): array {
            $groups = WorldFiles::group($this->files->entries($server, $this->worldsDirectory($server)));
            $backup = null;
            foreach ($groups['backups'] as $candidate) {
                if ($candidate['id'] === $id) {
                    $backup = $candidate;
                }
            }
            if ($backup === null) {
                throw new HttpException(404, 'That backup does not exist (any more).');
            }
            if (! Valheim::plainName($backup['world'])) {
                throw new HttpException(422, 'That backup belongs to a world with an unusual name. Restore it by hand with the file manager.');
            }

            // NAME_backup_auto-STAMP(.db|.fwl) becomes NAME(.db|.fwl).
            $names = [];
            foreach ($backup['entries'] as $entry) {
                $names[$entry] = $backup['world'].substr($entry, strlen($backup['id']));
            }

            return ['world' => $backup['world'], 'safety' => $this->putBack($server, $backup['world'], $this->worldsDirectory($server), $names)];
        });
    }

    public function deleteBackup(Server $server, string $id): void
    {
        $this->locked($server, function () use ($server, $id): void {
            if (WorldFiles::parseBackupFolder($id) === null || ! $this->files->directoryExists($server, $this->files->path(Valheim::BACKUPS_DIR, $id))) {
                throw new HttpException(404, 'That backup does not exist (any more).');
            }

            $this->files->delete($server, Valheim::BACKUPS_DIR, [$id]);
        });
    }

    /**
     * Delete a world. The world the server loads stays.
     *
     * @return ?string the backup taken first, if any
     */
    public function delete(Server $server, string $name, bool $backupFirst): ?string
    {
        return $this->locked($server, function () use ($server, $name, $backupFirst): ?string {
            $world = $this->requireWorld($server, $name);
            if ($this->activeName($server) === $world['name']) {
                throw new HttpException(409, 'This is the world the server loads. Switch to another world first.');
            }

            $backup = $backupFirst ? $this->makeBackup($server, $world, 'before-delete') : null;
            $this->files->delete($server, $this->worldsDirectory($server), $world['entries']);
            if ($backupFirst) {
                $this->prune($server);
            }

            return $backup;
        });
    }

    /**
     * @return array{name: string, format: string, entries: list<string>, complete: bool, size: int, modified: ?string}|null
     */
    private function find(Server $server, string $name): ?array
    {
        foreach (WorldFiles::group($this->files->entries($server, $this->worldsDirectory($server)))['worlds'] as $world) {
            if ($world['name'] === $name) {
                return $world;
            }
        }

        return null;
    }

    /**
     * @return array{name: string, format: string, entries: list<string>, complete: bool, size: int, modified: ?string}
     */
    private function requireWorld(Server $server, string $name): array
    {
        return $this->find($server, $name) ?? throw new HttpException(404, 'There is no world called '.$name.' on this server.');
    }

    /**
     * @param  array{name: string, entries: list<string>}  $world
     */
    private function makeBackup(Server $server, array $world, string $kind): string
    {
        $folder = WorldFiles::backupFolder($world['name'], CarbonImmutable::now('UTC'), $kind);
        if ($this->files->directoryExists($server, $this->files->path(Valheim::BACKUPS_DIR, $folder))) {
            throw new HttpException(409, 'A backup was just made. Wait a second and try again.');
        }

        try {
            $this->files->copyEntries($server, $this->worldsDirectory($server), $world['entries'], $this->files->path(Valheim::BACKUPS_DIR, $folder));
        } catch (\Throwable $exception) {
            // A half-made backup is worse than none.
            try {
                if ($this->files->directoryExists($server, $this->files->path(Valheim::BACKUPS_DIR, $folder))) {
                    $this->files->delete($server, Valheim::BACKUPS_DIR, [$folder]);
                }
            } catch (\Throwable) {
            }

            throw $exception;
        }

        return $folder;
    }

    /**
     * Replace a world with files from a folder. Order matters: the new files are copied
     * to a temporary folder first, the old world is backed up, and only then replaced, so
     * a failure never leaves the world gone.
     *
     * @param  array<string, string>  $names  name in the source folder => name in the worlds folder
     * @return ?string the backup of the world that was replaced
     */
    private function putBack(Server $server, string $world, string $from, array $names): ?string
    {
        $directory = $this->worldsDirectory($server);
        $temp = Valheim::TEMP_DIR.'/'.Str::lower(Str::random(12));

        $this->files->copyEntries($server, $from, array_keys($names), $temp);

        try {
            $current = $this->find($server, $world);
            $safety = null;
            if ($current !== null) {
                $safety = $this->makeBackup($server, $current, 'before-restore');
                $this->files->delete($server, $directory, $current['entries']);
            }
            $this->files->ensureDirectory($server, $directory);
            foreach ($names as $source => $target) {
                $this->files->move($server, $temp, $source, $directory, $target);
            }
        } catch (\Throwable $exception) {
            $this->cleanTemp($server, $temp);

            throw $exception;
        }

        $this->cleanTemp($server, $temp);
        $this->prune($server);

        return $safety;
    }

    private function cleanTemp(Server $server, string $temp): void
    {
        try {
            $this->files->delete($server, dirname($temp), [basename($temp)]);
        } catch (\Throwable) {
            // A leftover temporary folder is harmless.
        }
    }

    private function prune(Server $server): void
    {
        try {
            $folders = array_column($this->files->folders($server, Valheim::BACKUPS_DIR), 'name');
            // Backups of the world the server loads are never pruned automatically.
            $active = $this->activeName($server);
            $this->files->delete($server, Valheim::BACKUPS_DIR, WorldFiles::expired($folders, $this->preferences->keep(), $active === null ? [] : [$active]));
        } catch (\Throwable) {
            // An extra backup is harmless.
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
            return Cache::lock('ext:valheim-tools:worlds:'.$server->uuid, 900)->block(5, $run);
        } catch (LockTimeoutException) {
            throw new HttpException(409, 'Another world action is running. Try again in a moment.');
        }
    }
}
