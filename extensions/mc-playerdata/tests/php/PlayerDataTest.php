<?php

require_once __DIR__.'/PlayerFixtures.php';

use McPlayerData\Support\Items;
use McPlayerData\Support\Names;
use McPlayerData\Support\Nbt\Reader;
use McPlayerData\Support\Nbt\Tag;
use McPlayerData\Support\Nbt\Writer;
use McPlayerData\Support\PlayerData;
use McPlayerData\Support\TextComponent;
use PHPUnit\Framework\TestCase;

final class PlayerDataTest extends TestCase
{
    /** Through the file format, as the screen reads it. */
    private function summary(\McPlayerData\Support\Nbt\NbtDocument $document): array
    {
        return PlayerData::summarize(Reader::readFile(Writer::writeFile($document))->root);
    }

    /** @param list<array<string, mixed>> $items */
    private function bySlot(array $items, int|string $slot): ?array
    {
        foreach ($items as $item) {
            if ($item['slot'] === $slot) {
                return $item;
            }
        }

        return null;
    }

    public function test_legacy_format(): void
    {
        $data = $this->summary(PlayerFixtures::legacy());

        $sword = $this->bySlot($data['inventory'], 0);
        $this->assertSame('minecraft:diamond_sword', $sword['id']);
        $this->assertSame('Diamond Sword', $sword['name']);
        $this->assertSame('Excalibur', $sword['custom_name']);
        $this->assertSame(120, $sword['damage']);
        $this->assertSame([
            ['id' => 'minecraft:sharpness', 'name' => 'Sharpness', 'level' => 5],
            ['id' => 'minecraft:looting', 'name' => 'Looting', 'level' => 3],
        ], $sword['enchantments']);
        $this->assertSame(['Pulled from a stone'], $sword['lore']);
        $this->assertSame('legacy', $sword['format']);
        $this->assertFalse($sword['modded']);

        // Hotbar 0-8, main 9-35, in slot order.
        $this->assertSame([0, 1, 8, 9, 35], array_column($data['inventory'], 'slot'));
        $this->assertSame(200, $this->bySlot($data['inventory'], 8)['count']);

        $modded = $this->bySlot($data['inventory'], 9);
        $this->assertTrue($modded['modded']);
        $this->assertSame('create:brass_ingot', $modded['name']);

        // Armor and the off hand sit in Inventory slots 100-103 and -106.
        $this->assertSame('minecraft:iron_helmet', $data['equipment']['head']['id']);
        $this->assertSame('minecraft:iron_boots', $data['equipment']['feet']['id']);
        $this->assertSame('Shield', $data['equipment']['offhand']['name']);
        $this->assertNull($data['equipment']['chest']);
        $this->assertNull($data['equipment']['legs']);

        // Anything else is listed, not lost.
        $this->assertSame([40], array_column($data['other'], 'slot'));

        $this->assertSame([0, 26], array_column($data['ender'], 'slot'));
        $this->assertSame(9, $data['ender'][1]['count']);

        $this->assertSame(['x' => 100.5, 'y' => 64.0, 'z' => -200.25, 'yaw' => 90.0, 'pitch' => 15.5], $data['position']);
        $this->assertSame(['id' => 'minecraft:overworld', 'name' => 'Overworld'], $data['dimension']);
        $this->assertSame(['x' => 10, 'y' => 70, 'z' => -20, 'dimension' => ['id' => 'minecraft:overworld', 'name' => 'Overworld'], 'forced' => true], $data['spawn']);
        $this->assertSame(['level' => 30, 'progress' => 0.5, 'total' => 1395], $data['xp']);
        $this->assertSame(18.5, $data['health']);
        $this->assertSame(20.0, $data['max_health']);
        $this->assertSame(['level' => 15, 'saturation' => 2.5], $data['food']);
        $this->assertSame(['id' => 0, 'name' => 'Survival'], $data['game_mode']);
        $this->assertSame(2, $data['selected_slot']);
        $this->assertSame(3465, $data['data_version']);

        $this->assertCount(2, $data['effects']);
        $this->assertSame(['id' => 'minecraft:speed', 'name' => 'Speed', 'level' => 2, 'ticks' => 1200, 'infinite' => false, 'seconds' => 60], $data['effects'][0]);
        $this->assertSame('Night Vision', $data['effects'][1]['name']);
        $this->assertTrue($data['effects'][1]['infinite']);
        $this->assertNull($data['effects'][1]['seconds']);
    }

