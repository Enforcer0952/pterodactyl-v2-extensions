<?php

namespace PalworldTools\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use PalworldTools\Support\Palworld;
use PalworldTools\Support\SaveFiles;
use PalworldTools\Support\SaveWait;
use Pterodactyl\Contracts\Servers\ReadsServerState;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Models\Server;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * World saves: list worlds, back one up as palworld-backups/<world>-<date>.tar.gz,
 * restore a backup or one of the game's own snapshots, delete backups.
 *
 * A world being replaced is never deleted: it is moved to palworld-backups/replaced/
 * first and put back if the restore fails. Those parked worlds are never removed
 * automatically; they can be deleted in the file manager.
 */
class Saves
{
    private const int DEFAULT_KEEP = 5;

    private const string REPLACED = 'replaced';

    /** How long a running server gets to finish a save before the backup is made anyway. */
    private const int SAVE_WAIT_SECONDS = 20;

    public function __construct(
        private readonly ServerFiles $files,
        private readonly SettingsFiles $settings,
        private readonly ReadsServerState $state,
        private readonly RestClient $rest,
    ) {}

    /** The lock that keeps backups, restores and the start after a settings change from overlapping. */
    public static function lockKey(Server $server): string
    {
        return 'ext:palworld-tools:saves:'.$server->uuid;
    }

    public function keep(): int
    {
        $value = app('ext.palworld-tools.settings')->get('backups_keep', self::DEFAULT_KEEP);

        return max(1, min(50, (int) $value));
    }

    /**
     * @return array<string, mixed>
     */
    public function overview(Server $server): array
    {
        $root = '/'.Palworld::SAVE_ROOT;
        $active = SaveFiles::activeWorld($this->files->read($server, $this->settings->locate($server)['directory'], 'GameUserSettings.ini', 262144));

        $ids = array_slice(SaveFiles::worlds($this->files->entries($server, $root)), 0, 12);
        $worlds = [];
        foreach ($ids as $id) {
            $dir = $root.'/'.$id;
            $entries = $this->files->entries($server, $dir);
            $level = $this->files->find($server, $dir, 'Level.sav', true);
            $worlds[] = [
                'id' => $id,
                'active' => $active !== null ? strcasecmp($active, $id) === 0 : count($ids) === 1,
                'has_level' => $level !== null,
                'size' => $level !== null ? (int) ($level['size'] ?? 0) : null,
                'modified' => $level['modified'] ?? null,
                'players' => SaveFiles::playerFiles($this->files->entries($server, $dir.'/Players')),
                'game_backups' => in_array('backup', array_column($entries, 'name'), true)
                    ? SaveFiles::gameBackups($this->files->entries($server, $dir.'/backup/world'))
                    : [],
            ];
        }

        $backups = [];
        foreach ($this->files->entries($server, '/'.Palworld::BACKUP_DIR) as $entry) {
            $parsed = SaveFiles::parseBackup((string) ($entry['name'] ?? ''));
            if ($parsed !== null && ($entry['file'] ?? true) !== false) {
                $backups[] = [
                    'file' => $entry['name'],
                    'world' => $parsed['world'],
                    'stamp' => $parsed['stamp'],
                    'size' => (int) ($entry['size'] ?? 0),
                    'modified' => $entry['modified'] ?? null,
                ];
            }
        }
        usort($backups, fn (array $a, array $b): int => strcmp($b['stamp'], $a['stamp']));

        return [
            'worlds' => $worlds,
            'backups' => $backups,
            'keep' => $this->keep(),
            'directory' => Palworld::BACKUP_DIR,
            'saves_directory' => Palworld::SAVE_ROOT,
        ];
    }

