<?php

namespace PalworldTools\Support;

use Pterodactyl\Models\EggVariable;
use Pterodactyl\Models\Server;

/**
 * Shared checks and input rules. Everything that ends up in a REST request or a file
 * path is cleaned here.
 */
final class Palworld
{
    /** Steam app id of the Palworld dedicated server. */
    public const string APP_ID = '2394010';

    public const string SAVE_ROOT = 'Pal/Saved/SaveGames/0';

    public const string BAN_LIST = 'Pal/Saved/SaveGames/banlist.txt';

    public const string BACKUP_DIR = 'palworld-backups';

    /** Player ids as the REST API gives them: steam_7656..., xbox_..., mac_... */
    public const string USER_ID = '/^[A-Za-z0-9_.:-]{1,64}$/D';

    /** World folders are 32 hex characters. */
    public const string WORLD_ID = '/^[0-9A-Fa-f]{32}$/D';

    /** Whether a server is a Palworld server: the "palworld" tag, a PalServer startup command or the app id. */
    public static function matches(array $tags, string $startup, ?string $appId): bool
    {
        return in_array('palworld', array_map('strtolower', $tags), true)
            || stripos($startup, 'PalServer') !== false
            || $appId === self::APP_ID;
    }

    public static function isPalworld(Server $server): bool
    {
        $appId = $server->variables()->where('env_variable', 'SRCDS_APPID')->first();

        return self::matches(
            $server->tagSlugs(),
            (string) $server->startup,
            $appId instanceof EggVariable ? (string) ($appId->server_value ?? $appId->default_value) : null,
        );
    }

    public static function validUserId(?string $id): bool
    {
        return $id !== null && preg_match(self::USER_ID, $id) === 1;
    }

    public static function validWorld(?string $id): bool
    {
        return $id !== null && preg_match(self::WORLD_ID, $id) === 1;
    }

    /** One line of plain text: control characters removed, length capped. */
    public static function text(?string $value, int $max = 200): ?string
    {
        if ($value === null) {
            return null;
        }

        $clean = trim(preg_replace('/[\p{Cc}\p{Zl}\p{Zp}]+/u', ' ', $value) ?? '');

        return $clean === '' ? null : mb_substr($clean, 0, $max);
    }
}
