<?php

use McPlayerData\Support\Nbt\NbtDocument;
use McPlayerData\Support\Nbt\Tag;

/**
 * Player files built to mirror what Minecraft writes, following the Minecraft Wiki
 * pages "Player.dat format" and "Item format". Three layouts:
 *  - legacy: 1.20.1, items with Count and tag, armor in Inventory slots 100-103 and -106
 *  - components: 1.21.1, items with count and components, enchantments as {levels:{...}}
 *  - modern: 1.21.5, armor in an equipment compound, flat enchantments, NBT text components
 */
final class PlayerFixtures
{
    public static function legacy(): NbtDocument
    {
        $sword = Tag::compound([
            'Slot' => Tag::byte(0),
            'id' => Tag::string('minecraft:diamond_sword'),
            'Count' => Tag::byte(1),
            'tag' => Tag::compound([
                'Damage' => Tag::int(120),
                'Enchantments' => Tag::list(Tag::COMPOUND, [
                    Tag::compound(['id' => Tag::string('minecraft:sharpness'), 'lvl' => Tag::short(5)]),
                    Tag::compound(['id' => Tag::string('minecraft:looting'), 'lvl' => Tag::short(3)]),
                ]),
                'display' => Tag::compound([
                    'Name' => Tag::string('{"text":"Excalibur","italic":false}'),
                    'Lore' => Tag::list(Tag::STRING, ['{"text":"Pulled from a stone"}']),
                ]),
                'RepairCost' => Tag::int(7),
            ]),
        ]);

        $inventory = [
            $sword,
            self::legacyItem(1, 'minecraft:cooked_beef', 64),
            self::legacyItem(8, 'minecraft:torch', 200),
            self::legacyItem(9, 'create:brass_ingot', 12),
            self::legacyItem(35, 'minecraft:oak_planks', 3),
            self::legacyItem(100, 'minecraft:iron_boots', 1),
            self::legacyItem(103, 'minecraft:iron_helmet', 1),
            self::legacyItem(-106, 'minecraft:shield', 1),
            self::legacyItem(40, 'somemod:strange_slot_item', 2),
        ];

        $root = Tag::compound([
            'DataVersion' => Tag::int(3465),
            'Pos' => Tag::list(Tag::DOUBLE, [100.5, 64.0, -200.25]),
            'Motion' => Tag::list(Tag::DOUBLE, [0.0, -0.0784, 0.0]),
            'Rotation' => Tag::list(Tag::FLOAT, [90.0, 15.5]),
            'FallDistance' => Tag::float(3.5),
            'Dimension' => Tag::string('minecraft:overworld'),
            'playerGameType' => Tag::int(0),
            'previousPlayerGameType' => Tag::int(-1),
            'SelectedItemSlot' => Tag::int(2),
            'XpLevel' => Tag::int(30),
            'XpP' => Tag::float(0.5),
            'XpTotal' => Tag::int(1395),
            'Health' => Tag::float(18.5),
            'foodLevel' => Tag::int(15),
            'foodSaturationLevel' => Tag::float(2.5),
            'foodExhaustionLevel' => Tag::float(0.25),
            'SpawnX' => Tag::int(10),
            'SpawnY' => Tag::int(70),
            'SpawnZ' => Tag::int(-20),
            'SpawnAngle' => Tag::float(0.0),
            'SpawnForced' => Tag::byte(1),
            'SpawnDimension' => Tag::string('minecraft:overworld'),
            'ActiveEffects' => Tag::list(Tag::COMPOUND, [
                Tag::compound(['Id' => Tag::byte(1), 'Amplifier' => Tag::byte(1), 'Duration' => Tag::int(1200), 'Ambient' => Tag::byte(0), 'ShowParticles' => Tag::byte(1)]),
                Tag::compound(['Id' => Tag::byte(16), 'Amplifier' => Tag::byte(0), 'Duration' => Tag::int(-1), 'Ambient' => Tag::byte(1), 'ShowParticles' => Tag::byte(0)]),
            ]),
            'Attributes' => Tag::list(Tag::COMPOUND, [
                Tag::compound(['Name' => Tag::string('minecraft:generic.max_health'), 'Base' => Tag::double(20.0)]),
                Tag::compound(['Name' => Tag::string('minecraft:generic.movement_speed'), 'Base' => Tag::double(0.1)]),
            ]),
            'Inventory' => Tag::list(Tag::COMPOUND, $inventory),
            'EnderItems' => Tag::list(Tag::COMPOUND, [
                self::legacyItem(0, 'minecraft:diamond', 64),
                self::legacyItem(26, 'minecraft:netherite_ingot', 9),
            ]),
            'abilities' => Tag::compound([
                'flying' => Tag::byte(0), 'flySpeed' => Tag::float(0.05), 'instabuild' => Tag::byte(0),
                'invulnerable' => Tag::byte(0), 'mayBuild' => Tag::byte(1), 'mayfly' => Tag::byte(0), 'walkSpeed' => Tag::float(0.1),
            ]),
            'UUID' => Tag::intArray([-1, 2, -3, 4]),
            'recipeBook' => Tag::compound(['recipes' => Tag::list(Tag::STRING, ['minecraft:stick']), 'isFurnaceGuiOpen' => Tag::byte(0)]),
            'seenCredits' => Tag::byte(1),
            'Brain' => Tag::compound(['memories' => Tag::compound()]),
            'Score' => Tag::int(42),
        ]);

        return new NbtDocument($root, '', true);
    }