    /**
     * @return array{file: string, saved: ?bool} saved: whether a running server finished a fresh save first, null for a stopped one
     */
    public function backup(Server $server, string $world): array
    {
        $this->requireWorldId($world);

        return $this->exclusive($server, function () use ($server, $world): array {
            $running = $this->serverState($server) !== 'offline';
            $saved = $running ? $this->saveWorld($server, $world) : null;
            $file = $this->makeBackup($server, $world);
            // Old backups are only removed after a backup of a stopped server: one
            // made under a running server may be stale, and must not push out a good one.
            if (! $running) {
                $this->prune($server);
            }
            Activity::event('server:palworld.backup')->subject($server)->property(['world' => $world, 'file' => $file])->log();

            return ['file' => $file, 'saved' => $saved];
        });
    }

    /**
     * Ask the game to write the world and wait until Level.sav is newer. Without the
     * REST API, or when the game is slow, the copy may be a few minutes old.
     */
    private function saveWorld(Server $server, string $world): bool
    {
        $dir = '/'.Palworld::SAVE_ROOT.'/'.$world;
        $modified = function () use ($server, $dir): ?string {
            $this->files->forget();
            $modified = $this->files->find($server, $dir, 'Level.sav', true)['modified'] ?? null;

            return is_string($modified) ? $modified : null;
        };
        $before = $modified();

        try {
            $this->rest->post($server, '/save');
        } catch (Throwable) {
            return false;
        }

        $changed = SaveWait::changed($before, $modified, fn () => sleep(1), self::SAVE_WAIT_SECONDS);
        $this->files->forget();

        return $changed;
    }

    /**
     * Put a panel backup back as its world.
     *
     * @return array{world: string, kept: ?string}
     */
    public function restore(Server $server, string $file): array
    {
        $parsed = SaveFiles::parseBackup($file) ?? throw new HttpException(422, 'That is not a backup made by this page.');
        $world = $parsed['world'];

        return $this->exclusive($server, function () use ($server, $file, $world): array {
            $this->requireStopped($server);
            $backups = '/'.Palworld::BACKUP_DIR;
            if (! $this->files->exists($server, $backups, $file)) {
                throw new HttpException(404, 'That backup no longer exists.');
            }

            $stamp = CarbonImmutable::now('UTC');
            $dir = '/'.Palworld::SAVE_ROOT.'/'.$world;
            $temporary = 'restore-'.$stamp->format('Ymd-His').'.tar.gz';
            $hadWorld = $this->files->exists($server, '/'.Palworld::SAVE_ROOT, $world, false);
            $aside = $hadWorld ? $this->asidePath($server, $world, $stamp) : null;
            $parked = false;
            $created = false;

            try {
                if ($aside !== null) {
                    $this->files->move($server, $dir, $aside);
                }
                $this->files->ensureDirectory($server, $dir);
                $created = true;
                // Wings unpacks into the folder the archive is in, so it is moved there for a moment.
                $this->files->move($server, $backups.'/'.$file, $dir.'/'.$temporary);
                $parked = true;
                $this->files->decompress($server, $dir, $temporary);
                $this->files->move($server, $dir.'/'.$temporary, $backups.'/'.$file);
                $parked = false;

                if (! $this->files->exists($server, $dir, 'Level.sav')) {
                    throw new HttpException(422, 'That backup does not contain a world (Level.sav is missing).');
                }
                if ($aside !== null && $this->files->exists($server, $aside, 'backup', false)) {
                    $this->files->move($server, $aside.'/backup', $dir.'/backup');
                }
            } catch (Throwable $exception) {
                $this->undo($server, $dir, $aside, $created, $parked ? [$dir.'/'.$temporary, $backups.'/'.$file] : null);

                throw $exception instanceof HttpException || $exception instanceof DaemonConnectionException
                    ? $exception
                    : new HttpException(500, 'The backup could not be restored. The old world was put back.');
            }

            Activity::event('server:palworld.restore')->subject($server)->property(['world' => $world, 'backup' => $file, 'kept' => $aside])->log();

            return ['world' => $world, 'kept' => $aside === null ? null : ltrim($aside, '/')];
        });
    }

