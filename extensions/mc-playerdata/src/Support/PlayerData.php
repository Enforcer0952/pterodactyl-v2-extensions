<?php

namespace McPlayerData\Support;

use McPlayerData\Support\Nbt\Tag;

/**
 * Reads the parts of a player's .dat that the screen shows. Everything is optional:
 * a player who never moved, or a file from another game version, simply lacks tags.
 */
final class PlayerData
{
    /** Equipment slots, in the order the screen lists them. */
    public const array EQUIPMENT = ['head', 'chest', 'legs', 'feet', 'offhand'];

    /** Where an equipment slot lived in the Inventory list before 1.21.5. */
    public const array LEGACY_SLOTS = ['head' => 103, 'chest' => 102, 'legs' => 101, 'feet' => 100, 'offhand' => -106];

    public const int INVENTORY_SLOTS = 36;

    public const int ENDER_SLOTS = 27;

    /**
     * @return array<string, mixed>
     */
    public static function summarize(Tag $root): array
    {
        $inventory = [];
        $equipment = array_fill_keys(self::EQUIPMENT, null);
        $other = [];

        foreach ($root->get('Inventory')?->tags() ?? [] as $entry) {
            $slot = $entry->get('Slot')?->asInt();
            $item = Items::describe($entry);
            if ($slot === null || $item === null) {
                continue;
            }

            $legacy = array_search($slot, self::LEGACY_SLOTS, true);
            if ($slot >= 0 && $slot < self::INVENTORY_SLOTS) {
                $inventory[$slot] = ['slot' => $slot, ...$item];
            } elseif ($legacy !== false) {
                $equipment[$legacy] = ['slot' => $legacy, ...$item];
            } else {
                $other[] = ['slot' => $slot, ...$item];
            }
        }

        // 1.21.5 and later keep armor and the off hand in a compound of their own.
        foreach (self::EQUIPMENT as $name) {
            $tag = $root->get('equipment')?->get($name);
            $item = $tag === null ? null : Items::describe($tag);
            if ($item !== null) {
                $equipment[$name] = ['slot' => $name, ...$item];
            }
        }

        $ender = [];
        foreach ($root->get('EnderItems')?->tags() ?? [] as $entry) {
            $slot = $entry->get('Slot')?->asInt();
            $item = Items::describe($entry);
            if ($slot !== null && $item !== null) {
                $ender[$slot] = ['slot' => $slot, ...$item];
            }
        }

        $pos = self::numbers($root->get('Pos'), 3);
        $rotation = self::numbers($root->get('Rotation'), 2);
        $mode = $root->get('playerGameType')?->asInt();
        $dimension = self::dimension($root->get('Dimension'));

        ksort($inventory);
        ksort($ender);
        usort($other, fn (array $a, array $b): int => $a['slot'] <=> $b['slot']);

        return [
            'data_version' => $root->get('DataVersion')?->asInt(),
            'inventory' => array_values($inventory),
            'equipment' => $equipment,
            'ender' => array_values($ender),
            'other' => $other,
            'selected_slot' => $root->get('SelectedItemSlot')?->asInt(),
            'position' => $pos === null ? null : [
                'x' => $pos[0], 'y' => $pos[1], 'z' => $pos[2],
                'yaw' => $rotation[0] ?? null, 'pitch' => $rotation[1] ?? null,
            ],
            'dimension' => $dimension === null ? null : ['id' => $dimension, 'name' => Names::dimension($dimension)],
            'spawn' => self::spawn($root),
            'xp' => [
                'level' => $root->get('XpLevel')?->asInt(),
                'progress' => $root->get('XpP')?->asFloat(),
                'total' => $root->get('XpTotal')?->asInt(),
            ],
            'health' => $root->get('Health')?->asFloat(),
            'max_health' => self::maxHealth($root),
            'food' => [
                'level' => $root->get('foodLevel')?->asInt(),
                'saturation' => $root->get('foodSaturationLevel')?->asFloat(),
            ],
            'game_mode' => $mode === null ? null : ['id' => $mode, 'name' => Names::GAME_MODES[$mode] ?? 'Unknown'],
            'effects' => self::effects($root),
        ];
    }

