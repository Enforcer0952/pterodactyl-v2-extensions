<?php

namespace ValheimTools\Support;

use Pterodactyl\Models\EggVariable;
use Pterodactyl\Models\Server;

/**
 * Shared checks and input rules. Everything that ends up in a file path or the
 * startup command is cleaned here.
 *
 * Sources for the facts used in this extension:
 * - Iron Gate, "A Guide to Dedicated Servers" (valheimgame.com/support/a-guide-to-dedicated-servers):
 *   save folder ~/.config/unity3d/IronGate/Valheim, -savedir, adminlist.txt / bannedlist.txt /
 *   permittedlist.txt, ID format [Platform]_[User ID], launch options and presets.
 * - Valheim patch notes on Steam (1.0 "New save system", hotfix 1.0.12 about old and new IDs).
 * - The pelican-eggs/games-steamcmd Valheim eggs (variables SERVER_NAME, WORLD, PASSWORD, ...).
 */
final class Valheim
{
    /** Steam app id of the Valheim dedicated server. */
    public const string APP_ID = '896660';

    /** Where the eggs keep the server's files. */
    public const string HOME = '/home/container';

    /** Default save folder, relative to the server root (the eggs run with HOME=/home/container). */
    public const string DEFAULT_SAVE_DIR = '/.config/unity3d/IronGate/Valheim';

    public const string BACKUPS_DIR = '/valheim-backups';

    public const string TEMP_DIR = '/valheim-tools-temp';

    /** Whether this is a Valheim server: the "valheim" tag, a valheim_server startup, or the app id. */
    public static function isValheim(Server $server): bool
    {
        $appId = $server->variables()->where('env_variable', 'SRCDS_APPID')->first();

        return self::matches(
            $server->tagSlugs(),
            (string) $server->startup,
            $appId instanceof EggVariable ? (string) ($appId->server_value ?? $appId->default_value) : null,
        );
    }

    /**
     * @param  list<string>  $tags  egg tag slugs
     */
    public static function matches(array $tags, string $startup, ?string $appId = null): bool
    {
        return in_array('valheim', array_map('strtolower', $tags), true)
            || stripos($startup, 'valheim_server') !== false
            || $appId === self::APP_ID;
    }

    /** One line of plain text: control characters removed, length capped. Null when nothing is left. */
    public static function text(?string $value, int $max = 200): ?string
    {
        if ($value === null) {
            return null;
        }

        $clean = trim(preg_replace('/[\p{Cc}\p{Zl}\p{Zp}]+/u', ' ', $value) ?? '');

        return $clean === '' ? null : mb_substr($clean, 0, $max);
    }

    /**
     * A world name that is safe in -world "NAME": letters, digits, spaces, dash, underscore, dot.
     * The startup command is evaluated by a shell, so quotes, dollar signs and backslashes never pass.
     */
    public static function validWorldName(string $name): bool
    {
        return preg_match('/^[\p{L}\p{N}](?:[\p{L}\p{N} _.\-]*[\p{L}\p{N}_\-])?$/uD', $name) === 1
            && ! str_contains($name, '..')
            && mb_strlen($name) <= 20;
    }

    /** A file or folder name from a listing that is safe to act on: one plain name, nothing hidden. */
    public static function plainName(string $name): bool
    {
        return $name !== ''
            && strlen($name) <= 255
            && $name[0] !== '.'
            && ! str_contains($name, '..')
            && preg_match('#[/\\\\\p{Cc}\p{Zl}\p{Zp}]#u', $name) !== 1
            && mb_check_encoding($name, 'UTF-8');
    }

    /**
     * A path from the startup command as a folder inside the server. Accepts
     * /home/container/... or a relative path. Null for anything that could leave the server.
     */
    public static function serverPath(?string $value): ?string
    {
        $path = trim((string) $value);
        if ($path === '' || preg_match('/[\p{Cc}\p{Zl}\p{Zp}\\\\$`{}"\']/u', $path) === 1 || ! mb_check_encoding($path, 'UTF-8')) {
            return null;
        }

        if ($path === self::HOME || str_starts_with($path, self::HOME.'/')) {
            $path = substr($path, strlen(self::HOME));
        } elseif (str_starts_with($path, '/')) {
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

    /**
     * The folder with worlds and access lists: -savedir from the startup command
     * when it points inside the server, else the default under .config.
     */
    public static function saveDirectory(string $startup): string
    {
        if (preg_match('/(?:^|\s)-savedir\s+(?:"([^"]*)"|\'([^\']*)\'|([^\s"\']+))/i', $startup, $match) === 1) {
            $value = ($match[1] ?? '') !== '' ? $match[1] : (($match[2] ?? '') !== '' ? $match[2] : ($match[3] ?? ''));
            $path = self::serverPath($value);
            if ($path !== null && $path !== '/') {
                return $path;
            }
        }

        return self::DEFAULT_SAVE_DIR;
    }
}
