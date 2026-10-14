<?php

use PHPUnit\Framework\TestCase;
use ValheimTools\Support\PlayerIds;

class PlayerIdsTest extends TestCase
{
    public function test_valid_ids(): void
    {
        foreach (['76561198051014133', 'V_76561198051014133', 'Steam_76561198051014133', 'Xbox_2535400000000001'] as $id) {
            $this->assertTrue(PlayerIds::isValid($id), $id);
        }
    }

    public function test_invalid_ids(): void
    {
        foreach (['', ' ', '123', 'V_', '../etc/passwd', "7656119805101413\n3", '76561198051014133 ', 'Bob', '// comment', 'V_7656119805101413;rm', "Steam_1234\x00", str_repeat('A', 100)] as $id) {
            $this->assertFalse(PlayerIds::isValid($id), json_encode($id));
        }
    }

    public function test_steam_id_and_key(): void
    {
        $this->assertSame('76561198051014133', PlayerIds::steamId('V_76561198051014133'));
        $this->assertSame('76561198051014133', PlayerIds::steamId('Steam_76561198051014133'));
        $this->assertNull(PlayerIds::steamId('Xbox_2535400000000001'));
        $this->assertSame(PlayerIds::key('V_76561198051014133'), PlayerIds::key('Steam_76561198051014133'));
        $this->assertSame('Xbox_2535400000000001', PlayerIds::key('Xbox_2535400000000001'));
    }

    public function test_format(): void
    {
        $this->assertSame('V_76561198051014133', PlayerIds::format('76561198051014133', 'v'));
        $this->assertSame('Steam_76561198051014133', PlayerIds::format('76561198051014133', 'steam'));
        $this->assertSame('76561198051014133', PlayerIds::format('76561198051014133', 'plain'));
        $this->assertSame('V_76561198051014133', PlayerIds::format('76561198051014133', 'nonsense'));
    }

    public function test_from_input(): void
    {
        $this->assertSame('V_76561198051014133', PlayerIds::fromInput(' 76561198051014133 ', 'v'));
        $this->assertSame('Steam_76561198051014133', PlayerIds::fromInput('V_76561198051014133', 'steam'));
        $this->assertSame('V_76561198051014133', PlayerIds::fromInput('https://steamcommunity.com/profiles/76561198051014133/', 'v'));
        $this->assertSame('Xbox_2535400000000001', PlayerIds::fromInput('Xbox_2535400000000001', 'v'));
        $this->assertNull(PlayerIds::fromInput('Big Bob', 'v'));
        $this->assertNull(PlayerIds::fromInput('', 'v'));
        $this->assertNull(PlayerIds::fromInput("V_76561198051014133\nV_76561198051014134", 'v'));
    }
}
