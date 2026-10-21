<?php

require_once __DIR__.'/PlayerFixtures.php';

use McPlayerData\Support\Nbt\NbtDocument;
use McPlayerData\Support\Nbt\Reader;
use McPlayerData\Support\Nbt\Tag;
use McPlayerData\Support\Nbt\Writer;
use McPlayerData\Support\PlayerData;
use McPlayerData\Support\PlayerEditException;
use McPlayerData\Support\PlayerEdits;
use PHPUnit\Framework\TestCase;

final class PlayerEditsTest extends TestCase
{
    /**
     * Apply an edit, write the file, read it back and check that it is valid NBT in
     * which every top-level tag other than $touched is exactly as it was, in the same order.
     *
     * @param  list<string>  $touched
     */
    private function edit(NbtDocument $document, callable $change, array $touched): NbtDocument
    {
        $before = Reader::readFile(Writer::writeFile($document));
        $beforeKeys = array_map('strval', array_keys($before->root->value));

        $change($document->root);
        $after = Reader::readFile(Writer::writeFile($document));

        $this->assertSame($document->gzip, $after->gzip);
        $this->assertSame(Writer::writeDocument($document), Writer::writeDocument($after));

        foreach ($beforeKeys as $key) {
            if (in_array($key, $touched, true)) {
                continue;
            }
            $this->assertEquals($before->root->get($key), $after->root->get($key), "Tag $key changed");
        }

        // Tags that stay keep their order; new ones only ever come at the end.
        $afterKeys = array_map('strval', array_keys($after->root->value));
        $this->assertSame(array_values(array_intersect($beforeKeys, $afterKeys)), array_values(array_intersect($afterKeys, $beforeKeys)));

        return $after;
    }

    /** @return list<array<string, mixed>> */
    private function slots(Tag $list): array
    {
        return array_map(fn (Tag $item): array => ['slot' => $item->get('Slot')->value, 'id' => $item->get('id')->text()], $list->tags());
    }

    public function test_remove_one_slot_in_every_layout(): void
    {
        $after = $this->edit(PlayerFixtures::legacy(), fn (Tag $r) => PlayerEdits::removeItem($r, 'inventory', 1), ['Inventory']);
        $this->assertSame([0, 8, 9, 35, 100, 103, -106, 40], array_column($this->slots($after->root->get('Inventory')), 'slot'));
        // The other entries are untouched, enchantments and all.
        $this->assertSame(PlayerData::summarize(PlayerFixtures::legacy()->root)['inventory'][0], PlayerData::summarize($after->root)['inventory'][0]);

        $after = $this->edit(PlayerFixtures::components(), fn (Tag $r) => PlayerEdits::removeItem($r, 'inventory', 4), ['Inventory']);
        $this->assertNotContains(4, array_column($this->slots($after->root->get('Inventory')), 'slot'));

        $after = $this->edit(PlayerFixtures::modern(), fn (Tag $r) => PlayerEdits::removeItem($r, 'inventory', 0), ['Inventory']);
        $this->assertSame([1, 2, 5], array_column($this->slots($after->root->get('Inventory')), 'slot'));
    }

    public function test_remove_from_the_ender_chest(): void
    {
        $after = $this->edit(PlayerFixtures::legacy(), fn (Tag $r) => PlayerEdits::removeItem($r, 'ender', 26), ['EnderItems']);
        $this->assertSame([0], array_column($this->slots($after->root->get('EnderItems')), 'slot'));

        $after = $this->edit(PlayerFixtures::modern(), fn (Tag $r) => PlayerEdits::removeItem($r, 'ender', 13), ['EnderItems']);
        $this->assertSame([], $after->root->get('EnderItems')->value);
    }

