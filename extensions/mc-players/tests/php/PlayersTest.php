<?php

namespace McPlayers\Tests;

use McPlayers\Support\BrokenListFile;
use McPlayers\Support\ListFile;
use McPlayers\Support\ListOutput;
use McPlayers\Support\Stats;
use McPlayers\Support\Validates;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PlayersTest extends TestCase
{
    public function test_offline_uuid_matches_the_server(): void
    {
        // Notch's offline UUID is the widely published one; jeb_'s is Java's UUID.nameUUIDFromBytes.
        $this->assertSame('b50ad385-829d-3141-a216-7e7d7539ba7f', Validates::offlineUuid('Notch'));
        $this->assertSame('a762f560-4fce-3236-812a-b80efff0b62b', Validates::offlineUuid('jeb_'));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function names(): iterable
    {
        yield 'normal' => ['Steve', true];
        yield 'underscore' => ['jeb_', true];
        yield 'bedrock' => ['.BedrockGuy', true];
        yield 'too long' => ['abcdefghijklmnopq', false];
        yield 'space' => ['Steve Smith', false];
        yield 'selector' => ['@a', false];
        yield 'trailing newline' => ["Steve\n", false];
        yield 'second command' => ['a op x', false];
    }

    #[DataProvider('names')]
    public function test_names(string $name, bool $valid): void
    {
        $this->assertSame($valid, preg_match(Validates::NAME, $name) === 1);
    }

    public function test_reasons_cannot_carry_a_second_command(): void
    {
        $this->assertSame('griefing op Evil', Validates::reason("griefing\nop Evil"));
        $this->assertSame('a b', Validates::reason("a\u{2028}b"));
        $this->assertSame('a b', Validates::reason("a\u{0085}b"));
        $this->assertNull(Validates::reason("  \t "));
        $this->expectException(\InvalidArgumentException::class);
        Validates::reason("bad \xC3\x28 utf8");
    }

    public function test_broken_list_files_are_never_read_as_empty(): void
    {
        $this->assertSame([], ListFile::decode(null, '/ops.json'));
        $this->assertSame([], ListFile::decode("\n", '/ops.json'));

        foreach (['[{"name":"Steve",}]', '{"name":"Steve"}', 'null', '[1,'] as $broken) {
            try {
                ListFile::decode($broken, '/whitelist.json');
                $this->fail('Accepted '.$broken);
            } catch (BrokenListFile $exception) {
                $this->assertStringContainsString('whitelist.json', $exception->getMessage());
            }
        }
    }

    public function test_upsert_replaces_by_uuid_or_name_and_keeps_fields(): void
    {
        $rows = [
            ['uuid' => 'u1', 'name' => 'Steve', 'level' => 2, 'bypassesPlayerLimit' => true],
            ['uuid' => 'u2', 'name' => 'Alex', 'level' => 4, 'bypassesPlayerLimit' => false],
        ];

        $result = ListFile::upsert($rows, ['uuid' => 'u1-new', 'name' => 'steve', 'level' => 4, 'bypassesPlayerLimit' => false], 'uuid', ['level', 'bypassesPlayerLimit']);

        $this->assertCount(2, $result);
        $this->assertSame(['uuid' => 'u1-new', 'name' => 'steve', 'level' => 2, 'bypassesPlayerLimit' => true], $result[1]);
    }

    public function test_remove_reports_when_nothing_matched(): void
    {
        $rows = [['ip' => '1.2.3.4'], ['ip' => '5.6.7.8']];
        $this->assertSame([['ip' => '5.6.7.8']], ListFile::remove($rows, 'ip', '1.2.3.4'));
        $this->assertNull(ListFile::remove($rows, 'ip', '9.9.9.9'));
        $this->assertTrue(ListFile::has([['name' => 'Old-Name!']], 'name', 'old-name!'));
    }

    public function test_cached_profile_uses_the_servers_own_uuid(): void
    {
        $cache = [
            ['name' => 'Steve', 'uuid' => '069A79F4-44E9-4726-A5BE-FCA90E38AAF5', 'expiresOn' => '2026-11-01 10:00:00 +0000'],
            ['name' => 'Broken', 'uuid' => 'not-a-uuid'],
        ];

        $this->assertSame(['uuid' => '069a79f4-44e9-4726-a5be-fca90e38aaf5', 'name' => 'Steve'], ListFile::cachedProfile($cache, 'steve'));
        $this->assertNull(ListFile::cachedProfile($cache, 'Broken'));
        $this->assertNull(ListFile::cachedProfile($cache, 'Nobody'));
    }

    public function test_list_answers_in_every_format(): void
    {
        $this->assertSame(
            ['count' => 2, 'max' => 20, 'players' => ['Alex', 'Steve']],
            ListOutput::parse(['[12:00:00 INFO]: There are 2 of a max of 20 players online: Steve, Alex']),
        );
        $this->assertSame(
            ['count' => 1, 'max' => 10, 'players' => ['Notch']],
            ListOutput::parse(['[12:00:00] [Server thread/INFO]: There are 1/10 players online:', '[12:00:00] [Server thread/INFO]: Notch']),
        );
        $this->assertSame(
            ['count' => 2, 'max' => 50, 'players' => ['Alex', 'Steve']],
            ListOutput::parse(['There are 2 out of maximum 50 players online.', 'default: [AFK]Steve, ~Alex']),
        );
    }

    public function test_chat_cannot_fake_the_player_list(): void
    {
        $lines = [
            '[12:00:00 INFO]: There are 1 of a max of 20 players online: Steve',
            '[12:00:01 INFO]: <Evil> There are 99 of a max of 100 players online: Fake1, Fake2',
        ];

        $this->assertSame(['Steve'], ListOutput::parse($lines)['players']);
    }

    public function test_command_replies(): void
    {
        $this->assertSame(
            ['failed' => true, 'message' => 'That player does not exist'],
            ListOutput::reply(['> op Nobody', '[12:00:00 INFO]: That player does not exist'], 'op Nobody'),
        );
        $this->assertSame(
            ['failed' => false, 'message' => 'Made Steve a server operator'],
            ListOutput::reply(['[12:00:00 INFO]: Made Steve a server operator'], 'op Steve'),
        );
        // Chat that happens to contain the words is ignored.
        $this->assertNull(ListOutput::reply(['<Steve> Could not believe it'], 'op Steve'));
    }

    public function test_stats_from_modern_and_old_files(): void
    {
        $modern = ['stats' => [
            'minecraft:custom' => ['minecraft:play_time' => 72000, 'minecraft:deaths' => 3, 'minecraft:mob_kills' => 40, 'minecraft:walk_one_cm' => 150000, 'minecraft:sprint_one_cm' => 50000, 'minecraft:jump' => 12],
            'minecraft:mined' => ['minecraft:stone' => 100, 'minecraft:dirt' => 23],
        ], 'DataVersion' => 3953];
        $this->assertSame(['play_seconds' => 3600, 'deaths' => 3, 'mob_kills' => 40, 'player_kills' => 0, 'distance_m' => 2000, 'blocks_mined' => 123, 'jumps' => 12], Stats::summarize($modern));

        // 1.13-1.16 named it play_one_minute (still ticks).
        $this->assertSame(1800, Stats::summarize(['stats' => ['minecraft:custom' => ['minecraft:play_one_minute' => 36000]]])['play_seconds']);

        $old = ['stat.playOneMinute' => 24000, 'stat.deaths' => 1, 'stat.walkOneCm' => 1000, 'stat.mineBlock.minecraft.stone' => 5, 'stat.mineBlock.minecraft.dirt' => 2];
        $this->assertSame(['play_seconds' => 1200, 'deaths' => 1, 'mob_kills' => 0, 'player_kills' => 0, 'distance_m' => 10, 'blocks_mined' => 7, 'jumps' => 0], Stats::summarize($old));
    }
}
