<?php

namespace McPlayers\Support;

/**
 * The highlights of a player's stats file (world/stats/<uuid>.json), across the
 * old (1.12, flat "stat.x" keys) and new (1.13+, nested "minecraft:custom") formats.
 */
final class Stats
{
    /**
     * The world folder name from server.properties' level-name, or null when it is
     * missing or could point outside the server (a path, "..", a hidden folder).
     */
    public static function worldName(?string $levelName): ?string
    {
        $world = trim($levelName ?? '') ?: 'world';

        if (preg_match('#^[\w][\w .\-]{0,63}$#', $world) !== 1 || str_contains($world, '..') || str_ends_with($world, '.')) {
            return null;
        }

        return $world;
    }

    /**
     * Folders that can hold a world's stats files, newest layout first.
     *
     * @return list<string>
     */
    public static function folders(string $world): array
    {
        return ['/'.$world.'/players/stats', '/'.$world.'/stats'];
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array{play_seconds: int, deaths: int, mob_kills: int, player_kills: int, distance_m: int, blocks_mined: int, jumps: int}
     */
    public static function summarize(array $json): array
    {
        if (isset($json['stats']) && is_array($json['stats'])) {
            $stats = $json['stats'];
            $custom = is_array($stats['minecraft:custom'] ?? null) ? $stats['minecraft:custom'] : [];
            $get = fn (string $key): int => (int) ($custom['minecraft:'.$key] ?? 0);
            // play_one_minute was renamed play_time in 1.17; both count ticks.
            $ticks = $get('play_time') ?: $get('play_one_minute');
            $distance = 0;
            foreach (['walk_one_cm', 'sprint_one_cm', 'crouch_one_cm', 'swim_one_cm', 'fly_one_cm', 'boat_one_cm', 'horse_one_cm', 'minecart_one_cm', 'aviate_one_cm', 'walk_on_water_one_cm', 'walk_under_water_one_cm'] as $key) {
                $distance += $get($key);
            }

            return [
                'play_seconds' => intdiv($ticks, 20),
                'deaths' => $get('deaths'),
                'mob_kills' => $get('mob_kills'),
                'player_kills' => $get('player_kills'),
                'distance_m' => intdiv($distance, 100),
                'blocks_mined' => self::total($stats['minecraft:mined'] ?? []),
                'jumps' => $get('jump'),
            ];
        }

        $flat = fn (string $key): int => (int) ($json['stat.'.$key] ?? 0);
        $distance = 0;
        foreach (['walkOneCm', 'sprintOneCm', 'crouchOneCm', 'swimOneCm', 'flyOneCm', 'boatOneCm', 'horseOneCm', 'minecartOneCm', 'aviateOneCm'] as $key) {
            $distance += $flat($key);
        }
        $mined = 0;
        foreach ($json as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'stat.mineBlock.')) {
                $mined += (int) $value;
            }
        }

        return [
            'play_seconds' => intdiv($flat('playOneMinute'), 20),
            'deaths' => $flat('deaths'),
            'mob_kills' => $flat('mobKills'),
            'player_kills' => $flat('playerKills'),
            'distance_m' => intdiv($distance, 100),
            'blocks_mined' => $mined,
            'jumps' => $flat('jump'),
        ];
    }

    private static function total(mixed $group): int
    {
        return is_array($group) ? (int) array_sum(array_map('intval', $group)) : 0;
    }
}