    /**
     * Put one of the game's own snapshots (backup/world/<date>) back as the world.
     *
     * @return array{world: string, kept: string}
     */
    public function restoreSnapshot(Server $server, string $world, string $snapshot): array
    {
        $this->requireWorldId($world);
        if (! SaveFiles::validGameBackup($snapshot)) {
            throw new HttpException(422, 'That is not a game backup.');
        }

        return $this->exclusive($server, function () use ($server, $world, $snapshot): array {
            $this->requireStopped($server);
            $dir = '/'.Palworld::SAVE_ROOT.'/'.$world;
            if (! $this->files->exists($server, '/'.Palworld::SAVE_ROOT, $world, false)) {
                throw new HttpException(404, 'That world no longer exists.');
            }
            if (! $this->files->exists($server, $dir.'/backup/world', $snapshot, false)) {
                throw new HttpException(404, 'That game backup no longer exists.');
            }

            $aside = $this->asidePath($server, $world, CarbonImmutable::now('UTC'));
            $step = 0;
            try {
                $this->files->move($server, $dir, $aside);
                $step = 1;
                $this->files->move($server, $aside.'/backup/world/'.$snapshot, $dir);
                $step = 2;
                if ($this->files->exists($server, $aside, 'backup', false)) {
                    $this->files->move($server, $aside.'/backup', $dir.'/backup');
                }
                $step = 3;
                if (! $this->files->exists($server, $dir, 'Level.sav')) {
                    throw new HttpException(422, 'That game backup does not contain a world (Level.sav is missing).');
                }
            } catch (Throwable $exception) {
                $this->undoSnapshot($server, $dir, $aside, $snapshot, $step);

                throw $exception instanceof HttpException || $exception instanceof DaemonConnectionException
                    ? $exception
                    : new HttpException(500, 'The game backup could not be restored. The old world was put back.');
            }

            // Files the snapshot lacks (LocalData.sav, WorldOption.sav) stay with the world.
            try {
                $present = array_column($this->files->entries($server, $dir), 'name');
                foreach ($this->files->entries($server, $aside) as $entry) {
                    $name = (string) ($entry['name'] ?? '');
                    if ($name !== '' && $name !== 'backup' && ! in_array($name, $present, true)) {
                        $this->files->move($server, $aside.'/'.$name, $dir.'/'.$name);
                    }
                }
            } catch (Throwable) {
                // The snapshot itself is restored; the leftovers stay in the parked folder.
            }

            Activity::event('server:palworld.restore')->subject($server)->property(['world' => $world, 'snapshot' => $snapshot, 'kept' => $aside])->log();

            return ['world' => $world, 'kept' => ltrim($aside, '/')];
        });
    }

    public function deleteBackup(Server $server, string $file): void
    {
        if (SaveFiles::parseBackup($file) === null) {
            throw new HttpException(422, 'That is not a backup made by this page.');
        }

        $this->exclusive($server, function () use ($server, $file): void {
            if (! $this->files->exists($server, '/'.Palworld::BACKUP_DIR, $file)) {
                throw new HttpException(404, 'That backup no longer exists.');
            }
            $this->files->delete($server, '/'.Palworld::BACKUP_DIR, [$file]);
        });
    }

    private function makeBackup(Server $server, string $world): string
    {
        $dir = '/'.Palworld::SAVE_ROOT.'/'.$world;
        if (! $this->files->exists($server, '/'.Palworld::SAVE_ROOT, $world, false) || ! $this->files->exists($server, $dir, 'Level.sav')) {
            throw new HttpException(404, 'There is no world with a Level.sav called '.$world.' on this server.');
        }
        $names = SaveFiles::archiveEntries($this->files->entries($server, $dir));

        $backups = '/'.Palworld::BACKUP_DIR;
        $this->files->ensureDirectory($server, $backups);
        $file = SaveFiles::backupFile($world, CarbonImmutable::now('UTC'));
        if ($this->files->exists($server, $backups, $file)) {
            throw new HttpException(409, 'A backup was just made. Wait a second and try again.');
        }

        $archive = $this->files->compress($server, $dir, $names);
        try {
            $this->files->move($server, $dir.'/'.$archive, $backups.'/'.$file);
        } catch (Throwable $exception) {
            try {
                $this->files->delete($server, $dir, [$archive]);
            } catch (Throwable) {
                // The loose archive can be removed in the file manager.
            }

            throw $exception;
        }

        return $file;
    }