    public function test_components_format_before_1_21_5(): void
    {
        $data = $this->summary(PlayerFixtures::components());

        $axe = $this->bySlot($data['inventory'], 4);
        $this->assertSame('components', $axe['format']);
        $this->assertSame('Lumberjack', $axe['custom_name']);
        $this->assertSame(33, $axe['damage']);
        $this->assertSame(2031, $axe['max_damage']);
        $this->assertSame(['Chops fast'], $axe['lore']);
        $this->assertSame([
            ['id' => 'minecraft:efficiency', 'name' => 'Efficiency', 'level' => 5],
            ['id' => 'minecraft:unbreaking', 'name' => 'Unbreaking', 'level' => 3],
        ], $axe['enchantments']);

        $box = $this->bySlot($data['inventory'], 10);
        $this->assertSame([
            ['name' => 'Diamond', 'id' => 'minecraft:diamond', 'count' => 32],
            ['name' => 'Stick', 'id' => 'minecraft:stick', 'count' => 5],
        ], $box['contents']);

        // This version still keeps armor in Inventory slots 100-103.
        $this->assertSame('minecraft:diamond_boots', $data['equipment']['feet']['id']);
        $this->assertSame('minecraft:diamond_chestplate', $data['equipment']['chest']['id']);

        $this->assertSame(['id' => 'minecraft:the_nether', 'name' => 'The Nether'], $data['dimension']);
        $this->assertSame(['id' => 1, 'name' => 'Creative'], $data['game_mode']);
        $this->assertSame(40.0, $data['max_health']);
        $this->assertNull($data['spawn']);
        $this->assertSame('Regeneration', $data['effects'][0]['name']);
        $this->assertSame(30, $data['effects'][0]['seconds']);
    }

    public function test_modern_format_with_an_equipment_compound(): void
    {
        $data = $this->summary(PlayerFixtures::modern());

        $bow = $this->bySlot($data['inventory'], 1);
        $this->assertSame('Skyfall', $bow['custom_name']);
        $this->assertSame(['Long range'], $bow['lore']);
        $this->assertSame([
            ['id' => 'minecraft:power', 'name' => 'Power', 'level' => 4],
            ['id' => 'minecraft:infinity', 'name' => 'Infinity', 'level' => 1],
        ], $bow['enchantments']);

        $book = $this->bySlot($data['inventory'], 2);
        $this->assertSame('Plain literal name', $book['custom_name']);
        $this->assertSame([], $book['enchantments']);
        $this->assertSame('Mending', $book['stored_enchantments'][0]['name']);

        $this->assertSame('minecraft:turtle_helmet', $data['equipment']['head']['id']);
        $this->assertSame('head', $data['equipment']['head']['slot']);
        $this->assertSame(100, $data['equipment']['chest']['damage']);
        $this->assertSame('minecraft:totem_of_undying', $data['equipment']['offhand']['id']);
        $this->assertNull($data['equipment']['legs']);
        $this->assertSame(1, $data['equipment']['head']['count']);

        $this->assertSame('mymod:ruby_sword', $this->bySlot($data['inventory'], 5)['name']);
        $this->assertSame(['x' => -100, 'y' => 65, 'z' => 300, 'dimension' => ['id' => 'minecraft:overworld', 'name' => 'Overworld'], 'forced' => false], $data['spawn']);
        $this->assertSame(['id' => 'minecraft:the_end', 'name' => 'The End'], $data['dimension']);
        $this->assertSame(['id' => 2, 'name' => 'Adventure'], $data['game_mode']);
        $this->assertSame(3, $data['effects'][0]['level']);
        $this->assertSame('mymod:rage', $data['effects'][1]['name']);
        $this->assertSame([13], array_column($data['ender'], 'slot'));
    }

    public function test_an_empty_or_minimal_file_does_not_break_the_summary(): void
    {
        $data = PlayerData::summarize(Tag::compound());

        $this->assertSame([], $data['inventory']);
        $this->assertSame([], $data['ender']);
        $this->assertNull($data['position']);
        $this->assertNull($data['dimension']);
        $this->assertNull($data['spawn']);
        $this->assertNull($data['game_mode']);
        $this->assertSame([], $data['effects']);
        $this->assertNull($data['health']);
        $this->assertSame(['head' => null, 'chest' => null, 'legs' => null, 'feet' => null, 'offhand' => null], $data['equipment']);
    }

