<?php

namespace McPlayerData\Support;

use McPlayerData\Support\Nbt\Tag;

/**
 * The few changes the screen can make to a player's data. Each one touches only the
 * tags it needs and leaves every other tag, and the order of tags, as it was.
 */
final class PlayerEdits
{
    public const int MAX_HORIZONTAL = 30000000;

    public const int MIN_Y = -2048;

    public const int MAX_Y = 4064;

    private const array LEGACY_DIMENSIONS = ['0' => 'minecraft:overworld', '-1' => 'minecraft:the_nether', '1' => 'minecraft:the_end'];

    /**
     * Take an item (or part of a stack) out of one slot.
     *
     * @param  string  $area  inventory, ender or equipment
     * @param  int|string  $slot  a slot number, or head, chest, legs, feet or offhand for equipment
     * @param  int|null  $amount  how many to take; null takes the whole stack
     */
    public static function removeItem(Tag $root, string $area, int|string $slot, ?int $amount = null): void
    {
        if ($amount !== null && $amount < 1) {
            throw new PlayerEditException('Enter an amount of at least 1.');
        }

        if ($area === 'equipment') {
            $slot = (string) $slot;
            if (! in_array($slot, PlayerData::EQUIPMENT, true)) {
                throw new PlayerEditException('Unknown equipment slot.');
            }

            $found = false;
            $item = $root->get('equipment')?->get($slot);
            if ($item !== null && Items::describe($item) !== null) {
                $found = true;
                if (self::takeFrom($item, $amount)) {
                    $root->get('equipment')->remove($slot);
                }
            }

            // Versions before 1.21.5 keep it in the Inventory list, and a 1.21.5 file
            // can still carry the old entry too.
            $found = self::removeFromList($root, 'Inventory', PlayerData::LEGACY_SLOTS[$slot], $amount) || $found;
            if (! $found) {
                throw new PlayerEditException('That slot is already empty.');
            }

            return;
        }

        if (! in_array($area, ['inventory', 'ender'], true) || ! is_int($slot)) {
            throw new PlayerEditException('Unknown slot.');
        }

        $list = $area === 'inventory' ? 'Inventory' : 'EnderItems';
        // Slots outside the normal grid (modded or odd) can still be taken out of the inventory.
        if ($slot < -128 || $slot > 127 || ($area === 'ender' && $slot >= PlayerData::ENDER_SLOTS) || ($area === 'ender' && $slot < 0)) {
            throw new PlayerEditException('Unknown slot.');
        }

        if (! self::removeFromList($root, $list, $slot, $amount)) {
            throw new PlayerEditException('That slot is already empty.');
        }
    }

    /** Remove everything the player carries: main inventory, hotbar, armor and off hand. */
    public static function clearInventory(Tag $root): void
    {
        self::emptyList($root, 'Inventory');
        foreach (PlayerData::EQUIPMENT as $slot) {
            $root->get('equipment')?->remove($slot);
        }
    }

    public static function clearEnderChest(Tag $root): void
    {
        self::emptyList($root, 'EnderItems');
    }

    /** Remove all active effects. */
    public static function clearEffects(Tag $root): void
    {
        foreach (['active_effects', 'ActiveEffects'] as $key) {
            $root->remove($key);
        }
    }

    public static function setGameMode(Tag $root, int $mode): void
    {
        if (! isset(Names::GAME_MODES[$mode])) {
            throw new PlayerEditException('Game mode must be 0 (survival), 1 (creative), 2 (adventure) or 3 (spectator).');
        }

        $root->put('playerGameType', Tag::int($mode));
    }

