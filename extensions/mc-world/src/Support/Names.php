<?php

namespace McWorld\Support;

use DateTimeInterface;

/**
 * Every name that ends up in a file path: world folders, backup files and the
 * suffixes added to them. Nothing here is trusted until it passes these checks.
 */
final class Names
{
    public const string BACKUP_DIR = 'world-backups';

    public const string NETHER = '_nether';

    public const string END = '_the_end';

    /** Folders in the server root that are never a world, even when one holds a level.dat. */
    private const array PROTECTED = [
        'plugins', 'mods', 'config', 'configs', 'logs', 'libraries', 'versions', 'cache', 'crash-reports', 'world-backups',
        'backups', 'defaultconfigs', 'datapacks', 'resourcepacks', 'kubejs', 'scripts', 'bluemap', 'dynmap',
    ];

    /** A folder name made of letters, digits, underscore, dash, dot and inner spaces. */
    private const string WORLD = '/^[\p{L}\p{N}_][\p{L}\p{N}_ .\-]{0,99}$/Du';

    private const string BACKUP = '/^(.+)-(\d{8}-\d{6})\.tar\.gz$/Du';

    private const string STAMP = '/^\d{8}-\d{6}$/D';

    public static function world(string $name): bool
    {
        return preg_match(self::WORLD, $name) === 1
            && ! str_contains($name, '..')
            && ! str_ends_with($name, ' ')
            && ! str_ends_with($name, '.')
            && ! in_array(strtolower($name), self::PROTECTED, true);
    }

    public static function stamp(DateTimeInterface $time): string
    {
        return $time->format('Ymd-His');
    }

    public static function validStamp(string $stamp): bool
    {
        return preg_match(self::STAMP, $stamp) === 1;
    }

    public static function nether(string $world): string
    {
        return $world.self::NETHER;
    }

    public static function end(string $world): string
    {
        return $world.self::END;
    }

    /** True for a folder name that is the Paper-style nether or end folder of some world. */
    public static function dimensionOf(string $folder): ?string
    {
        foreach ([self::NETHER, self::END] as $suffix) {
            if (str_ends_with($folder, $suffix) && strlen($folder) > strlen($suffix)) {
                return substr($folder, 0, -strlen($suffix));
            }
        }

        return null;
    }

    public static function old(string $world, string $stamp): string
    {
        return $world.'.old-'.$stamp;
    }

    public static function backupFile(string $world, string $stamp): string
    {
        return $world.'-'.$stamp.'.tar.gz';
    }

    /**
     * @return array{world: string, stamp: string}|null
     */
    public static function parseBackup(string $file): ?array
    {
        if (preg_match(self::BACKUP, $file, $match) !== 1 || ! self::world($match[1]) || ! self::validStamp($match[2])) {
            return null;
        }

        return ['world' => $match[1], 'stamp' => $match[2]];
    }

    /**
     * A seed typed by the user: one line of plain text. Null means random.
     *
     * @throws \InvalidArgumentException
     */
    public static function seed(?string $seed): ?string
    {
        $seed = trim((string) $seed);
        if ($seed === '') {
            return null;
        }
        if (preg_match('/[\p{Cc}\p{Zl}\p{Zp}]/u', $seed) === 1 || mb_strlen($seed) > 100) {
            throw new \InvalidArgumentException('The seed must be one line of at most 100 characters.');
        }

        return $seed;
    }
}