    public static function components(): NbtDocument
    {
        $axe = Tag::compound([
            'Slot' => Tag::byte(4),
            'id' => Tag::string('minecraft:netherite_axe'),
            'count' => Tag::int(1),
            'components' => Tag::compound([
                'minecraft:enchantments' => Tag::compound([
                    'levels' => Tag::compound(['minecraft:efficiency' => Tag::int(5), 'minecraft:unbreaking' => Tag::int(3)]),
                    'show_in_tooltip' => Tag::byte(1),
                ]),
                'minecraft:custom_name' => Tag::string('{"text":"Lumberjack","color":"gold","italic":false}'),
                'minecraft:damage' => Tag::int(33),
                'minecraft:max_damage' => Tag::int(2031),
                'minecraft:lore' => Tag::list(Tag::STRING, ['{"text":"Chops fast"}']),
            ]),
        ]);

        $shulker = Tag::compound([
            'Slot' => Tag::byte(10),
            'id' => Tag::string('minecraft:blue_shulker_box'),
            'count' => Tag::int(1),
            'components' => Tag::compound([
                'minecraft:container' => Tag::list(Tag::COMPOUND, [
                    Tag::compound(['slot' => Tag::int(0), 'item' => Tag::compound(['id' => Tag::string('minecraft:diamond'), 'count' => Tag::int(32)])]),
                    Tag::compound(['slot' => Tag::int(1), 'item' => Tag::compound(['id' => Tag::string('minecraft:stick'), 'count' => Tag::int(5)])]),
                ]),
            ]),
        ]);

        $root = Tag::compound([
            'DataVersion' => Tag::int(3955),
            'Pos' => Tag::list(Tag::DOUBLE, [-5.5, 80.0, 12.5]),
            'Rotation' => Tag::list(Tag::FLOAT, [0.0, 0.0]),
            'Dimension' => Tag::string('minecraft:the_nether'),
            'playerGameType' => Tag::int(1),
            'SelectedItemSlot' => Tag::int(0),
            'XpLevel' => Tag::int(5),
            'XpP' => Tag::float(0.25),
            'XpTotal' => Tag::int(55),
            'Health' => Tag::float(20.0),
            'foodLevel' => Tag::int(20),
            'foodSaturationLevel' => Tag::float(5.0),
            'active_effects' => Tag::list(Tag::COMPOUND, [
                Tag::compound(['id' => Tag::string('minecraft:regeneration'), 'amplifier' => Tag::byte(0), 'duration' => Tag::int(600), 'ambient' => Tag::byte(0), 'show_particles' => Tag::byte(1), 'show_icon' => Tag::byte(1)]),
            ]),
            'attributes' => Tag::list(Tag::COMPOUND, [
                Tag::compound(['id' => Tag::string('minecraft:generic.max_health'), 'base' => Tag::double(40.0)]),
            ]),
            'Inventory' => Tag::list(Tag::COMPOUND, [
                $axe,
                $shulker,
                self::componentItem(100, 'minecraft:diamond_boots', 1),
                self::componentItem(102, 'minecraft:diamond_chestplate', 1),
            ]),
            'EnderItems' => Tag::list(Tag::COMPOUND, [self::componentItem(3, 'minecraft:obsidian', 64)]),
            'abilities' => Tag::compound(['flying' => Tag::byte(0), 'instabuild' => Tag::byte(1)]),
            'Motion' => Tag::list(Tag::DOUBLE, [0.0, 0.0, 0.0]),
            'fall_distance' => Tag::double(0.0),
            'UUID' => Tag::intArray([5, 6, 7, 8]),
        ]);

        return new NbtDocument($root, '', true);
    }

