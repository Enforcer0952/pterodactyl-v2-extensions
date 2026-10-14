<?php

namespace PalworldTools\Support;

use DateTimeInterface;

/**
 * Names and layout of Palworld save data: worlds under Pal/Saved/SaveGames/0/<WorldId>/,
 * the game's own snapshots in <WorldId>/backup/world/<yyyy.mm.dd-hh.mm.ss>/ and the
 * archives this extension makes in palworld-backups/.
 *
 * The folder layout is not part of the official docs; it is what the game writes and
 * every part of it is treated as optional.
 */
final class SaveFiles
{
    /** Entries of a world folder that are not part of a panel backup. */
    private const string GAME_BACKUPS = 'backup';

    /**
     * @param  list<array<string, mixed>>  $entries a folder listing
     * @return list<string> world ids, folders only
     */
    public static function worlds(array $entries): array
    {
        $worlds = [];
        foreach ($entries as $entry) {
            $name = (string) ($entry['name'] ?? '');
            if (($entry['file'] ?? true) === false && Palworld::validWorld($name)) {
                $worlds[] = $name;
            }
        }
        sort($worlds);

        return $worlds;
    }

    /** Player save files: <32 hex>.sav (the _dps files hold the Pal storage). */
    public static function playerFiles(array $entries): int
    {
        $count = 0;
        foreach ($entries as $entry) {
            if (($entry['file'] ?? true) !== false && preg_match('/^[0-9A-Fa-f]{32}\.sav$/D', (string) ($entry['name'] ?? '')) === 1) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * The game's snapshots, newest first.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return list<array{name: string, at: string}>
     */
    public static function gameBackups(array $entries): array
    {
        $found = [];
        foreach ($entries as $entry) {
            $name = (string) ($entry['name'] ?? '');
            if (($entry['file'] ?? true) === false && preg_match('/^(\d{4})\.(\d{2})\.(\d{2})-(\d{2})\.(\d{2})\.(\d{2})$/D', $name, $m) === 1) {
                $found[] = ['name' => $name, 'at' => sprintf('%s-%s-%sT%s:%s:%s', $m[1], $m[2], $m[3], $m[4], $m[5], $m[6])];
            }
        }
        usort($found, fn (array $a, array $b): int => strcmp($b['name'], $a['name']));

        return $found;
    }

    public static function validGameBackup(string $name): bool
    {
        return preg_match('/^\d{4}\.\d{2}\.\d{2}-\d{2}\.\d{2}\.\d{2}$/D', $name) === 1;
    }

    /**
     * What goes into a panel backup of a world: everything except the game's own
     * snapshots (they can be larger than the world) and archives lying around.
     *
     * @param  list<array<string, mixed>>  $entries the world folder's listing
     * @return list<string>
     */
    public static function archiveEntries(array $entries): array
    {
        $names = [];
        foreach ($entries as $entry) {
            $name = (string) ($entry['name'] ?? '');
            if ($name === '' || $name === self::GAME_BACKUPS || str_contains($name, '/') || str_starts_with($name, '.') || str_ends_with($name, '.tar.gz')) {
                continue;
            }
            $names[] = $name;
        }
        sort($names);

        return $names;
    }

    public static function backupFile(string $world, DateTimeInterface $at): string
    {
        return $world.'-'.$at->format('Ymd-His').'.tar.gz';
    }

    /**
     * @return array{world: string, stamp: string}|null
     */
    public static function parseBackup(string $file): ?array
    {
        if (preg_match('/^([0-9A-Fa-f]{32})-(\d{8}-\d{6})\.tar\.gz$/D', $file, $m) !== 1) {
            return null;
        }

        return ['world' => $m[1], 'stamp' => $m[2]];
    }

    /**
     * Backups beyond the newest $keep of each world.
     *
     * @param  list<string>  $files file names
     * @return list<string>
     */
    public static function expired(array $files, int $keep): array
    {
        $byWorld = [];
        foreach ($files as $file) {
            $parsed = self::parseBackup($file);
            if ($parsed !== null) {
                $byWorld[strtolower($parsed['world'])][$file] = $parsed['stamp'];
            }
        }

        $old = [];
        foreach ($byWorld as $stamps) {
            arsort($stamps);
            $old = [...$old, ...array_slice(array_keys($stamps), max(1, $keep))];
        }

        return $old;
    }

    /** Where a replaced world is parked: palworld-backups/replaced/<stamp>-<world>. */
    public static function replacedName(string $world, DateTimeInterface $at): string
    {
        return $at->format('Ymd-His').'-'.$world;
    }

    /** Parked worlds to delete, keeping the newest $keep. */
    public static function expiredReplaced(array $names, int $keep): array
    {
        $valid = array_values(array_filter($names, fn (string $name): bool => preg_match('/^\d{8}-\d{6}-[0-9A-Fa-f]{32}$/D', $name) === 1));
        rsort($valid);

        return array_slice($valid, $keep);
    }

    /** The world id the server loads, from GameUserSettings.ini (DedicatedServerName=...). */
    public static function activeWorld(?string $gameUserSettings): ?string
    {
        if ($gameUserSettings !== null && preg_match('/^\s*DedicatedServerName\s*=\s*([0-9A-Fa-f]{32})\s*$/m', $gameUserSettings, $m) === 1) {
            return $m[1];
        }

        return null;
    }
}
