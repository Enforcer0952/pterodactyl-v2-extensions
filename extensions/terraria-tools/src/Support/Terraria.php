<?php

namespace TerrariaTools\Support;

use Pterodactyl\Models\Server;

/**
 * Shared checks and input rules. Everything that ends up in a console command or a
 * file path is cleaned here, so a name or message can never smuggle in a second
 * command or reach outside the server folder.
 */
final class Terraria
{
    /** Where the egg keeps the server's files. */
    public const string HOME = '/home/container';

    public const string CONFIG_FILE = 'serverconfig.txt';

    public const string DEFAULT_WORLDS = '/saves/Worlds';

    public const string DEFAULT_BANLIST = 'banlist.txt';

    public const int MAX_MESSAGE = 200;

    public const int MAX_PLAYER_NAME = 60;

    /** A new world's name: letters, digits, spaces and a few separators. */
    private const string NEW_WORLD = '/^[\p{L}\p{N}][\p{L}\p{N} _.\'\-]{0,39}$/uD';

    /** Whether this is a Terraria server: the "terraria" tag, or a startup command that runs TerrariaServer. */
    public static function isTerraria(Server $server): bool
    {
        $tags = array_map('strtolower', $server->tagSlugs());

        return self::matches($tags, (string) $server->startup);
    }

    /**
     * @param  list<string>  $tags  egg tag slugs
     */
    public static function matches(array $tags, string $startup): bool
    {
        return in_array('terraria', array_map('strtolower', $tags), true) || stripos($startup, 'TerrariaServer') !== false;
    }

    /** One line of plain text: control characters removed, length capped. Null when nothing is left. */
    public static function text(?string $value, int $max = self::MAX_MESSAGE): ?string
    {
        if ($value === null) {
            return null;
        }

        // All control characters, including C1 and Unicode line breaks, could end the command early.
        $clean = trim(preg_replace('/[\p{Cc}\p{Zl}\p{Zp}]+/u', ' ', $value) ?? '');

        return $clean === '' ? null : mb_substr($clean, 0, $max);
    }

    /** A player name for kick and ban: Terraria takes the rest of the line as the name. */
    public static function playerName(?string $value): ?string
    {
        return self::text($value, self::MAX_PLAYER_NAME);
    }

    /** Whether a name is fine for a world that does not exist yet. */
    public static function validNewWorld(string $name): bool
    {
        return preg_match(self::NEW_WORLD, $name) === 1 && ! str_contains($name, '..') && ! str_ends_with($name, '.');
    }

    /**
     * Whether a file name from a directory listing is safe to act on: one plain
     * name, no folder separators, no control characters, not hidden.
     */
    public static function plainFileName(string $name): bool
    {
        return $name !== ''
            && strlen($name) <= 255
            && $name[0] !== '.'
            && ! str_contains($name, '..')
            && preg_match('#[/\\\\\p{Cc}\p{Zl}\p{Zp}]#u', $name) !== 1
            && mb_check_encoding($name, 'UTF-8');
    }

    /**
     * A path from serverconfig.txt, as a folder or file path inside the server.
     * Accepts /home/container/... or a path relative to it. Returns null for
     * anything that could leave the server folder or is not plain text.
     */
    public static function serverPath(?string $value): ?string
    {
        $path = trim((string) $value);
        if ($path === '' || preg_match('/[\p{Cc}\p{Zl}\p{Zp}\\\\]/u', $path) === 1 || ! mb_check_encoding($path, 'UTF-8')) {
            return null;
        }

        if ($path === self::HOME || str_starts_with($path, self::HOME.'/')) {
            $path = substr($path, strlen(self::HOME));
        } elseif (str_starts_with($path, '/')) {
            // Some other absolute path: it is not inside the server's files.
            return null;
        }

        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                return null;
            }
            $parts[] = $part;
        }

        return '/'.implode('/', $parts);
    }

    /** The folder holding the worlds: worldpath from serverconfig.txt, else /saves/Worlds. */
    public static function worldsDirectory(?string $worldpath): string
    {
        $path = self::serverPath($worldpath);

        return $path === null || $path === '/' ? self::DEFAULT_WORLDS : $path;
    }

    /** The folder for world backups: "Backups" next to the worlds folder. */
    public static function backupsDirectory(string $worldsDirectory): string
    {
        $parent = dirname($worldsDirectory);

        return ($parent === '/' || $parent === '.' ? '' : $parent).'/Backups';
    }

    /**
     * The ban list file: banlist= from serverconfig.txt when it points inside the
     * server, else banlist.txt in the server folder.
     */
    public static function banlistPath(?string $configured): string
    {
        $path = self::serverPath($configured);

        return $path === null || $path === '/' ? '/'.self::DEFAULT_BANLIST : $path;
    }
}
