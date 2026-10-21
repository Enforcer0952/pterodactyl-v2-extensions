<?php

namespace ValheimTools\Support;

/**
 * Groups the entries of the worlds folder (worlds_local) into worlds and Valheim's
 * own automatic backups.
 *
 * Before 1.0 a world is NAME.db plus NAME.fwl. Valheim 1.0 stores a world as a folder
 * called NAME (the data split into chunks), and backups as folders too (patch notes:
 * "New save system"). A pre-1.0 world is converted when a 1.0 server loads it: the pair is
 * renamed NAME.db.old and NAME.fwl.old, and a copy NAME_backup_<date> is made.
 * Automatic backups are NAME_backup_auto-YYYYMMDDHHMMSS (folder or .db/.fwl pair).
 */
final class WorldFiles
{
    private const string BACKUP = '/^(?<world>.+)_backup_(?<auto>auto-)?(?<stamp>[A-Za-z0-9.\-]+)$/uD';

    /**
     * @param  list<array{name: string, file: bool, size: int, modified: ?string}>  $entries
     * @return array{
     *     worlds: list<array{name: string, format: string, entries: list<string>, complete: bool, size: int, modified: ?string}>,
     *     backups: list<array{id: string, world: string, stamp: string, auto: bool, format: string, entries: list<string>, size: int, modified: ?string}>,
     *     originals: list<array{world: string, entries: list<string>, size: int, modified: ?string}>
     * }
     */
    public static function group(array $entries): array
    {
        /** @var array<string, array{folder: ?array, db: ?array, fwl: ?array}> $stems */
        $stems = [];
        /** @var array<string, array{entries: list<string>, size: int, modified: ?string}> $originals */
        $originals = [];

        foreach ($entries as $entry) {
            $name = $entry['name'];
            if (! Valheim::plainName($name)) {
                continue;
            }
            if (! $entry['file']) {
                $stems[$name]['folder'] = $entry;

                continue;
            }
            if (preg_match('/^(.+)\.(db|fwl)\.old$/uD', $name, $m) === 1) {
                $originals[$m[1]]['entries'][] = $name;
                $originals[$m[1]]['size'] = ($originals[$m[1]]['size'] ?? 0) + $entry['size'];
                $originals[$m[1]]['modified'] = self::newer($originals[$m[1]]['modified'] ?? null, $entry['modified']);

                continue;
            }
            if (preg_match('/^(.+)\.(db|fwl)$/uD', $name, $m) === 1) {
                $stems[$m[1]][$m[2]] = $entry;
            }
        }

        $worlds = [];
        $backups = [];
        foreach ($stems as $stem => $parts) {
            $names = [];
            $size = 0;
            $modified = null;
            foreach (['folder', 'db', 'fwl'] as $part) {
                if (isset($parts[$part])) {
                    $names[] = $parts[$part]['name'];
                    $size += $parts[$part]['size'];
                    $modified = self::newer($modified, $parts[$part]['modified']);
                }
            }
            $format = isset($parts['folder']) ? 'folder' : 'files';

            if (preg_match(self::BACKUP, (string) $stem, $m) === 1) {
                $backups[] = [
                    'id' => (string) $stem,
                    'world' => $m['world'],
                    'stamp' => $m['stamp'],
                    'auto' => $m['auto'] !== '',
                    'format' => $format,
                    'entries' => $names,
                    'size' => $size,
                    'modified' => $modified,
                ];

                continue;
            }

            $worlds[] = [
                'name' => (string) $stem,
                'format' => $format,
                'entries' => $names,
                'complete' => $format === 'folder' || (isset($parts['db']) && isset($parts['fwl'])),
                'size' => $size,
                'modified' => $modified,
            ];
        }

        usort($worlds, fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        // Newest first. The stamp formats differ (automatic, converted), so the file time decides.
        usort($backups, fn (array $a, array $b): int => [(int) strtotime((string) $b['modified']), $b['id']] <=> [(int) strtotime((string) $a['modified']), $a['id']]);

        $kept = [];
        foreach ($originals as $world => $info) {
            $kept[] = ['world' => (string) $world] + $info + ['size' => 0, 'modified' => null];
        }
        usort($kept, fn (array $a, array $b): int => strcasecmp($a['world'], $b['world']));

        return ['worlds' => $worlds, 'backups' => $backups, 'originals' => $kept];
    }

    /** The backup's time from an automatic backup stamp (YYYYMMDDHHMMSS), as the server's clock wrote it. */
    public static function stampTime(string $stamp): ?\DateTimeImmutable
    {
        if (preg_match('/^(?:auto-)?(\d{14})$/D', $stamp, $m) !== 1) {
            return null;
        }
        $time = \DateTimeImmutable::createFromFormat('!YmdHis', $m[1], new \DateTimeZone('UTC'));

        return $time !== false && $time->format('YmdHis') === $m[1] ? $time : null;
    }

    /** Folder name of an extension backup: NAME-YYYYMMDD-HHMMSS (plus a kind for safety copies). */
    public static function backupFolder(string $world, \DateTimeInterface $at, string $kind = ''): string
    {
        return $world.($kind !== '' ? '-'.$kind : '').'-'.$at->format('Ymd-His');
    }

    /**
     * @return array{world: string, kind: string, stamp: string}|null
     */
    public static function parseBackupFolder(string $name): ?array
    {
        if (! Valheim::plainName($name)
            || preg_match('/^(?<world>.+?)(?:-(?<kind>before-restore|before-delete))?-(?<stamp>\d{8}-\d{6})$/uD', $name, $m) !== 1) {
            return null;
        }

        return ['world' => $m['world'], 'kind' => $m['kind'] ?? '', 'stamp' => $m['stamp']];
    }

    /**
     * Backup folders to delete so each world keeps its newest $keep of each kind.
     * Backups of the worlds in $protected are never listed.
     *
     * @param  list<string>  $folders
     * @param  list<string>  $protected  world names (the one the server loads)
     * @return list<string>
     */
    public static function expired(array $folders, int $keep, array $protected = []): array
    {
        $groups = [];
        foreach ($folders as $folder) {
            $parsed = self::parseBackupFolder($folder);
            if ($parsed !== null && ! in_array($parsed['world'], $protected, true)) {
                $groups[$parsed['world']."\0".($parsed['kind'] === '' ? 'manual' : 'safety')][$parsed['stamp']] = $folder;
            }
        }

        $expired = [];
        foreach ($groups as $items) {
            krsort($items, SORT_STRING);
            foreach (array_slice($items, max(1, $keep)) as $folder) {
                $expired[] = $folder;
            }
        }

        return $expired;
    }

    private static function newer(?string $a, ?string $b): ?string
    {
        if ($a === null) {
            return $b;
        }
        if ($b === null) {
            return $a;
        }

        return strtotime($b) > strtotime($a) ? $b : $a;
    }
}