    public function test_remove_armor_and_the_off_hand(): void
    {
        // 1.21.5 and later: the equipment compound.
        $after = $this->edit(PlayerFixtures::modern(), fn (Tag $r) => PlayerEdits::removeItem($r, 'equipment', 'chest'), ['equipment']);
        $this->assertSame(['head', 'offhand'], array_map('strval', array_keys($after->root->get('equipment')->value)));
        $this->assertEquals(PlayerFixtures::modern()->root->get('equipment')->get('head'), $after->root->get('equipment')->get('head'));

        // Before: Inventory slots 100-103 and -106.
        $after = $this->edit(PlayerFixtures::legacy(), fn (Tag $r) => PlayerEdits::removeItem($r, 'equipment', 'offhand'), ['Inventory']);
        $this->assertNotContains(-106, array_column($this->slots($after->root->get('Inventory')), 'slot'));
        $this->assertContains(103, array_column($this->slots($after->root->get('Inventory')), 'slot'));

        $after = $this->edit(PlayerFixtures::components(), fn (Tag $r) => PlayerEdits::removeItem($r, 'equipment', 'feet'), ['Inventory']);
        $this->assertNull(PlayerData::summarize($after->root)['equipment']['feet']);
        $this->assertNotNull(PlayerData::summarize($after->root)['equipment']['chest']);
    }

    public function test_remove_part_of_a_stack(): void
    {
        $after = $this->edit(PlayerFixtures::legacy(), fn (Tag $r) => PlayerEdits::removeItem($r, 'inventory', 1, 4), ['Inventory']);
        $item = PlayerData::summarize($after->root)['inventory'][1];
        $this->assertSame(60, $item['count']);
        // The legacy count stays a byte.
        $this->assertSame(Tag::BYTE, $after->root->get('Inventory')->tags()[1]->get('Count')->type);

        $after = $this->edit(PlayerFixtures::legacy(), fn (Tag $r) => PlayerEdits::removeItem($r, 'inventory', 8, 72), ['Inventory']);
        $this->assertSame(128, PlayerData::summarize($after->root)['inventory'][2]['count']);
        $this->assertSame(-128, $after->root->get('Inventory')->tags()[2]->get('Count')->value);

        $after = $this->edit(PlayerFixtures::modern(), fn (Tag $r) => PlayerEdits::removeItem($r, 'inventory', 0, 10), ['Inventory']);
        $this->assertSame(22, PlayerData::summarize($after->root)['inventory'][0]['count']);
        $this->assertSame(Tag::INT, $after->root->get('Inventory')->tags()[2]->get('count')->type);

        // Taking at least the whole stack removes it.
        $after = $this->edit(PlayerFixtures::modern(), fn (Tag $r) => PlayerEdits::removeItem($r, 'inventory', 0, 32), ['Inventory']);
        $this->assertNotContains(0, array_column($this->slots($after->root->get('Inventory')), 'slot'));

        $after = $this->edit(PlayerFixtures::modern(), fn (Tag $r) => PlayerEdits::removeItem($r, 'equipment', 'chest', 1), ['equipment']);
        $this->assertNull($after->root->get('equipment')->get('chest'));
    }

