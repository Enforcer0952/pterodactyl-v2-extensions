<?php

namespace McWorld\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use McWorld\Support\Names;
use McWorld\Support\NodeErrors;
use McWorld\Support\Properties;
use McWorld\Support\ResetPlan;
use McWorld\Support\WorldLayout;
use Pterodactyl\Contracts\Servers\ReadsServerState;
use Pterodactyl\Contracts\Servers\SendsServerPower;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Models\Server;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Worlds, world backups and resets. Every change that deletes or replaces a world needs
 * the server stopped, and only one such action runs per server at a time.
 */
class Worlds
{
    public const string EXTENSION = 'mc-world';

    public const int DEFAULT_KEEP = 5;

    /** Longer than a compress plus a decompress, which Wings may each take 15 minutes for. */
    public const int LOCK_SECONDS = 2700;

    /** How long a "does this folder hold a level.dat" answer is reused. */
    private const int PROBE_SECONDS = 5;

    public function __construct(
        private readonly ServerFiles $files,
        private readonly PropertiesFile $properties,
        private readonly ReadsServerState $state,
        private readonly SendsServerPower $power,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function overview(Server $server): array
    {
        $file = $this->properties->read($server);
        $parsed = Properties::parse($file['raw']);
        $active = $file['exists'] ? ($parsed->get('level-name') ?? 'world') : 'world';

        return [
            'state' => $this->serverState($server),
            'properties_exists' => $file['exists'],
            'active' => $active,
            'active_valid' => Names::world($active),
            'hardcore' => $parsed->get('hardcore') === 'true',
            'worlds' => $this->worlds($server, $active),
            'backups' => $this->backups($server),
            'keep' => $this->keep(),
        ];
    }

    public function serverState(Server $server): string
    {
        try {
            \Illuminate\Support\Facades\Cache::forget('resources:'.$server->uuid); // the panel caches power state for 20 s
            return $this->state->read($server)->state;
        } catch (DaemonConnectionException) {
            throw new HttpException(502, 'Could not reach this server\'s node.');
        }
    }

    public function keep(): int
    {
        $value = (int) app('ext.mc-world.settings')->get('backups_to_keep', self::DEFAULT_KEEP);

        return max(1, min(50, $value));
    }

    /**
     * Create a backup of a world and its nether and end folders. Returns the backup's file name.
     */
    public function backup(Server $server, string $world): string
    {
        return $this->exclusive($server, fn (): string => $this->makeBackup($server, $this->findWorld($server, $world)));
    }

    /**
     * Replace a world with the one from a backup. The current folders are kept next to it
     * as "<name>.old-<date>".
     *
     * @return array{world: string, kept: list<string>}
     */
    public function restore(Server $server, string $file): array
    {
        $backup = Names::parseBackup($file);
        if ($backup === null) {
            throw new HttpException(422, 'That is not a backup made by this page.');
        }

        return $this->exclusive($server, function () use ($server, $file, $backup): array {
            $this->requireStopped($server);
            $dir = Names::BACKUP_DIR;
            if (ServerFiles::find($this->files->entries($server, '/'.$dir), $file, true) === null) {
                throw new HttpException(404, 'That backup no longer exists.');
            }

            $world = $backup['world'];
            $stamp = Names::stamp(CarbonImmutable::now());
            // Strict: if the root cannot be listed, nothing is moved.
            $root = WorldLayout::folders($this->files->strictEntries($server));
            $moves = [];
            foreach ([$world, Names::nether($world), Names::end($world)] as $folder) {
                if (isset($root[$folder])) {
                    if (isset($root[Names::old($folder, $stamp)])) {
                        throw new HttpException(409, 'A restore ran a second ago. Try again in a moment.');
                    }
                    $moves[$folder] = Names::old($folder, $stamp);
                }
            }

            $temporary = 'mcw-restore-'.$stamp.'.tar.gz';
            $moved = [];
            $parked = false;
            $unpacked = false;
            // The listing above may be old by now, so ask the node again just before the first rename.
            $this->requireStopped($server);
            try {
                foreach ($moves as $from => $to) {
                    $this->files->rename($server, '/', $from, $to);
                    $moved[$from] = $to;
                }
                // Wings unpacks into the folder the archive is in, so it is moved to the root for a moment.
                $this->files->rename($server, '/', $dir.'/'.$file, $temporary);
                $parked = true;
                $this->files->decompress($server, '/', $temporary);
                $unpacked = true;
            } catch (Throwable $exception) {
                if ($parked && ! $unpacked && $exception instanceof DaemonConnectionException
                    && NodeErrors::mayStillRun($exception->getStatusCode(), $exception->getPrevious() instanceof ConnectionException)) {
                    // Wings keeps unpacking after the panel gives up, so nothing is undone here.
                    throw new HttpException(502, $moved === []
                        ? 'The restore may still be running on the node. Check the files before starting the server. The backup is at '.$temporary.' until you move it back.'
                        : 'The restore may still be running on the node. Your old world is kept as '.array_values($moved)[0].'; check the files before starting the server. The backup is at '.$temporary.' until you move it back.');
                }

                $this->undoRestore($server, $moved, $parked ? [$temporary, $dir.'/'.$file] : null);

                throw $exception instanceof HttpException || $exception instanceof DaemonConnectionException
                    ? $exception
                    : new HttpException(500, 'The backup could not be restored. The old world was put back.');
            }

            try {
                $this->files->rename($server, '/', $temporary, $dir.'/'.$file);
            } catch (Throwable) {
                // The world is restored; the archive can be moved back by hand.
            }

            Activity::event('server:world.restore')->subject($server)->property(['backup' => $file, 'kept' => array_values($moved)])->log();

            return ['world' => $world, 'kept' => array_values($moved)];
        });
    }

    public function deleteBackup(Server $server, string $file): void
    {
        if (Names::parseBackup($file) === null) {
            throw new HttpException(422, 'That is not a backup made by this page.');
        }

        $this->exclusive($server, function () use ($server, $file): void {
            if (ServerFiles::find($this->files->entries($server, '/'.Names::BACKUP_DIR), $file, true) === null) {
                throw new HttpException(404, 'That backup no longer exists.');
            }
            $this->files->delete($server, '/'.Names::BACKUP_DIR, [$file]);
        });
    }

    /**
     * Delete the active world and its nether and end folders and make the server create a
     * new one. The plan (back up, delete, set the seed, start) is built by ResetPlan.
     *
     * @return list<string> the folders that were deleted
     */
    public function reset(Server $server, string $world, ?string $seed, bool $backup, bool $start): array
    {
        try {
            $seed = Names::seed($seed);
        } catch (InvalidArgumentException $exception) {
            throw new HttpException(422, $exception->getMessage());
        }

        return $this->exclusive($server, function () use ($server, $world, $seed, $backup, $start): array {
            $this->requireStopped($server);
            $file = $this->properties->read($server);
            if (! $file['exists']) {
                throw new HttpException(404, 'server.properties does not exist yet. Start the server once so it is created.');
            }
            $parsed = Properties::parse($file['raw']);
            $active = $parsed->get('level-name') ?? 'world';
            if ($active !== $world) {
                throw new HttpException(409, 'The active world changed. Reload the page and try again.');
            }
            if (! $backup && $parsed->get('hardcore') === 'true') {
                throw new HttpException(422, 'A hardcore world is always backed up before it is reset.');
            }
            $oldSeed = $parsed->get('level-seed');
            $seedChanged = false;

            $found = $this->findWorld($server, $world);
            try {
                $steps = ResetPlan::build($world, $found['folders'], $seed, $backup, $start, Names::stamp(CarbonImmutable::now()));
            } catch (InvalidArgumentException $exception) {
                throw new HttpException(422, $exception->getMessage());
            }

            foreach ($steps as $step) {
                switch ($step['type']) {
                    case 'backup':
                        $this->makeBackup($server, $found);
                        break;
                    case 'seed':
                        // Saved before anything is deleted: if this fails, the world is untouched.
                        try {
                            $this->properties->change($server, ['level-seed' => $step['value']], null);
                            $seedChanged = true;
                        } catch (HttpException $exception) {
                            throw new HttpException($exception->getStatusCode(), 'The seed could not be saved, so nothing was deleted: '.$exception->getMessage());
                        }
                        break;
                    case 'delete':
                        try {
                            // A backup can take minutes: look at the server again before deleting.
                            $this->requireStopped($server);
                            $this->files->delete($server, '/', $step['folders']);
                        } catch (Throwable $exception) {
                            if ($seedChanged) {
                                try {
                                    $this->properties->change($server, ['level-seed' => $oldSeed], null);
                                } catch (Throwable) {
                                    // The old seed can be set again on the Properties card.
                                }
                            }

                            throw $exception;
                        }
                        break;
                    case 'start':
                        $this->power->send($server, 'start');
                        break;
                }
            }

            Activity::event('server:world.reset')->subject($server)->property([
                'world' => $world,
                'deleted' => $found['folders'],
                'backup' => $backup,
                'seed' => $seed === null ? 'random' : 'custom',
                'start' => $start,
            ])->log();

            return $found['folders'];
        });
    }

    /** Make another existing world the active one. */
    public function switch(Server $server, string $world): void
    {
        $this->exclusive($server, function () use ($server, $world): void {
            $this->findWorld($server, $world);
            $this->properties->change($server, ['level-name' => $world], null);
            Activity::event('server:world.switch')->subject($server)->property('world', $world)->log();
        });
    }

    /**
     * Delete a world that is not the active one, with its nether and end folders. A backup
     * is made first unless $backup is false.
     *
     * @return list<string>
     */
    public function deleteWorld(Server $server, string $world, bool $backup = true): array
    {
        return $this->exclusive($server, function () use ($server, $world, $backup): array {
            $this->requireStopped($server);
            $file = $this->properties->read($server);
            $active = $file['exists'] ? (Properties::parse($file['raw'])->get('level-name') ?? 'world') : 'world';
            if ($active === $world) {
                throw new HttpException(409, 'This is the active world. Switch to another world first, or use Reset.');
            }
            $found = $this->findWorld($server, $world);
            if ($backup) {
                $this->makeBackup($server, $found);
                // The backup can take minutes: look at the server again before deleting.
                $this->requireStopped($server);
            }
            $this->files->delete($server, '/', $found['folders']);
            Activity::event('server:world.delete')->subject($server)->property(['world' => $world, 'deleted' => $found['folders'], 'backup' => $backup])->log();

            return $found['folders'];
        });
    }

    /**
     * @return list<array{name: string, active: bool, modified: ?string, folders: list<string>, layout: string}>
     */
    private function worlds(Server $server, string $active): array
    {
        $root = $this->files->strictEntries($server);
        $levelDats = [];
        // One listing per candidate folder is a node call each, so answers are reused for a few
        // seconds. Every world action bumps the version, which drops them.
        $version = (string) Cache::get($this->versionKey($server), '0');
        foreach (WorldLayout::candidates($root, $active) as $folder) {
            $probe = Cache::remember(
                'ext:mc-world:level:'.$server->uuid.':'.$version.':'.md5($folder),
                self::PROBE_SECONDS,
                function () use ($server, $folder): array {
                    $level = ServerFiles::find($this->files->entries($server, '/'.$folder), 'level.dat', true);

                    return ['found' => $level !== null, 'modified' => isset($level['modified']) ? (string) $level['modified'] : null];
                },
            );
            if ($probe['found']) {
                $levelDats[$folder] = $probe['modified'];
            }
        }

        return WorldLayout::detect($root, $levelDats, $active);
    }

    /**
     * @return array{name: string, active: bool, modified: ?string, folders: list<string>, layout: string}
     */
    private function findWorld(Server $server, string $world): array
    {
        if (! Names::world($world)) {
            throw new HttpException(422, 'That is not a valid world name.');
        }
        $root = $this->files->strictEntries($server);
        if (isset(WorldLayout::folders($root)[$world])) {
            $level = ServerFiles::find($this->files->strictEntries($server, '/'.$world), 'level.dat', true);
            if ($level !== null) {
                $found = WorldLayout::detect($root, [$world => isset($level['modified']) ? (string) $level['modified'] : null], '');

                if ($found !== []) {
                    return $found[0];
                }
            }
        }

        throw new HttpException(404, 'There is no world folder named '.$world.'.');
    }

    /**
     * @param  array{name: string, folders: list<string>}  $world
     */
    private function makeBackup(Server $server, array $world): string
    {
        $dir = Names::BACKUP_DIR;
        if (ServerFiles::find($this->files->strictEntries($server), $dir, false) === null) {
            $this->files->createDirectory($server, $dir);
        }

        $file = Names::backupFile($world['name'], Names::stamp(CarbonImmutable::now()));
        $archive = $this->files->compress($server, $world['folders']);
        if ($archive === '' || str_contains($archive, '/') || str_contains($archive, '..')) {
            throw new HttpException(502, 'The node did not say where it put the archive.');
        }

        try {
            $this->files->rename($server, '/', $archive, $dir.'/'.$file);
        } catch (Throwable $exception) {
            try {
                $this->files->delete($server, '/', [$archive]);
            } catch (Throwable) {
                // The loose archive can be removed in the file manager.
            }

            throw $exception;
        }

        $this->prune($server, $world['name']);

        return $file;
    }

    /** Keep the newest backups of one world; older ones are removed best effort. */
    private function prune(Server $server, string $world): void
    {
        try {
            $mine = array_values(array_filter(
                $this->backups($server),
                fn (array $backup): bool => $backup['world'] === $world,
            ));
            $old = array_slice(array_column($mine, 'name'), $this->keep());
            if ($old !== []) {
                $this->files->delete($server, '/'.Names::BACKUP_DIR, $old);
            }
        } catch (Throwable) {
            // An extra backup is harmless.
        }
    }

    /**
     * @return list<array{name: string, world: string, stamp: string, size: ?int, modified: ?string}>
     */
    private function backups(Server $server): array
    {
        $backups = [];
        foreach ($this->files->entries($server, '/'.Names::BACKUP_DIR) as $entry) {
            $parsed = Names::parseBackup((string) ($entry['name'] ?? ''));
            if ($parsed === null || ($entry['file'] ?? true) === false) {
                continue;
            }
            $backups[] = [
                'name' => (string) $entry['name'],
                'world' => $parsed['world'],
                'stamp' => $parsed['stamp'],
                'size' => isset($entry['size']) ? (int) $entry['size'] : null,
                'modified' => isset($entry['modified']) ? (string) $entry['modified'] : null,
            ];
        }
        usort($backups, fn (array $a, array $b): int => strcmp($b['stamp'], $a['stamp']) ?: strcmp($b['name'], $a['name']));

        return $backups;
    }

    /**
     * Put the moved folders back after a failed restore: whatever the unpacking left
     * behind is removed first, and the archive goes back to the backup folder.
     *
     * @param  array<string, string>  $moved  original name => name it was given
     * @param  array{0: string, 1: string}|null  $archive  where the archive is now, and where it belongs
     */
    private function undoRestore(Server $server, array $moved, ?array $archive): void
    {
        try {
            if ($archive !== null) {
                $this->files->rename($server, '/', $archive[0], $archive[1]);
            }
            // Strict: if the root cannot be listed, nothing is deleted.
            $root = WorldLayout::folders($this->files->strictEntries($server));
            $partial = array_values(array_filter(array_keys($moved), fn (string $name): bool => isset($root[$name])));
            if ($partial !== []) {
                $this->files->delete($server, '/', $partial);
            }
            foreach ($moved as $from => $to) {
                $this->files->rename($server, '/', $to, $from);
            }
        } catch (Throwable) {
            // What is left is named "<world>.old-<date>" and can be renamed by hand.
        }
    }

    /**
     * The panel reuses a server's state for 20 seconds, which is too old to delete anything
     * on, so the cached copy is dropped and the node is asked.
     */
    private function requireStopped(Server $server): void
    {
        Cache::forget('resources:'.$server->uuid);
        $state = $this->serverState($server);
        if ($state !== 'offline') {
            throw new HttpException(409, 'Stop the server first. It is '.$state.'.');
        }
    }

    /**
     * Run one world action at a time per server.
     *
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    private function exclusive(Server $server, \Closure $callback): mixed
    {
        $lock = Cache::lock('ext:mc-world:actions:'.$server->uuid, self::LOCK_SECONDS);
        if (! $lock->get()) {
            throw new HttpException(409, 'Another world action is running on this server. Wait for it to finish.');
        }

        try {
            return $callback();
        } finally {
            $lock->release();
            Cache::forever($this->versionKey($server), bin2hex(random_bytes(4)));
        }
    }

    private function versionKey(Server $server): string
    {
        return 'ext:mc-world:version:'.$server->uuid;
    }
}
