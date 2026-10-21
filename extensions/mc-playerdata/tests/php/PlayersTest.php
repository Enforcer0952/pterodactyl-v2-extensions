<?php

use McPlayerData\Support\MinecraftServer;
use McPlayerData\Support\Players;
use PHPUnit\Framework\TestCase;

final class PlayersTest extends TestCase
{
    public function test_parse_accepts_names_and_uuids_only(): void
    {
        $this->assertSame(['name' => 'Steve_1'], Players::parse('Steve_1'));
        $this->assertSame(['name' => '.BedrockGuy'], Players::parse(' .BedrockGuy '));
        $this->assertSame(['uuid' => '069a79f4-44e9-4726-a5be-fca90e38aaf5'], Players::parse('069A79F4-44E9-4726-A5BE-FCA90E38AAF5'));
        $this->assertSame(['uuid' => '069a79f4-44e9-4726-a5be-fca90e38aaf5'], Players::parse('069a79f444e94726a5befca90e38aaf5'));

        foreach (['', '../etc/passwd', 'a/b', 'a b', "Ste\nve", 'Steve%00', 'ThisNameIsWayTooLongForJava', '..', '.', 'a.dat', '069a79f4-44e9-4726-a5be-fca90e38aaf5/../x', '069a79f4-44e9-4726-a5be-fca90e38aaf5.dat', '{uuid}', 'Ünicode'] as $bad) {
            $this->assertNull(Players::parse($bad), $bad);
        }
    }

    public function test_offline_uuid_is_version_3(): void
    {
        $uuid = Players::offlineUuid('Notch');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-3[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $uuid);
        $this->assertSame($uuid, Players::offlineUuid('Notch'));
        $this->assertNotSame($uuid, Players::offlineUuid('notch'));
    }

    public function test_known_players_from_usercache(): void
    {
        $json = json_encode([
            ['name' => 'Alex', 'uuid' => '11111111-1111-1111-1111-111111111111', 'expiresOn' => '2026-09-08 12:00:00 +0200'],
            ['name' => 'Steve', 'uuid' => '22222222-2222-2222-2222-222222222222', 'expiresOn' => '2026-10-08 12:00:00 +0200'],
            ['name' => 'Bad/Name', 'uuid' => '33333333-3333-3333-3333-333333333333'],
            ['name' => 'NoUuid'],
            ['name' => 'BadUuid', 'uuid' => 'nope'],
            'junk',
            ['name' => 'NoDate', 'uuid' => '44444444-4444-4444-4444-444444444444'],
            ['name' => 'steve2', 'uuid' => '22222222-2222-2222-2222-222222222222', 'expiresOn' => '2026-10-09 12:00:00 +0200'],
        ]);
        $known = Players::known($json);

        $this->assertSame(['steve2', 'Alex', 'NoDate'], array_column($known, 'name'));
        $this->assertSame('2026-09-09T12:00:00+02:00', $known[0]['last_seen']);
        $this->assertNull($known[2]['last_seen']);

        $this->assertSame('Alex', Players::find($known, ['name' => 'ALEX'])['name']);
        $this->assertSame('steve2', Players::find($known, ['uuid' => '22222222-2222-2222-2222-222222222222'])['name']);
        $this->assertNull(Players::find($known, ['name' => 'Nobody']));

        $this->assertSame([], Players::known(null));
        $this->assertSame([], Players::known('not json'));
        $this->assertSame([], Players::known('"string"'));
        $this->assertSame([], Players::known('{}'));
    }

    public function test_world_folder_comes_from_level_name(): void
    {
        $this->assertSame('world', MinecraftServer::worldFolder(null));
        $this->assertSame('world', MinecraftServer::worldFolder("motd=Hi\nlevel-name=\n"));
        $this->assertSame('My World', MinecraftServer::worldFolder("a=b\r\nlevel-name=My World\r\nc=d"));
        $this->assertSame('survival_1.2-b', MinecraftServer::worldFolder('level-name=survival_1.2-b'));
        $this->assertSame('world', MinecraftServer::worldFolder("#level-name=other\nmotd=x"));

        foreach (['..', '../x', '/etc', 'a/b', 'a\\b', '.hidden', 'a..b', str_repeat('a', 65), "x\ty"] as $bad) {
            $this->assertNull(MinecraftServer::worldFolder('level-name='.$bad), $bad);
        }
    }

    public function test_player_data_folder_prefers_the_newer_layout(): void
    {
        $this->assertSame(['players/data', 'playerdata'], MinecraftServer::playerDataFolders());

        $uuid = '069a79f4-44e9-4726-a5be-fca90e38aaf5.dat';
        $this->assertSame('/w/players/data', MinecraftServer::pickPlayerDataFolder(['/w/players/data' => [$uuid], '/w/playerdata' => [$uuid]]));
        $this->assertSame('/w/playerdata', MinecraftServer::pickPlayerDataFolder(['/w/playerdata' => [$uuid]]));
        // An upgraded server that has not saved yet: the old folder still has the players.
        $this->assertSame('/w/playerdata', MinecraftServer::pickPlayerDataFolder(['/w/players/data' => [], '/w/playerdata' => [$uuid]]));
        // Neither has players: stay with the newer one.
        $this->assertSame('/w/players/data', MinecraftServer::pickPlayerDataFolder(['/w/players/data' => [], '/w/playerdata' => []]));
        $this->assertNull(MinecraftServer::pickPlayerDataFolder([]));
    }
}