    public function test_removing_something_that_is_not_there_is_an_error(): void
    {
        foreach ([['inventory', 2], ['inventory', 20], ['ender', 5], ['equipment', 'legs'], ['equipment', 'bogus'], ['bogus', 1], ['ender', 27], ['ender', -1], ['inventory', 500]] as [$area, $slot]) {
            try {
                PlayerEdits::removeItem(PlayerFixtures::legacy()->root, $area, $slot);
                $this->fail("Expected an error for $area $slot");
            } catch (PlayerEditException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(PlayerEditException::class);
        PlayerEdits::removeItem(PlayerFixtures::legacy()->root, 'inventory', 0, 0);
    }

    public function test_clear_inventory_takes_armor_and_the_off_hand_too(): void
    {
        $after = $this->edit(PlayerFixtures::legacy(), fn (Tag $r) => PlayerEdits::clearInventory($r), ['Inventory']);
        $this->assertSame([], $after->root->get('Inventory')->value);
        $this->assertSame(Tag::END, $after->root->get('Inventory')->elementType);
        $this->assertCount(2, $after->root->get('EnderItems')->value);

        $after = $this->edit(PlayerFixtures::modern(), fn (Tag $r) => PlayerEdits::clearInventory($r), ['Inventory', 'equipment']);
        $data = PlayerData::summarize($after->root);
        $this->assertSame([], $data['inventory']);
        $this->assertSame([], $data['other']);
        $this->assertSame(array_fill_keys(PlayerData::EQUIPMENT, null), $data['equipment']);
        $this->assertCount(1, $data['ender']);
    }

    public function test_clear_ender_chest(): void
    {
        foreach ([PlayerFixtures::legacy(), PlayerFixtures::components(), PlayerFixtures::modern()] as $document) {
            $count = count(PlayerData::summarize($document->root)['inventory']);
            $after = $this->edit($document, fn (Tag $r) => PlayerEdits::clearEnderChest($r), ['EnderItems']);
            $this->assertSame([], PlayerData::summarize($after->root)['ender']);
            $this->assertCount($count, PlayerData::summarize($after->root)['inventory']);
        }

        // A file without the tag is left alone.
        $root = Tag::compound(['a' => Tag::byte(1)]);
        PlayerEdits::clearEnderChest($root);
        $this->assertSame(['a'], array_keys($root->value));
    }

    public function test_clear_effects_in_both_names(): void
    {
        $after = $this->edit(PlayerFixtures::legacy(), fn (Tag $r) => PlayerEdits::clearEffects($r), ['ActiveEffects']);
        $this->assertNull($after->root->get('ActiveEffects'));
        $this->assertSame([], PlayerData::summarize($after->root)['effects']);

        $after = $this->edit(PlayerFixtures::modern(), fn (Tag $r) => PlayerEdits::clearEffects($r), ['active_effects']);
        $this->assertNull($after->root->get('active_effects'));
    }

    public function test_game_mode(): void
    {
        $after = $this->edit(PlayerFixtures::legacy(), fn (Tag $r) => PlayerEdits::setGameMode($r, 3), ['playerGameType']);
        $this->assertSame(['id' => 3, 'name' => 'Spectator'], PlayerData::summarize($after->root)['game_mode']);
        $this->assertSame(Tag::INT, $after->root->get('playerGameType')->type);
        $this->assertSame(-1, $after->root->get('previousPlayerGameType')->value);

        foreach ([-1, 4, 99] as $mode) {
            try {
                PlayerEdits::setGameMode(Tag::compound(), $mode);
                $this->fail('Expected an error.');
            } catch (PlayerEditException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_teleport(): void
    {
        $after = $this->edit(PlayerFixtures::legacy(), fn (Tag $r) => PlayerEdits::teleport($r, 10.5, 70.0, -3.25, 'minecraft:the_nether'), ['Pos', 'Dimension', 'FallDistance', 'Motion']);
        $data = PlayerData::summarize($after->root);

        $this->assertSame(10.5, $data['position']['x']);
        $this->assertSame(70.0, $data['position']['y']);
        $this->assertSame(-3.25, $data['position']['z']);
        // They keep facing the same way.
        $this->assertSame(90.0, $data['position']['yaw']);
        $this->assertSame('The Nether', $data['dimension']['name']);
        $this->assertSame(Tag::DOUBLE, $after->root->get('Pos')->elementType);
        $this->assertSame(Tag::FLOAT, $after->root->get('FallDistance')->type);
        $this->assertSame(0.0, $after->root->get('FallDistance')->value);
        $this->assertSame([0.0, 0.0, 0.0], $after->root->get('Motion')->value);

        // 1.21.5: no vehicle, fall_distance as a double.
        $after = $this->edit(PlayerFixtures::modern(), fn (Tag $r) => PlayerEdits::teleport($r, 0, 64, 0, 'minecraft:overworld'), ['Pos', 'Dimension', 'RootVehicle', 'fall_distance', 'Motion']);
        $this->assertNull($after->root->get('RootVehicle'));
        $this->assertSame(Tag::DOUBLE, $after->root->get('fall_distance')->type);
        $this->assertSame(0.0, $after->root->get('fall_distance')->value);
    }

    public function test_teleport_into_a_modded_dimension_and_an_old_save(): void
    {
        $after = $this->edit(PlayerFixtures::components(), fn (Tag $r) => PlayerEdits::teleport($r, 1, 2, 3, 'twilightforest:twilight_forest'), ['Pos', 'Dimension', 'fall_distance', 'Motion']);
        $this->assertSame('twilightforest:twilight_forest', $after->root->get('Dimension')->text());

        // Before 1.16 the dimension is a number.
        $root = Tag::compound(['Dimension' => Tag::int(0), 'Pos' => Tag::list(Tag::DOUBLE, [0.0, 0.0, 0.0])]);
        PlayerEdits::teleport($root, 5, 6, 7, 'minecraft:the_nether');
        $this->assertSame(-1, $root->get('Dimension')->value);
        $this->assertSame(Tag::INT, $root->get('Dimension')->type);

        $this->expectException(PlayerEditException::class);
        PlayerEdits::teleport($root, 5, 6, 7, 'mod:other');
    }

    public function test_teleport_rejects_bad_input_and_changes_nothing(): void
    {
        $cases = [
            [NAN, 64.0, 0.0, 'minecraft:overworld'],
            [INF, 64.0, 0.0, 'minecraft:overworld'],
            [0.0, 64.0, 30000001.0, 'minecraft:overworld'],
            [-30000001.0, 64.0, 0.0, 'minecraft:overworld'],
            [0.0, 5000.0, 0.0, 'minecraft:overworld'],
            [0.0, -2049.0, 0.0, 'minecraft:overworld'],
            [0.0, 64.0, 0.0, 'Overworld'],
            [0.0, 64.0, 0.0, 'minecraft:'],
            [0.0, 64.0, 0.0, "minecraft:over\nworld"],
            [0.0, 64.0, 0.0, '../x:y'],
            [0.0, 64.0, 0.0, ''],
        ];

        foreach ($cases as [$x, $y, $z, $dimension]) {
            $document = PlayerFixtures::legacy();
            $before = Writer::writeDocument($document);
            try {
                PlayerEdits::teleport($document->root, $x, $y, $z, $dimension);
                $this->fail('Expected an error for '.json_encode([$x, $y, $z, $dimension]));
            } catch (PlayerEditException) {
                $this->assertSame($before, Writer::writeDocument($document));
            }
        }

        // The edges are fine.
        PlayerEdits::teleport(Tag::compound(), -30000000.0, -2048.0, 30000000.0, 'minecraft:overworld');
        PlayerEdits::teleport(Tag::compound(), 0.0, 4064.0, 0.0, 'minecraft:overworld');
        $this->addToAssertionCount(2);
    }

    public function test_world_spawn_from_level_dat(): void
    {
        $old = PlayerEdits::worldSpawn(PlayerFixtures::level(false)->root);
        $this->assertSame(['x' => 12.5, 'y' => 71.0, 'z' => -33.5, 'dimension' => 'minecraft:overworld'], $old);

        $new = PlayerEdits::worldSpawn(PlayerFixtures::level(true)->root);
        $this->assertSame(['x' => 12.5, 'y' => 71.0, 'z' => -33.5, 'dimension' => 'minecraft:overworld'], $new);

        $this->assertNull(PlayerEdits::worldSpawn(Tag::compound()));
        $this->assertNull(PlayerEdits::worldSpawn(Tag::compound(['Data' => Tag::compound(['SpawnX' => Tag::int(1)])])));

        $after = $this->edit(PlayerFixtures::modern(), function (Tag $r) use ($old): void {
            PlayerEdits::teleport($r, $old['x'], $old['y'], $old['z'], $old['dimension']);
        }, ['Pos', 'Dimension', 'RootVehicle', 'fall_distance', 'Motion']);
        $this->assertSame(12.5, PlayerData::summarize($after->root)['position']['x']);
    }

    public function test_an_uncompressed_file_stays_uncompressed(): void
    {
        $document = PlayerFixtures::modern();
        $document->gzip = false;
        $after = $this->edit($document, fn (Tag $r) => PlayerEdits::clearEffects($r), ['active_effects']);

        $this->assertFalse($after->gzip);
    }

    public function test_every_edit_leaves_unrelated_nested_data_alone(): void
    {
        // Mod data and tags this extension knows nothing about survive byte for byte.
        $document = PlayerFixtures::modern();
        $document->root->put('ForgeData', Tag::compound(['x' => Tag::longArray([PHP_INT_MAX, PHP_INT_MIN]), 'name' => Tag::rawString("\xED\xA0\x80"), 'f' => Tag::float(0.5)]));
        $document->root->put('empty', Tag::list(Tag::COMPOUND));

        $original = Writer::writeDocument(Reader::readFile(Writer::writeFile($document)));
        foreach ([
            fn (Tag $r) => PlayerEdits::clearEnderChest($r),
            fn (Tag $r) => PlayerEdits::clearEffects($r),
            fn (Tag $r) => PlayerEdits::setGameMode($r, 0),
            fn (Tag $r) => PlayerEdits::removeItem($r, 'inventory', 0),
        ] as $change) {
            $copy = Reader::readFile(Writer::writeFile($document));
            $change($copy->root);
            $after = Reader::readFile(Writer::writeFile($copy));

            $this->assertEquals($document->root->get('ForgeData'), $after->root->get('ForgeData'));
            $this->assertEquals($document->root->get('empty'), $after->root->get('empty'));
            $this->assertEquals($document->root->get('UUID'), $after->root->get('UUID'));
        }

        $this->assertSame($original, Writer::writeDocument(Reader::readFile(Writer::writeFile($document))));
    }

    public function test_teleport_to_another_dimension_drops_the_world_id(): void
    {
        $root = Tag::compound([
            'Dimension' => Tag::string('minecraft:overworld'),
            'WorldUUIDLeast' => Tag::long(123),
            'WorldUUIDMost' => Tag::long(456),
            'Pos' => Tag::list(Tag::DOUBLE, [0.0, 0.0, 0.0]),
        ]);
        PlayerEdits::teleport($root, 1, 70, 1, 'minecraft:the_nether');
        $this->assertFalse($root->has('WorldUUIDLeast'));
        $this->assertFalse($root->has('WorldUUIDMost'));
        $this->assertSame('minecraft:the_nether', $root->get('Dimension')->text());
    }

    public function test_teleport_inside_the_dimension_keeps_the_world_id(): void
    {
        $root = Tag::compound([
            'Dimension' => Tag::string('minecraft:overworld'),
            'WorldUUIDLeast' => Tag::long(123),
            'WorldUUIDMost' => Tag::long(456),
            'Pos' => Tag::list(Tag::DOUBLE, [0.0, 0.0, 0.0]),
        ]);
        PlayerEdits::teleport($root, 1, 70, 1, 'minecraft:overworld');
        $this->assertSame(123, $root->get('WorldUUIDLeast')->value);

        // An old save with a numeric dimension counts the same way.
        $old = Tag::compound(['Dimension' => Tag::int(0), 'WorldUUIDLeast' => Tag::long(1), 'WorldUUIDMost' => Tag::long(2), 'Pos' => Tag::list(Tag::DOUBLE, [0.0, 0.0, 0.0])]);
        PlayerEdits::teleport($old, 1, 70, 1, 'minecraft:overworld');
        $this->assertTrue($old->has('WorldUUIDLeast'));
        PlayerEdits::teleport($old, 1, 70, 1, 'minecraft:the_end');
        $this->assertFalse($old->has('WorldUUIDMost'));
    }
}
