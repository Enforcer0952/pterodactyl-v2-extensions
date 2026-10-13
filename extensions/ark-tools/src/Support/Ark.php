<?php

namespace ArkTools\Support;

use Pterodactyl\Models\EggVariable;
use Pterodactyl\Models\Server;

/**
 * Shared checks and input rules. Everything that ends up in an RCON command is
 * cleaned here, so a name or message can never smuggle in a second command.
 */
final class Ark
{
    /** Steam app id of the ARK: Survival Evolved dedicated server. */
    public const string APP_ID = '376030';

    /** A SteamID64 of an individual account. */
    public const string STEAM_ID = '/^7656119\d{10}$/D';

    /** Steam Workshop item ids. */
    public const string MOD_ID = '/^\d{5,12}$/D';

    public const string MODS_DIR = 'ShooterGame/Content/Mods';

    /** Whether this is an ARK: Survival Evolved server: the "ark" tag, or the egg's app id. */
    public static function isArk(Server $server): bool
    {
        $tags = array_map('strtolower', $server->tagSlugs());
        if (in_array('ark', $tags, true)) {
            return true;
        }

        $appId = $server->variables()->where('env_variable', 'SRCDS_APPID')->first();

        return $appId instanceof EggVariable && (string) ($appId->server_value ?? $appId->default_value) === self::APP_ID;
    }

    /** One line of plain text: control characters removed, length capped. */
    public static function text(?string $value, int $max = 200): ?string
    {
        if ($value === null) {
            return null;
        }

        // All control characters, including C1 and Unicode line breaks, could end the command early.
        $clean = trim(preg_replace('/[\p{Cc}\p{Zl}\p{Zp}]+/u', ' ', $value) ?? '');

        return $clean === '' ? null : mb_substr($clean, 0, $max);
    }

    /**
     * Steam ids found in a list file. ARK writes one per line, sometimes followed by
     * a comma or a name.
     *
     * @return list<string>
     */
    public static function steamIds(string $contents): array
    {
        preg_match_all('/\b(7656119\d{10})\b/', $contents, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * Workshop ids from a MOD_ID value such as "731604991, 1999447172".
     *
     * @return list<string>
     */
    public static function modIds(?string $value): array
    {
        $ids = [];
        foreach (preg_split('/[\s,]+/', (string) $value) ?: [] as $part) {
            if (preg_match(self::MOD_ID, $part) === 1 && ! in_array($part, $ids, true)) {
                $ids[] = $part;
            }
        }

        return $ids;
    }
}
