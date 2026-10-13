<?php

namespace TerrariaTools\Support;

/**
 * World file names. A world is NAME.wld; the game keeps NAME.wld.bak next to it and
 * NAME.twld for modded content. Backups are NAME-YYYYMMDD-HHMMSS.wld.
 */
final class WorldFiles
{
    private const string BACKUP = '/^(?<world>.+)-(?<stamp>\d{8}-\d{6})\.wld$/uD';

    /** The world's name when this file is a world, else null. */
    public static function worldName(string $file): ?string
    {
        if (! str_ends_with($file, '.wld') || ! Terraria::plainFileName($file)) {
            return null;
        }

        $name = substr($file, 0, -4);

        return $name === '' ? null : $name;
    }

    /**
     * The files that belong to one world.
     *
     * @return list<string>
     */
    public static function companions(string $name): array
    {
        return [$name.'.wld', $name.'.wld.bak', $name.'.twld'];
    }

    public static function backupFile(string $world, \DateTimeInterface $at): string
    {
        return $world.'-'.$at->format('Ymd-His').'.wld';
    }

    /**
     * @return array{world: string, stamp: string}|null
     */
    public static function parseBackup(string $file): ?array
    {
        if (! Terraria::plainFileName($file) || preg_match(self::BACKUP, $file, $match) !== 1) {
            return null;
        }

        return ['world' => $match['world'], 'stamp' => $match['stamp']];
    }

    /** The backup's time, in the time zone it was named in. */
    public static function backupTime(string $stamp, ?\DateTimeZone $zone = null): ?\DateTimeImmutable
    {
        $time = \DateTimeImmutable::createFromFormat('!Ymd-His', $stamp, $zone);

        return $time === false ? null : $time;
    }

    /**
     * The backups to delete so each world keeps only its newest $keep.
     *
     * @param  list<string>  $files  backup file names
     * @return list<string>
     */
    public static function expired(array $files, int $keep): array
    {
        $byWorld = [];
        foreach ($files as $file) {
            $parsed = self::parseBackup($file);
            if ($parsed !== null) {
                $byWorld[$parsed['world']][$parsed['stamp']] = $file;
            }
        }

        $expired = [];
        foreach ($byWorld as $backups) {
            krsort($backups, SORT_STRING);
            foreach (array_slice($backups, max(1, $keep)) as $file) {
                $expired[] = $file;
            }
        }

        return $expired;
    }

    /** The world file path inside serverconfig.txt, written the way the file already has it. */
    public static function configPath(?string $currentWorld, ?string $worldpath, string $worldsDirectory, string $name): string
    {
        $file = $name.'.wld';
        $current = trim((string) $currentWorld);
        if ($current !== '' && str_contains($current, '/')) {
            return substr($current, 0, (int) strrpos($current, '/') + 1).$file;
        }

        $path = trim((string) $worldpath);
        if ($path !== '' && Terraria::serverPath($path) !== null) {
            return rtrim($path, '/').'/'.$file;
        }

        return Terraria::HOME.$worldsDirectory.'/'.$file;
    }
}
