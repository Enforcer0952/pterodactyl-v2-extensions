<?php

use McNetwork\Support\Address;
use McNetwork\Support\Eggs;
use McNetwork\Support\Motd;
use McNetwork\Support\Validate;
use PHPUnit\Framework\TestCase;

final class ValidateTest extends TestCase
{
    /** @return array{servers: list<array<string, mixed>>, priorities: list<mixed>, forced_hosts: list<array<string, mixed>>} */
    private function draft(array $servers = [], array $priorities = [], array $forced = []): array
    {
        return ['servers' => $servers, 'priorities' => $priorities, 'forced_hosts' => $forced];
    }

    private function server(string $name, string $address = 'localhost:25566', string $motd = '', ?string $was = null): array
    {
        return ['name' => $name, 'address' => $address, 'motd' => $motd, 'restricted' => false, 'was' => $was];
    }

    public function test_server_names(): void
    {
        foreach (['lobby', 'The-Kingdom_2', str_repeat('a', 32), 'A', '1a', '1-2', '_1', '007x'] as $name) {
            $this->assertTrue(Validate::serverName($name), $name);
        }
        foreach (['', 'has space', 'a:b', "a\nb", "lobby\n", str_repeat('a', 33), 'ünïcode', 'a.b', '../x', 'a#b', "a\0b", '123', '0', '007', str_repeat('9', 32)] as $name) {
            $this->assertFalse(Validate::serverName($name), json_encode($name));
        }
    }

    public function test_a_name_of_only_digits_is_refused_for_new_servers_but_an_old_one_stays(): void
    {
        $errors = Validate::draft($this->draft([$this->server('2024')]), []);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('not a digit', $errors[0]);

        $current = [['name' => '2024', 'address' => 'localhost:25566', 'motd' => '', 'restricted' => false]];
        $this->assertSame([], Validate::draft($this->draft([$this->server('2024', 'localhost:25566', '', '2024')]), $current));
        $this->assertNotSame([], Validate::draft($this->draft([$this->server('2025', 'localhost:25566', '', '2024')]), $current));
    }

    public function test_a_good_draft_has_no_errors(): void
    {
        $draft = $this->draft([$this->server('lobby'), $this->server('Two', '127.0.0.1:12551', '&aHello')], ['lobby'], [['host' => 'play.example.com', 'server' => 'lobby']]);

        $this->assertSame([], Validate::draft($draft, []));
    }

    public function test_bad_names_addresses_and_motds_are_reported(): void
    {
        $draft = $this->draft([
            $this->server("bad name\nx"),
            $this->server('lobby', 'localhost'),
            $this->server('two', 'localhost:70000'),
            $this->server('three', 'localhost:25566', "evil\nkey: 1"),
            $this->server('four', 'localhost:25566', str_repeat('x', 257)),
        ]);

        $errors = Validate::draft($draft, []);
        $this->assertCount(5, $errors);
        $this->assertStringNotContainsString("\n", implode('', $errors), 'messages never echo control characters');
    }

    public function test_duplicate_names_are_reported(): void
    {
        $errors = Validate::draft($this->draft([$this->server('lobby'), $this->server('lobby', 'localhost:2')], ['lobby', 'lobby']), []);

        $this->assertContains('"lobby" is listed twice.', $errors);
        $this->assertContains('"lobby" is in the priority list twice.', $errors);
    }

    public function test_an_old_entry_that_breaks_todays_rules_can_stay_as_it_is(): void
    {
        $current = [['name' => 'The Kingdom', 'address' => 'unix:///run/k.sock', 'motd' => '', 'restricted' => false]];

        $unchanged = $this->draft([$this->server('The Kingdom', 'unix:///run/k.sock', '', 'The Kingdom')]);
        $this->assertSame([], Validate::draft($unchanged, $current));

        $changed = $this->draft([$this->server('The Kingdom', 'unix:///run/other.sock', '', 'The Kingdom')]);
        $this->assertCount(1, Validate::draft($changed, $current));

        $renamed = $this->draft([$this->server('Kingdom 2', 'unix:///run/k.sock', '', 'The Kingdom')]);
        $this->assertCount(1, Validate::draft($renamed, $current));

        $newEntryWithSameName = $this->draft([$this->server('The Kingdom', 'localhost:25566')]);
        $this->assertCount(1, Validate::draft($newEntryWithSameName, $current));
    }