    /**
     * The dimension id as text, also for the numbers saved before 1.16.
     */
    public static function dimension(?Tag $tag): ?string
    {
        if ($tag === null) {
            return null;
        }

        return $tag->text() ?? ($tag->asInt() !== null ? Names::oldDimension($tag->asInt()) : null);
    }

    /**
     * @return list<float>|null
     */
    private static function numbers(?Tag $tag, int $count): ?array
    {
        if (! $tag?->is(Tag::LIST) || count($tag->value) < $count || ! in_array($tag->elementType, [Tag::DOUBLE, Tag::FLOAT, Tag::INT, Tag::SHORT, Tag::BYTE, Tag::LONG], true)) {
            return null;
        }

        return array_map(fn (mixed $v): float => (float) $v, array_slice($tag->value, 0, $count));
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function spawn(Tag $root): ?array
    {
        // Newer versions: a "respawn" compound.
        $respawn = $root->get('respawn');
        if ($respawn?->is(Tag::COMPOUND)) {
            $pos = $respawn->get('pos');
            if ($pos?->is(Tag::INT_ARRAY) && count($pos->value) >= 3) {
                $dimension = $respawn->get('dimension')?->text() ?? 'minecraft:overworld';

                return [
                    'x' => $pos->value[0], 'y' => $pos->value[1], 'z' => $pos->value[2],
                    'dimension' => ['id' => $dimension, 'name' => Names::dimension($dimension)],
                    'forced' => ($respawn->get('forced')?->asInt() ?? 0) !== 0,
                ];
            }
        }

        $x = $root->get('SpawnX')?->asInt();
        $y = $root->get('SpawnY')?->asInt();
        $z = $root->get('SpawnZ')?->asInt();
        if ($x === null || $y === null || $z === null) {
            return null;
        }

        $dimension = self::dimension($root->get('SpawnDimension')) ?? 'minecraft:overworld';

        return [
            'x' => $x, 'y' => $y, 'z' => $z,
            'dimension' => ['id' => $dimension, 'name' => Names::dimension($dimension)],
            'forced' => ($root->get('SpawnForced')?->asInt() ?? 0) !== 0,
        ];
    }

    private static function maxHealth(Tag $root): ?float
    {
        foreach (['attributes', 'Attributes'] as $key) {
            foreach ($root->get($key)?->tags() ?? [] as $attribute) {
                $name = $attribute->get('id')?->text() ?? $attribute->get('Name')?->text() ?? '';
                if (preg_match('/(^|[:.])max_health$/D', $name) === 1) {
                    return ($attribute->get('base') ?? $attribute->get('Base'))?->asFloat();
                }
            }
        }

        return null;
    }

    /**
     * Active effects, from both layouts (a string "id" since 1.20.2, a numeric "Id" before).
     *
     * @return list<array<string, mixed>>
     */
    private static function effects(Tag $root): array
    {
        $out = [];
        foreach (['active_effects', 'ActiveEffects'] as $key) {
            foreach ($root->get($key)?->tags() ?? [] as $effect) {
                $idTag = $effect->get('id') ?? $effect->get('Id');
                $id = $idTag?->text() ?? ($idTag?->asInt() !== null ? Names::effectId($idTag->asInt()) : null);
                if ($id === null) {
                    continue;
                }
                $id = str_contains($id, ':') ? $id : 'minecraft:'.$id;

                $amplifier = ($effect->get('amplifier') ?? $effect->get('Amplifier'))?->asInt() ?? 0;
                $duration = ($effect->get('duration') ?? $effect->get('Duration'))?->asInt() ?? 0;
                $out[] = [
                    'id' => $id,
                    'name' => Names::readable($id),
                    'level' => ($amplifier & 0xFF) + 1,
                    'ticks' => $duration,
                    'infinite' => $duration < 0,
                    'seconds' => $duration < 0 ? null : intdiv($duration, 20),
                ];
            }
        }

        return $out;
    }
}
