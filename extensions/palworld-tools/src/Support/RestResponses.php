<?php

namespace PalworldTools\Support;

/**
 * Turns the answers of the Palworld REST API into plain arrays for the page. Missing
 * or odd fields are tolerated, because the API differs a little between game versions.
 *
 * https://docs.palworldgame.com/category/rest-api (GET /info, /players, /metrics)
 */
final class RestResponses
{
    /**
     * @return list<array{name: string, account_name: ?string, user_id: string, player_id: ?string, level: ?int, ping: ?int, x: ?int, y: ?int, buildings: ?int}>
     */
    public static function players(mixed $body): array
    {
        $list = is_array($body) ? ($body['players'] ?? []) : [];
        if (! is_array($list)) {
            return [];
        }

        $players = [];
        foreach ($list as $player) {
            if (! is_array($player)) {
                continue;
            }
            $userId = self::string($player['userId'] ?? null, 64);
            if ($userId === null || ! Palworld::validUserId($userId)) {
                continue;
            }
            $players[] = [
                'name' => self::string($player['name'] ?? null, 64) ?? self::string($player['accountName'] ?? null, 64) ?? $userId,
                'account_name' => self::string($player['accountName'] ?? null, 64),
                'user_id' => $userId,
                'player_id' => self::string($player['playerId'] ?? null, 64),
                'level' => self::int($player['level'] ?? null),
                'ping' => self::int($player['ping'] ?? null),
                'x' => self::int($player['location_x'] ?? null),
                'y' => self::int($player['location_y'] ?? null),
                'buildings' => self::int($player['building_count'] ?? null),
            ];
        }

        usort($players, fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $players;
    }

    /**
     * @return array{version: ?string, name: ?string, description: ?string}
     */
    public static function info(mixed $body): array
    {
        $body = is_array($body) ? $body : [];

        return [
            'version' => self::string($body['version'] ?? null, 32),
            'name' => self::string($body['servername'] ?? null, 100),
            'description' => self::string($body['description'] ?? null, 200),
        ];
    }

    /**
     * @return array{fps: ?int, uptime: ?int, days: ?int, players: ?int, max_players: ?int, base_camps: ?int}
     */
    public static function metrics(mixed $body): array
    {
        $body = is_array($body) ? $body : [];

        return [
            'fps' => self::int($body['serverfps'] ?? null),
            'uptime' => self::int($body['uptime'] ?? null),
            'days' => self::int($body['days'] ?? null),
            'players' => self::int($body['currentplayernum'] ?? null),
            'max_players' => self::int($body['maxplayernum'] ?? null),
            'base_camps' => self::int($body['basecampnum'] ?? null),
        ];
    }

    private static function string(mixed $value, int $max): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        return Palworld::text((string) $value, $max);
    }

    private static function int(mixed $value): ?int
    {
        return is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)) ? (int) round((float) $value) : null;
    }
}