    /**
     * Move the player. They keep their facing, and no longer ride a vehicle or
     * carry momentum or a fall from where they were.
     */
    public static function teleport(Tag $root, float $x, float $y, float $z, string $dimension): void
    {
        foreach ([$x, $y, $z] as $value) {
            if (! is_finite($value)) {
                throw new PlayerEditException('Coordinates must be numbers.');
            }
        }
        if (abs($x) > self::MAX_HORIZONTAL || abs($z) > self::MAX_HORIZONTAL) {
            throw new PlayerEditException('X and Z must be between -30000000 and 30000000.');
        }
        if ($y < self::MIN_Y || $y > self::MAX_Y) {
            throw new PlayerEditException('Y must be between '.self::MIN_Y.' and '.self::MAX_Y.'.');
        }
        if (! Names::isResourceLocation($dimension)) {
            throw new PlayerEditException('That is not a valid dimension id.');
        }

        // Files from before 1.16 name the dimension with a number.
        $current = $root->get('Dimension');
        $before = $current?->asInt() !== null ? self::LEGACY_DIMENSIONS[(string) $current->asInt()] ?? null : $current?->text();
        if ($current !== null && $current->asInt() !== null) {
            $number = array_search($dimension, self::LEGACY_DIMENSIONS, true);
            if ($number === false) {
                throw new PlayerEditException('This old save only knows the overworld, the nether and the end.');
            }
            $root->put('Dimension', new Tag($current->type, (int) $number));
        } else {
            $root->put('Dimension', Tag::string($dimension));
        }

        // The player file remembers the world by id. For another dimension that id is wrong,
        // and the game refuses the position; without it the game works the world out itself.
        if ($before !== $dimension) {
            $root->remove('WorldUUIDLeast');
            $root->remove('WorldUUIDMost');
        }

        $root->put('Pos', Tag::list(Tag::DOUBLE, [$x, $y, $z]));
        $root->remove('RootVehicle');

        $fall = $root->get('FallDistance') ?? $root->get('fall_distance');
        if ($fall !== null && $fall->isNumber()) {
            $root->put($root->has('FallDistance') ? 'FallDistance' : 'fall_distance', new Tag($fall->type, $fall->type === Tag::FLOAT || $fall->type === Tag::DOUBLE ? 0.0 : 0));
        }

        $motion = $root->get('Motion');
        if ($motion?->is(Tag::LIST) && $motion->elementType === Tag::DOUBLE) {
            $root->put('Motion', Tag::list(Tag::DOUBLE, array_fill(0, count($motion->value), 0.0)));
        }
    }

    /**
     * Where new players start: level.dat's spawn point. Older versions keep
     * Data.SpawnX/Y/Z in the overworld; newer ones a Data.spawn compound with a dimension.
     *
     * @return array{x: float, y: float, z: float, dimension: string}|null
     */
    public static function worldSpawn(Tag $levelRoot): ?array
    {
        $data = $levelRoot->get('Data');
        if ($data === null) {
            return null;
        }

        $spawn = $data->get('spawn');
        $pos = $spawn?->get('pos');
        if ($pos?->is(Tag::INT_ARRAY) && count($pos->value) >= 3) {
            $dimension = $spawn->get('dimension')?->text() ?? 'minecraft:overworld';

            return ['x' => $pos->value[0] + 0.5, 'y' => (float) $pos->value[1], 'z' => $pos->value[2] + 0.5, 'dimension' => Names::isResourceLocation($dimension) ? $dimension : 'minecraft:overworld'];
        }

        $x = $data->get('SpawnX')?->asInt();
        $y = $data->get('SpawnY')?->asInt();
        $z = $data->get('SpawnZ')?->asInt();
        if ($x === null || $y === null || $z === null) {
            return null;
        }

        return ['x' => $x + 0.5, 'y' => (float) $y, 'z' => $z + 0.5, 'dimension' => 'minecraft:overworld'];
    }

    /**
     * Reduce an item's count, or take it whole.
     *
     * @return bool true when nothing is left and the entry should go
     */
    private static function takeFrom(Tag $item, ?int $amount): bool
    {
        $uppercase = $item->has('Count') && ! $item->has('count');
        $count = Items::describe($item, false)['count'] ?? 0;
        if ($amount === null || $amount >= $count) {
            return true;
        }

        $left = $count - $amount;
        $item->put($uppercase ? 'Count' : 'count', $uppercase ? Tag::byte($left > 127 ? $left - 256 : $left) : Tag::int($left));

        return false;
    }

    /** @return bool whether an item was found in that slot */
    private static function removeFromList(Tag $root, string $key, int $slot, ?int $amount): bool
    {
        $list = $root->get($key);
        if (! $list?->is(Tag::LIST) || $list->elementType !== Tag::COMPOUND) {
            return false;
        }

        $found = false;
        $kept = [];
        foreach ($list->value as $entry) {
            if ($entry->get('Slot')?->asInt() === $slot && Items::describe($entry) !== null) {
                $found = true;
                if (! self::takeFrom($entry, $amount)) {
                    $kept[] = $entry;
                }

                continue;
            }
            $kept[] = $entry;
        }

        if ($found) {
            $root->put($key, $kept === [] ? Tag::list(Tag::END) : new Tag(Tag::LIST, $kept, Tag::COMPOUND));
        }

        return $found;
    }

    private static function emptyList(Tag $root, string $key): void
    {
        $list = $root->get($key);
        if ($list === null) {
            return;
        }

        $root->put($key, Tag::list(Tag::END));
    }
}
