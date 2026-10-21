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

    /** The modded-content file that goes with a world file or a world backup file (NAME.wld -> NAME.twld). */
    public static function twld(string $wldFile): string
    {
        return substr($wldFile, 0, -4).'.twld';
    }

    /**
     * Every world name the server may load: the one in the "world" path, "worldname",
     * and the WORLD_NAME startup variable. An egg can fill serverconfig.txt from the
     * variable at start, so a world named by either one counts as in use.
     *
     * @return list<string>
     */
    public static function activeNames(?string $world, ?string $worldName, ?string $variable): array
    {
        $names = [];
        $path = trim((string) $world);
        if ($path !== '') {
            $base = basename(str_replace('\\', '/', $path));
            $names[] = str_ends_with($base, '.wld') ? substr($base, 0, -4) : $base;
        }
        foreach ([$worldName, $variable] as $name) {
            if ($name !== null && trim($name) !== '') {
                $names[] = trim($name);
            }
        }

        return array_values(array_unique(array_filter($names, fn (string $name): bool => $name !== '')));
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
