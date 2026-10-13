<?php

namespace McPlayers\Support;

/**
 * Turns MCBans' public player and ban JSON into what the profile shows. Pure, so
 * it is tested without the network.
 */
final class McBansRecord
{
    /** At most this many bans are shown; the MCBans page has the rest. */
    public const int MAX_BANS = 25;

    /**
     * @param  array<string, mixed>|null  $player  /api/players/{uuid}, or null when MCBans has no record
     * @param  array<string, mixed>|null  $bans  /api/players/{uuid}/bans
     * @return array<string, mixed>
     */
    public static function from(string $uuid, ?array $player, ?array $bans): array
    {
        $url = 'https://www.mcbans.com/player/'.$uuid;
        if ($player === null) {
            return ['known' => false, 'url' => $url, 'reputation' => null, 'bans_total' => 0, 'bans' => []];
        }

        $items = is_array($bans['items'] ?? null) ? $bans['items'] : [];
        $rows = [];
        foreach (array_slice($items, 0, self::MAX_BANS) as $ban) {
            if (! is_array($ban)) {
                continue;
            }
            $rows[] = [
                'id' => is_int($ban['id'] ?? null) ? $ban['id'] : null,
                'type' => self::text($ban['type'] ?? null, 16) ?? 'unknown',
                'reason' => self::text($ban['reason'] ?? null, 300),
                'server' => self::text($ban['serverAddress'] ?? null, 120),
                'admin' => self::text($ban['adminName'] ?? null, 32),
                'date' => self::text($ban['date'] ?? null, 32),
                'permanent' => ($ban['permaban'] ?? false) === true,
            ];
        }

        $reputation = $player['reputation'] ?? null;

        return [
            'known' => true,
            'url' => $url,
            'name' => self::text($player['name'] ?? null, 32),
            'reputation' => is_int($reputation) || is_float($reputation) ? round((float) $reputation, 1) : null,
            'bans_total' => max(is_int($player['bansTotal'] ?? null) ? $player['bansTotal'] : 0, is_int($bans['total'] ?? null) ? $bans['total'] : 0),
            'bans' => $rows,
        ];
    }

    /** Plain text, control characters removed, length capped. */
    private static function text(mixed $value, int $max): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }
        $clean = trim((string) preg_replace('/[\p{Cc}\p{Zl}\p{Zp}]+/u', ' ', (string) $value));

        return $clean === '' ? null : mb_substr($clean, 0, $max);
    }
}
