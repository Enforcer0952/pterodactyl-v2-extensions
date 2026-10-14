<?php

use PHPUnit\Framework\TestCase;
use ValheimTools\Support\LogParser;

class LogParserTest extends TestCase
{
    private function events(string $file): array
    {
        $parser = new LogParser;

        return [$parser, $parser->feed(file($file, FILE_IGNORE_NEW_LINES) ?: [])];
    }

    public function test_session_fixture(): void
    {
        [$parser, $events] = $this->events(__DIR__.'/fixtures/console-session.log');

        $this->assertSame(['start', 'join', 'join', 'join', 'leave'], array_column($events, 'type'));
        $this->assertSame('Big Bob', $events[1]['name']);
        $this->assertSame('76561198051014133', $events[1]['id']);
        $this->assertTrue($events[1]['steam']);

        // Crossplay transport resolved to the Steam ID through the PlayFab line.
        $this->assertSame('Ragnar', $events[2]['name']);
        $this->assertSame('76561198000000001', $events[2]['id']);

        // A player on another platform has no ID the lists take.
        $this->assertSame('Freya', $events[3]['name']);
        $this->assertFalse($events[3]['steam']);

        $this->assertSame('Ragnar', $events[4]['name']);
        $this->assertSame(['-438505887' => 'Big Bob', '987654321' => 'Freya'], $parser->roster());
    }

    public function test_death_line_is_not_a_join(): void
    {
        $parser = new LogParser;
        $this->assertNull($parser->line('Got character ZDOID from Ragnar : 0:0'));
        $this->assertSame([], $parser->roster());
    }

    public function test_respawn_does_not_duplicate(): void
    {
        $parser = new LogParser;
        $parser->line('Got handshake from client 76561198051014133');
        $this->assertSame('join', $parser->line('Got character ZDOID from Bob : 111:1')['type']);
        $this->assertNull($parser->line('Got character ZDOID from Bob : 111:1'));
        $this->assertCount(1, $parser->roster());
    }

    public function test_server_start_clears_roster(): void
    {
        $parser = new LogParser;
        $parser->line('Got character ZDOID from Bob : 111:1');
        $this->assertSame('start', $parser->line('Game server connected')['type']);
        $this->assertSame([], $parser->roster());
    }

    public function test_leave_of_unknown_owner_is_ignored(): void
    {
        $parser = new LogParser;
        $this->assertNull($parser->line('Destroying abandoned non persistent zdo 5:5 owner 5'));
    }

    public function test_clean_strips_colours_and_controls(): void
    {
        $this->assertSame('Game server connected', LogParser::clean("\x1b[32m10/14/2026 18:02:11: Game server connected\x1b[0m\r"));
    }

    public function test_closing_socket_drops_a_pending_id(): void
    {
        $parser = new LogParser;
        $parser->line('Got handshake from client 76561198051014133');
        $parser->line('Closing socket 76561198051014133');
        $event = $parser->line('Got character ZDOID from Eve : 222:1');
        $this->assertNull($event['id']);
    }

    public function test_names_with_odd_characters_are_kept_as_data(): void
    {
        $parser = new LogParser;
        $event = $parser->line('Got character ZDOID from <b>"Bob" : 1;rm -rf : 333:1');
        $this->assertSame('<b>"Bob" : 1;rm -rf', $event['name']);
    }

    public function test_players_carry_their_ids(): void
    {
        [$parser] = $this->events(__DIR__.'/fixtures/console-session.log');
        $players = $parser->players();
        $this->assertSame('76561198051014133', $players[0]['id']);
        $this->assertTrue($players[0]['steam']);
        $this->assertSame('Xbox_2535400000000001', $players[1]['id']);
        $this->assertFalse($players[1]['steam']);
    }

    public function test_state_can_be_carried_between_reads(): void
    {
        $lines = file(__DIR__.'/fixtures/console-session.log', FILE_IGNORE_NEW_LINES);
        $first = new LogParser;
        $first->feed(array_slice($lines, 0, 6));
        $second = LogParser::import(json_decode(json_encode($first->export()), true));
        $second->feed(array_slice($lines, 6));

        $whole = new LogParser;
        $whole->feed($lines);
        $this->assertSame($whole->players(), $second->players());
    }

    public function test_import_ignores_junk(): void
    {
        $parser = LogParser::import(['handles' => 'x', 'roster' => ['1' => ['a']], 'pending' => 5, 'ids' => [1 => 'V_1']]);
        $this->assertSame([], $parser->roster());
    }
}