    public function test_odd_values_are_tolerated(): void
    {
        $root = Tag::compound([
            'Pos' => Tag::list(Tag::DOUBLE, [1.0]),
            'Dimension' => Tag::int(-1),
            'playerGameType' => Tag::int(9),
            'Inventory' => Tag::list(Tag::COMPOUND, [
                Tag::compound(['id' => Tag::string('minecraft:stone'), 'Count' => Tag::byte(1)]),
                Tag::compound(['Slot' => Tag::byte(2)]),
                Tag::compound(['Slot' => Tag::byte(3), 'id' => Tag::string('minecraft:air'), 'Count' => Tag::byte(1)]),
                Tag::compound(['Slot' => Tag::byte(4), 'id' => Tag::short(5), 'Count' => Tag::byte(-1)]),
            ]),
            'ActiveEffects' => Tag::list(Tag::COMPOUND, [Tag::compound(['Duration' => Tag::int(5)])]),
        ]);
        $data = PlayerData::summarize($root);

        $this->assertNull($data['position']);
        $this->assertSame('minecraft:the_nether', $data['dimension']['id']);
        $this->assertSame('Unknown', $data['game_mode']['name']);
        // No slot, no id, air: skipped. A numeric id and a count above 127 still show.
        $this->assertSame([4], array_column($data['inventory'], 'slot'));
        $this->assertSame(255, $data['inventory'][0]['count']);
        $this->assertSame('unknown:item_5', $data['inventory'][0]['id']);
        $this->assertSame([], $data['effects']);
    }

    public function test_item_names_and_text_components(): void
    {
        $this->assertSame('Diamond Sword', Names::readable('minecraft:diamond_sword'));
        $this->assertSame('Diamond Sword', Names::readable('diamond_sword'));
        $this->assertSame('Music Disc 13', Names::readable('minecraft:music_disc_13'));
        $this->assertSame('create:brass_ingot', Names::readable('create:brass_ingot'));
        $this->assertSame('The End', Names::dimension('minecraft:the_end'));
        $this->assertSame('mod:space', Names::dimension('mod:space'));

        $this->assertSame('Hi there', TextComponent::fromString('{"text":"Hi ","extra":[{"text":"there"}]}'));
        $this->assertSame('a b', TextComponent::fromString('["a ", {"text":"b"}]'));
        $this->assertSame('quoted', TextComponent::fromString('"quoted"'));
        $this->assertSame('item.minecraft.stick', TextComponent::fromString('{"translate":"item.minecraft.stick"}'));
        $this->assertSame('{not json', TextComponent::fromString('{not json'));
        $this->assertSame('Plain', TextComponent::fromString('Plain'));

        $this->assertSame('Red Bold', TextComponent::clean("§cRed §lBold"));
        $this->assertNull(TextComponent::clean("\x01\x02  "));
        $this->assertSame(200, mb_strlen(TextComponent::clean(str_repeat('é', 500))));
        // A deeply nested component stops instead of recursing forever.
        $deep = '{"text":"x","extra":['.str_repeat('{"text":"y","extra":[', 6).str_repeat(']}', 6).']}';
        $this->assertStringStartsWith('x', TextComponent::fromString($deep));
    }

    public function test_item_counts_in_both_layouts(): void
    {
        $new = Items::describe(Tag::compound(['id' => Tag::string('minecraft:arrow'), 'count' => Tag::int(99)]));
        $old = Items::describe(Tag::compound(['id' => Tag::string('minecraft:arrow'), 'Count' => Tag::byte(-56)]));
        $none = Items::describe(Tag::compound(['id' => Tag::string('minecraft:arrow')]));

        $this->assertSame(99, $new['count']);
        $this->assertSame(200, $old['count']);
        $this->assertSame(1, $none['count']);
        $this->assertNull(Items::describe(Tag::string('x')));
        $this->assertNull(Items::describe(Tag::compound(['id' => Tag::string('')])));
    }

    public function test_the_whole_pipeline_survives_a_write_and_read(): void
    {
        foreach ([PlayerFixtures::legacy(), PlayerFixtures::components(), PlayerFixtures::modern()] as $document) {
            $bytes = Writer::writeFile($document);
            $read = Reader::readFile($bytes);

            $this->assertTrue($read->gzip);
            $this->assertSame(Writer::writeDocument($document), Writer::writeDocument($read));
            $this->assertSame(PlayerData::summarize($document->root), PlayerData::summarize($read->root));
        }
    }
}
