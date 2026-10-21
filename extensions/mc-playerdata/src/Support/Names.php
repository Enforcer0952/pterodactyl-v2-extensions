<?php

namespace McPlayerData\Support;

/** Readable names for Minecraft ids. */
final class Names
{
    public const array GAME_MODES = [0 => 'Survival', 1 => 'Creative', 2 => 'Adventure', 3 => 'Spectator'];

    /** Effects by their number, as saved before 1.20.2. */
    private const array EFFECT_IDS = [
        1 => 'speed', 2 => 'slowness', 3 => 'haste', 4 => 'mining_fatigue', 5 => 'strength', 6 => 'instant_health',
        7 => 'instant_damage', 8 => 'jump_boost', 9 => 'nausea', 10 => 'regeneration', 11 => 'resistance',
        12 => 'fire_resistance', 13 => 'water_breathing', 14 => 'invisibility', 15 => 'blindness', 16 => 'night_vision',
        17 => 'hunger', 18 => 'weakness', 19 => 'poison', 20 => 'wither', 21 => 'health_boost', 22 => 'absorption',
        23 => 'saturation', 24 => 'glowing', 25 => 'levitation', 26 => 'luck', 27 => 'unluck', 28 => 'slow_falling',
        29 => 'conduit_power', 30 => 'dolphins_grace', 31 => 'bad_omen', 32 => 'hero_of_the_village', 33 => 'darkness',
    ];

    private const array DIMENSIONS = [
        'minecraft:overworld' => 'Overworld',
        'minecraft:the_nether' => 'The Nether',
        'minecraft:the_end' => 'The End',
    ];

    /** Dimensions as numbers, as saved before 1.16. */
    private const array OLD_DIMENSIONS = [0 => 'minecraft:overworld', -1 => 'minecraft:the_nether', 1 => 'minecraft:the_end'];

    /**
     * "minecraft:diamond_sword" is "Diamond Sword". Ids from mods and data packs
     * keep their namespace, so they are never mistaken for vanilla ones.
     */
    public static function readable(string $id): string
    {
        if (! str_contains($id, ':')) {
            $id = 'minecraft:'.$id;
        }

        [$namespace, $path] = explode(':', $id, 2);
        if ($namespace !== 'minecraft' || $path === '') {
            return $id;
        }

        return ucwords(str_replace(['_', '/'], [' ', ' / '], $path));
    }

    public static function dimension(string $id): string
    {
        return self::DIMENSIONS[$id] ?? self::readable($id);
    }

    public static function oldDimension(int $number): ?string
    {
        return self::OLD_DIMENSIONS[$number] ?? null;
    }

    public static function effectId(int $number): string
    {
        return 'minecraft:'.(self::EFFECT_IDS[$number] ?? 'effect_'.$number);
    }

    /** A namespaced id as a resource location, or false when it is not one. */
    public static function isResourceLocation(string $id): bool
    {
        return preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/D', $id) === 1 && strlen($id) <= 128;
    }
}