    public static function modern(): NbtDocument
    {
        $bow = Tag::compound([
            'Slot' => Tag::byte(1),
            'id' => Tag::string('minecraft:bow'),
            'count' => Tag::int(1),
            'components' => Tag::compound([
                'minecraft:enchantments' => Tag::compound(['minecraft:power' => Tag::int(4), 'minecraft:infinity' => Tag::int(1)]),
                'minecraft:custom_name' => Tag::compound(['text' => Tag::string('Skyfall'), 'color' => Tag::string('aqua'), 'italic' => Tag::byte(0)]),
                'minecraft:damage' => Tag::int(10),
                'minecraft:lore' => Tag::list(Tag::COMPOUND, [Tag::compound(['text' => Tag::string('Long range')])]),
            ]),
        ]);

        $book = Tag::compound([
            'Slot' => Tag::byte(2),
            'id' => Tag::string('minecraft:enchanted_book'),
            'count' => Tag::int(1),
            'components' => Tag::compound([
                'minecraft:stored_enchantments' => Tag::compound(['minecraft:mending' => Tag::int(1)]),
                'minecraft:custom_name' => Tag::string('Plain literal name'),
            ]),
        ]);

        $root = Tag::compound([
            'DataVersion' => Tag::int(4325),
            'Pos' => Tag::list(Tag::DOUBLE, [0.5, 100.0, 0.5]),
            'Rotation' => Tag::list(Tag::FLOAT, [-45.0, 10.0]),
            'Dimension' => Tag::string('minecraft:the_end'),
            'playerGameType' => Tag::int(2),
            'previousPlayerGameType' => Tag::int(0),
            'SelectedItemSlot' => Tag::int(1),
            'XpLevel' => Tag::int(0),
            'XpP' => Tag::float(0.0),
            'XpTotal' => Tag::int(0),
            'Health' => Tag::float(7.0),
            'foodLevel' => Tag::int(3),
            'foodSaturationLevel' => Tag::float(0.0),
            'respawn' => Tag::compound([
                'pos' => Tag::intArray([-100, 65, 300]),
                'dimension' => Tag::string('minecraft:overworld'),
                'forced' => Tag::byte(0),
                'yaw' => Tag::float(0.0),
                'pitch' => Tag::float(0.0),
            ]),
            'active_effects' => Tag::list(Tag::COMPOUND, [
                Tag::compound(['id' => Tag::string('minecraft:speed'), 'amplifier' => Tag::byte(2), 'duration' => Tag::int(400), 'ambient' => Tag::byte(0), 'show_particles' => Tag::byte(1), 'show_icon' => Tag::byte(1)]),
                Tag::compound(['id' => Tag::string('mymod:rage'), 'amplifier' => Tag::byte(0), 'duration' => Tag::int(-1), 'ambient' => Tag::byte(0), 'show_particles' => Tag::byte(1), 'show_icon' => Tag::byte(1)]),
            ]),
            'Inventory' => Tag::list(Tag::COMPOUND, [
                $bow,
                $book,
                self::componentItem(0, 'minecraft:cooked_porkchop', 32),
                self::componentItem(5, 'mymod:ruby_sword', 1),
            ]),
            'equipment' => Tag::compound([
                'head' => Tag::compound(['id' => Tag::string('minecraft:turtle_helmet'), 'count' => Tag::int(1)]),
                'chest' => Tag::compound(['id' => Tag::string('minecraft:elytra'), 'count' => Tag::int(1), 'components' => Tag::compound(['minecraft:damage' => Tag::int(100)])]),
                'offhand' => Tag::compound(['id' => Tag::string('minecraft:totem_of_undying'), 'count' => Tag::int(1)]),
            ]),
            'EnderItems' => Tag::list(Tag::COMPOUND, [self::componentItem(13, 'minecraft:ender_pearl', 16)]),
            'fall_distance' => Tag::double(12.5),
            'Motion' => Tag::list(Tag::DOUBLE, [0.0, -1.5, 0.0]),
            'RootVehicle' => Tag::compound(['Attach' => Tag::intArray([1, 2, 3, 4]), 'Entity' => Tag::compound(['id' => Tag::string('minecraft:boat')])]),
            'abilities' => Tag::compound(['flying' => Tag::byte(0)]),
            'UUID' => Tag::intArray([9, 10, 11, 12]),
        ]);

        return new NbtDocument($root, '', true);
    }

    public static function legacyItem(int $slot, string $id, int $count): Tag
    {
        return Tag::compound(['Slot' => Tag::byte($slot), 'id' => Tag::string($id), 'Count' => Tag::byte($count > 127 ? $count - 256 : $count)]);
    }

    public static function componentItem(int $slot, string $id, int $count): Tag
    {
        return Tag::compound(['Slot' => Tag::byte($slot), 'id' => Tag::string($id), 'count' => Tag::int($count)]);
    }

    /** Level data for the world spawn, old and new layouts. */
    public static function level(bool $modern): NbtDocument
    {
        $data = $modern
            ? Tag::compound(['LevelName' => Tag::string('world'), 'spawn' => Tag::compound(['pos' => Tag::intArray([12, 71, -34]), 'dimension' => Tag::string('minecraft:overworld'), 'yaw' => Tag::float(0.0), 'pitch' => Tag::float(0.0)])])
            : Tag::compound(['LevelName' => Tag::string('world'), 'SpawnX' => Tag::int(12), 'SpawnY' => Tag::int(71), 'SpawnZ' => Tag::int(-34)]);

        return new NbtDocument(Tag::compound(['Data' => $data]), '', true);
    }
}
