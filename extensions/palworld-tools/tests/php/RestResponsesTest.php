<?php

use PalworldTools\Support\RestResponses;
use PHPUnit\Framework\TestCase;

final class RestResponsesTest extends TestCase
{
    // Shapes from https://docs.palworldgame.com/category/rest-api
    private const string PLAYERS = '{"players":[{"name":"Zed","accountName":"zed","playerId":"AFAFD830000000000000000000000000","userId":"steam_00000000000000001","ip":"127.0.0.1","ping":3.14,"location_x":123.45,"location_y":-67.89,"level":12,"building_count":119},{"name":"alice","accountName":"alice","playerId":"B","userId":"steam_00000000000000002","ping":40,"location_x":1,"location_y":2,"level":3}]}';

    public function testPlayers(): void
    {
        $players = RestResponses::players(json_decode(self::PLAYERS, true));

        $this->assertCount(2, $players);
        $this->assertSame('alice', $players[0]['name'], 'sorted by name');
        $this->assertSame('steam_00000000000000001', $players[1]['user_id']);
        $this->assertSame(3, $players[1]['ping']);
        $this->assertSame(123, $players[1]['x']);
        $this->assertSame(-68, $players[1]['y']);
        $this->assertSame(12, $players[1]['level']);
        $this->assertSame(119, $players[1]['buildings']);
        $this->assertNull($players[0]['buildings']);
        $this->assertArrayNotHasKey('ip', $players[1]);
    }

    public function testPlayersToleratesOddData(): void
    {
        $this->assertSame([], RestResponses::players(null));
        $this->assertSame([], RestResponses::players('text'));
        $this->assertSame([], RestResponses::players(['players' => 'x']));
        $this->assertSame([], RestResponses::players(['players' => []]));
        $this->assertSame([], RestResponses::players(['players' => [['name' => 'no id'], 'string', ['userId' => 'bad id with spaces']]]));

        $one = RestResponses::players(['players' => [['userId' => 'steam_1', 'name' => "Evil\nName"]]]);
        $this->assertSame('Evil Name', $one[0]['name']);
        $this->assertNull($one[0]['level']);

        $fallback = RestResponses::players(['players' => [['userId' => 'steam_9']]]);
        $this->assertSame('steam_9', $fallback[0]['name']);
    }

    public function testInfo(): void
    {
        $info = RestResponses::info(json_decode('{"version":"v0.1.5.0","servername":"Palworld example Server","description":"This is a Palworld server.","worldguid":"A7E97BAA767DB9029EF013BB71E993A0"}', true));

        $this->assertSame('v0.1.5.0', $info['version']);
        $this->assertSame('Palworld example Server', $info['name']);
        $this->assertSame(['version' => null, 'name' => null, 'description' => null], RestResponses::info('nope'));
    }

    public function testMetrics(): void
    {
        $metrics = RestResponses::metrics(json_decode('{"serverfps":57,"currentplayernum":10,"serverframetime":16.7671,"maxplayernum":32,"uptime":3600,"basecampnum":32,"days":1}', true));

        $this->assertSame(['fps' => 57, 'uptime' => 3600, 'days' => 1, 'players' => 10, 'max_players' => 32, 'base_camps' => 32], $metrics);
        $this->assertSame(['fps' => null, 'uptime' => null, 'days' => null, 'players' => null, 'max_players' => null, 'base_camps' => null], RestResponses::metrics([]));
        $this->assertSame(5, RestResponses::metrics(['serverfps' => '5'])['fps']);
    }
}