    public function test_forced_hosts(): void
    {
        $errors = Validate::draft($this->draft([$this->server('lobby')], ['lobby'], [
            ['host' => 'ok.example.com', 'server' => 'lobby'],
            ['host' => 'OK.example.com', 'server' => 'lobby'],
            ['host' => 'bad host', 'server' => 'lobby'],
            ['host' => "x\ny", 'server' => 'lobby'],
            ['host' => 'fine.example.com', 'server' => ''],
        ]), []);

        $this->assertCount(4, $errors);
        $this->assertContains('The host "OK.example.com" is listed twice.', $errors);
    }

    public function test_priorities_must_be_clean_strings(): void
    {
        $this->assertNotSame([], Validate::draft($this->draft([$this->server('lobby')], ["a\nb"]), []));
        $this->assertNotSame([], Validate::draft($this->draft([$this->server('lobby')], [['x']]), []));
        $this->assertNotSame([], Validate::draft($this->draft([$this->server('lobby')], ['']), []));
    }

    public function test_limits(): void
    {
        $servers = [];
        for ($i = 0; $i <= Validate::MAX_SERVERS; $i++) {
            $servers[] = $this->server('s'.$i, 'localhost:'.(10000 + $i));
        }

        $this->assertContains('A network can have at most 200 servers.', Validate::draft($this->draft($servers), []));
    }

    public function test_addresses(): void
    {
        $this->assertSame(['host' => 'localhost', 'port' => 25566], Address::parse('localhost:25566'));
        $this->assertSame(['host' => '127.0.0.1', 'port' => 1], Address::parse(' 127.0.0.1:1 '));
        $this->assertSame(['host' => 'mc-1.example.com', 'port' => 65535], Address::parse('mc-1.example.com:65535'));
        $this->assertSame(['host' => '[::1]', 'port' => 25565], Address::parse('[::1]:25565'));
        $this->assertSame(['host' => '[2001:db8::1]', 'port' => 25565], Address::parse('[2001:DB8::1]:25565'));

        foreach (['', 'localhost', 'localhost:', ':25565', 'localhost:0', 'localhost:65536', 'localhost:-1', 'localhost:+5', 'localhost:1e3', 'local host:25565', 'localhost:25565:1', '::1:25565', '[::1]', '[not-ip]:25565', '-bad:25565', 'bad-:25565', '.bad:25565', 'a/b:25565', 'a;b:25565', 'localhost:0x50', 'http://host:25565', str_repeat('a', 300).':1'] as $address) {
            $this->assertNull(Address::parse($address), json_encode($address));
        }
    }

    public function test_host_names(): void
    {
        $this->assertTrue(Address::isHostName('play.example.com'));
        $this->assertTrue(Address::isHostName('a'));
        $this->assertFalse(Address::isHostName(''));
        $this->assertFalse(Address::isHostName('a b'));
        $this->assertFalse(Address::isHostName("a\n"));
        $this->assertFalse(Address::isHostName(str_repeat('a', 254)));
    }

    public function test_motd_without_colour_codes(): void
    {
        $this->assertSame('Lobby - spawn', Motd::plain('&1Lobby &r&7- spawn'));
        $this->assertSame('Survival', Motd::plain('§aSurvival'));
        $this->assertSame('Minetopia City', Motd::plain('&#ff8800Minetopia &lCity'));
        $this->assertSame('Hex', Motd::plain('&x&f&f&0&0&0&0Hex'));
        $this->assertSame('Bold Text', Motd::plain('§l§nBold §rText'));
        $this->assertSame('Fish & Chips', Motd::plain('Fish & Chips'));
        $this->assertSame('One Two', Motd::plain("One\nTwo"));
        $this->assertSame('', Motd::plain('&a&l'));
        $this->assertSame('Stray sign', Motd::plain('Stray § sign'));
    }

    public function test_egg_roles(): void
    {
        $this->assertTrue(Eggs::isProxy(['Minecraft', 'Proxy']));
        $this->assertTrue(Eggs::isProxy(['waterfall']));
        $this->assertFalse(Eggs::isProxy(['minecraft']));
        $this->assertFalse(Eggs::isProxy([]));

        $this->assertTrue(Eggs::isJavaBackend(['minecraft'], []));
        $this->assertTrue(Eggs::isJavaBackend([], ['eula']));
        $this->assertTrue(Eggs::isJavaBackend([], ['EULA']));
        $this->assertFalse(Eggs::isJavaBackend(['minecraft', 'proxy'], ['eula']));
        $this->assertFalse(Eggs::isJavaBackend(['minecraft', 'bedrock'], []));
        $this->assertFalse(Eggs::isJavaBackend(['terraria'], ['pid_limit']));
    }
}
