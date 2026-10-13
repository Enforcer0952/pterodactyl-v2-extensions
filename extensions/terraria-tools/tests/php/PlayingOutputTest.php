<?php

namespace TerrariaTools\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TerrariaTools\Support\ConsoleDelta;
use TerrariaTools\Support\PlayingOutput;

final class PlayingOutputTest extends TestCase
{
    /** @return iterable<string, array{list<string>, list<string>|null}> */
    public static function answers(): iterable
    {
        yield 'none' => [['No players connected.'], []];
        yield 'none without dot' => [['No players connected'], []];
        yield 'one' => [['Anton (192.168.1.20:51234)', '1 player connected.'], ['Anton']];
        yield 'many' => [['Anton (192.168.1.20:51234)', 'Koen (192.168.1.21:49822)', '2 players connected.'], ['Anton', 'Koen']];
        yield 'name with spaces and brackets' => [['Koen (the Great) (10.0.0.1:1234)', '1 player connected.'], ['Koen (the Great)']];
        yield 'ipv6 address' => [['Anton ([::1]:5555)', '1 player connected.'], ['Anton']];
        yield 'prompt prefix' => [[': Anton (127.0.0.1:1)', ': 1 player connected.'], ['Anton']];
        yield 'colour codes and CR' => [["\x1B[0mAnton (127.0.0.1:1)\r", "\x1B[0m1 player connected.\r"], ['Anton']];
        yield 'blank line between' => [['Anton (127.0.0.1:1)', '', '1 player connected.'], ['Anton']];
        yield 'noise before the answer' => [['Sam has joined.', 'Anton (127.0.0.1:1)', 'Koen (127.0.0.1:2)', '2 players connected.'], ['Anton', 'Koen']];
        yield 'case' => [['NO PLAYERS CONNECTED.'], []];
        yield 'colon in name' => [[':D (127.0.0.1:1)', '1 player connected.'], [':D']];
        yield 'nothing yet' => [['Server started', 'Sam has joined.'], null];
        yield 'empty' => [[], null];
        yield 'summary without its players' => [['2 players connected.'], null];
        yield 'fewer players than the summary says' => [['Anton (127.0.0.1:1)', '2 players connected.'], null];
        yield 'a line that is not a player' => [['Server started', '1 player connected.'], null];
    }

    /**
     * @param  list<string>  $lines
     * @param  list<string>|null  $expected
     */
    #[DataProvider('answers')]
    public function test_parses_the_answer(array $lines, ?array $expected): void
    {
        $this->assertSame($expected, PlayingOutput::parse($lines));
    }

    public function test_uses_the_newest_answer(): void
    {
        $lines = ['Anton (127.0.0.1:1)', '1 player connected.', 'No players connected.'];
        $this->assertSame([], PlayingOutput::parse($lines));

        $lines = ['No players connected.', 'Koen (127.0.0.1:2)', '1 player connected.'];
        $this->assertSame(['Koen'], PlayingOutput::parse($lines));
    }

    public function test_a_player_named_like_a_summary_is_not_counted_twice(): void
    {
        $lines = ['No players connected. (127.0.0.1:1)', '1 player connected.'];
        $this->assertSame(['No players connected.'], PlayingOutput::parse($lines));
    }

    public function test_delta_drops_what_was_already_in_the_log(): void
    {
        $before = ['a', 'No players connected.', 'b'];
        $this->assertSame([], ConsoleDelta::since($before, $before));
        $this->assertSame(['x'], ConsoleDelta::since($before, [...$before, 'x']));
        // The log scrolled: oldest lines fell off.
        $this->assertSame(['x', 'y'], ConsoleDelta::since($before, ['No players connected.', 'b', 'x', 'y']));
        // Nothing in common: all new.
        $this->assertSame(['p', 'q'], ConsoleDelta::since($before, ['p', 'q']));
        $this->assertSame(['p'], ConsoleDelta::since([], ['p']));
    }

    public function test_an_old_answer_is_not_mistaken_for_the_new_one(): void
    {
        $before = ['Anton (127.0.0.1:1)', '1 player connected.'];
        $after = [...$before, 'Sam has joined.'];

        $this->assertNull(PlayingOutput::parse(ConsoleDelta::since($before, $after)));

        $after[] = 'No players connected.';
        $this->assertSame([], PlayingOutput::parse(ConsoleDelta::since($before, $after)));
    }

    public function test_an_identical_answer_twice_is_seen_as_new(): void
    {
        $before = ['No players connected.'];
        $after = ['No players connected.', 'No players connected.'];

        $this->assertSame(['No players connected.'], ConsoleDelta::since($before, $after));
        $this->assertSame([], PlayingOutput::parse(ConsoleDelta::since($before, $after)));
    }
}