    /** Keep the newest backups of each world; older ones are removed best effort. */
    private function prune(Server $server): void
    {
        try {
            $names = array_map(fn (array $entry): string => (string) ($entry['name'] ?? ''), $this->files->entries($server, '/'.Palworld::BACKUP_DIR));
            $this->files->delete($server, '/'.Palworld::BACKUP_DIR, SaveFiles::expired($names, $this->keep()));
        } catch (Throwable) {
            // An extra backup is harmless.
        }
    }

    /** Where the world being replaced is parked, with its folder ready. */
    private function asidePath(Server $server, string $world, CarbonImmutable $at): string
    {
        $this->files->ensureDirectory($server, '/'.Palworld::BACKUP_DIR.'/'.self::REPLACED);
        $name = SaveFiles::replacedName($world, $at);
        if ($this->files->exists($server, '/'.Palworld::BACKUP_DIR.'/'.self::REPLACED, $name, false)) {
            throw new HttpException(409, 'A restore ran a second ago. Try again in a moment.');
        }

        return '/'.Palworld::BACKUP_DIR.'/'.self::REPLACED.'/'.$name;
    }

    /**
     * @param  array{0: string, 1: string}|null  $archive  where the archive is parked, and where it belongs
     */
    private function undo(Server $server, string $dir, ?string $aside, bool $created, ?array $archive): void
    {
        try {
            if ($archive !== null) {
                $this->files->move($server, $archive[0], $archive[1]);
            }
            if ($created) {
                $this->files->delete($server, dirname($dir), [basename($dir)]);
            }
            if ($aside !== null) {
                $this->files->move($server, $aside, $dir);
            }
        } catch (Throwable) {
            // What is left is in palworld-backups/replaced and can be moved back by hand.
        }
    }

    private function undoSnapshot(Server $server, string $dir, string $aside, string $snapshot, int $step): void
    {
        try {
            if ($step >= 3 || ($step === 2 && $this->files->exists($server, $dir, 'backup', false))) {
                $this->files->move($server, $dir.'/backup', $aside.'/backup');
            }
            if ($step >= 2) {
                $this->files->move($server, $dir, $aside.'/backup/world/'.$snapshot);
            }
            $this->files->move($server, $aside, $dir);
        } catch (Throwable) {
            // What is left is in palworld-backups/replaced and can be moved back by hand.
        }
    }

    private function requireWorldId(string $world): void
    {
        if (! Palworld::validWorld($world)) {
            throw new HttpException(422, 'That is not a world folder name.');
        }
    }

    private function serverState(Server $server): string
    {
        try {
            Cache::forget('resources:'.$server->uuid); // the panel caches power state for 20 s
            return $this->state->read($server)->state;
        } catch (DaemonConnectionException) {
            throw new HttpException(502, 'Could not reach this server\'s node.');
        }
    }

    private function requireStopped(Server $server): void
    {
        $state = $this->serverState($server);
        if ($state !== 'offline') {
            throw new HttpException(409, 'Stop the server first. It is '.$state.'.');
        }
    }

    /**
     * Run one save action at a time per server.
     *
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    private function exclusive(Server $server, \Closure $callback): mixed
    {
        $lock = Cache::lock(self::lockKey($server), 900);
        if (! $lock->get()) {
            throw new HttpException(409, 'Another save action is running on this server. Wait for it to finish.');
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }
}
