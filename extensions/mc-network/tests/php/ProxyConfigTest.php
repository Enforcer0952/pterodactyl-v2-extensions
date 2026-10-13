<?php

use McNetwork\Support\InvalidConfig;
use McNetwork\Support\ProxyConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class ProxyConfigTest extends TestCase
{
    private string $yaml;

    protected function setUp(): void
    {
        if (! class_exists(Yaml::class)) {
            $this->markTestSkipped('symfony/yaml is not available: run composer install in the repository root.');
        }
        $this->yaml = (string) file_get_contents(__DIR__.'/fixtures/config.yml');
    }

    private function plain(string $yaml): mixed
    {
        return Yaml::parse($yaml, Yaml::PARSE_OBJECT_FOR_MAP);
    }

    public function test_reads_servers_priorities_and_forced_hosts(): void
    {
        $config = ProxyConfig::parse($this->yaml);

        $servers = $config->servers();
        $this->assertCount(10, $servers);
        $this->assertSame(['name' => 'lobby', 'address' => 'localhost:12550', 'motd' => '&1Lobby &r&7- spawn', 'restricted' => false], $servers[0]);
        $this->assertTrue($servers[4]['restricted']);
        $this->assertSame(['lobby', 'Ghost'], $config->priorities());
        $this->assertSame([['host' => 'pvp.example.com', 'server' => 'Hardcore'], ['host' => 'skyblock.example.com', 'server' => 'SkyBlock']], $config->forcedHosts());
        $this->assertTrue($config->hasListener());
    }

    public function test_round_trip_keeps_every_other_key(): void
    {
        $config = ProxyConfig::parse($this->yaml);
        $config->replace(
            array_map(fn (array $server): array => $server + ['was' => $server['name']], $config->servers()),
            $config->priorities(),
            $config->forcedHosts(),
        );

        $before = $this->plain($this->yaml);
        $after = $this->plain($config->dump());
        $this->assertEquals($before, $after);

        // Spot checks on keys the editor knows nothing about.
        $this->assertSame(30000, $after->timeout);
        $this->assertSame('8f1c0a3e-0000-4000-8000-000000000000', $after->stats);
        $this->assertSame(['admin'], $after->groups->Anton);
        $this->assertSame(25578, $after->listeners[1]->query_port);
        $this->assertSame(['disabledcommandhere'], $after->disabled_commands);
        $this->assertTrue($after->ip_forward);
    }

    public function test_empty_maps_stay_maps_and_empty_lists_stay_lists(): void
    {
        $config = ProxyConfig::parse("servers: {}\nlisteners:\n- host: 0.0.0.0:25565\n  priorities: []\n  forced_hosts: {}\npermissions:\n  default: []\ngroups: {}\n");
        $config->replace([], [], []);
        $dumped = $config->dump();

        $this->assertStringContainsString('servers: {  }', $dumped);
        $this->assertStringContainsString('forced_hosts: {  }', $dumped);
        $this->assertStringContainsString('groups: {  }', $dumped);
        $this->assertStringContainsString('priorities: []', $dumped);
        $this->assertStringContainsString('default: []', $dumped);
    }

    public function test_edits_apply_and_other_listeners_stay_untouched(): void
    {
        $config = ProxyConfig::parse($this->yaml);
        $config->replace(
            [
                ['name' => 'lobby', 'address' => 'localhost:12550', 'motd' => 'Spawn', 'restricted' => true, 'was' => 'lobby'],
                ['name' => 'Kingdom', 'address' => 'localhost:12551', 'motd' => '', 'restricted' => false, 'was' => 'Survival'],
                ['name' => 'Fresh', 'address' => 'localhost:12557', 'motd' => '&aNew', 'restricted' => false],
            ],
            ['Fresh', 'lobby'],
            [['host' => 'play.example.com', 'server' => 'Fresh']],
        );

        $reread = ProxyConfig::parse($config->dump());
        $this->assertSame(['lobby', 'Kingdom', 'Fresh'], array_column($reread->servers(), 'name'));
        $this->assertSame('Spawn', $reread->servers()[0]['motd']);
        $this->assertTrue($reread->servers()[0]['restricted']);
        $this->assertSame(['Fresh', 'lobby'], $reread->priorities());
        $this->assertSame([['host' => 'play.example.com', 'server' => 'Fresh']], $reread->forcedHosts());

        $data = $this->plain($config->dump());
        $this->assertSame(25565, (int) substr($data->listeners[0]->host, -5));
        $this->assertSame(['lobby'], $data->listeners[1]->priorities);
        $this->assertSame(25578, $data->listeners[1]->query_port);
        $this->assertSame(100, $data->listeners[0]->max_players);
    }

    public function test_extra_keys_of_an_entry_survive_a_rename(): void
    {
        $config = ProxyConfig::parse("servers:\n  old:\n    address: 'localhost:25566'\n    motd: hi\n    restricted: false\n    custom_flag: 7\nlisteners:\n- priorities: [old]\n  forced_hosts: {}\n");
        $config->replace([['name' => 'new', 'address' => 'localhost:25566', 'motd' => 'hi', 'restricted' => false, 'was' => 'old']], ['new'], []);

        $data = $this->plain($config->dump());
        $this->assertSame(7, $data->servers->new->custom_flag);
        $this->assertFalse(isset($data->servers->old));
    }

    public function test_special_characters_are_quoted_and_cannot_add_keys(): void
    {
        $nasty = "x'\n  evil: true\n- injected: [1, 2]\n# comment: {a}";
        $config = ProxyConfig::parse($this->yaml);
        $config->replace(
            [['name' => 'lobby', 'address' => 'localhost:12550', 'motd' => $nasty, 'restricted' => false, 'was' => 'lobby']],
            ['lobby'],
            [['host' => 'a.example.com', 'server' => "lobby\nevil: 1"]],
        );

        $data = $this->plain($config->dump());
        $this->assertSame($nasty, $data->servers->lobby->motd);
        $this->assertFalse(isset($data->evil));
        $this->assertSame("lobby\nevil: 1", $data->listeners[0]->forced_hosts->{'a.example.com'});
        $this->assertSame(['lobby'], $data->listeners[0]->priorities);
    }

    public function test_values_that_look_like_other_types_stay_strings(): void
    {
        $config = ProxyConfig::parse($this->yaml);
        $config->replace(
            [['name' => 'lobby', 'address' => 'localhost:12550', 'motd' => 'true', 'restricted' => false, 'was' => 'lobby'], ['name' => '123', 'address' => 'localhost:12551', 'motd' => '1e3', 'restricted' => false]],
            ['123'],
            [],
        );

        $reread = ProxyConfig::parse($config->dump());
        $this->assertSame('true', $reread->servers()[0]['motd']);
        $this->assertSame('123', $reread->servers()[1]['name']);
        $this->assertSame('1e3', $reread->servers()[1]['motd']);
        $this->assertSame(['123'], $reread->priorities());
    }

    public function test_section_sign_and_unicode_motd_survive(): void
    {
        $config = ProxyConfig::parse($this->yaml);
        $config->replace([['name' => 'lobby', 'address' => 'localhost:12550', 'motd' => "§aGroen \u{2022} café", 'restricted' => false, 'was' => 'lobby']], [], []);

        $this->assertSame("§aGroen \u{2022} café", ProxyConfig::parse($config->dump())->servers()[0]['motd']);
    }

    public function test_priorities_need_a_listener(): void
    {
        $config = ProxyConfig::parse("servers: {}\n");
        $this->expectException(InvalidConfig::class);
        $config->replace([], ['lobby'], []);
    }

    public function test_without_a_listener_the_servers_can_still_be_saved(): void
    {
        $config = ProxyConfig::parse("timeout: 5\n");
        $config->replace([['name' => 'a', 'address' => 'localhost:1', 'motd' => '', 'restricted' => false]], [], []);

        $this->assertFalse($config->hasListener());
        $this->assertSame('a', ProxyConfig::parse($config->dump())->servers()[0]['name']);
    }

    public function test_invalid_input_is_refused(): void
    {
        foreach (["servers: [unclosed\n", '', "- a\n- b\n", 'just text'] as $text) {
            try {
                ProxyConfig::parse($text);
                $this->fail('Expected InvalidConfig for: '.$text);
            } catch (InvalidConfig $exception) {
                $this->assertStringStartsWith('config.yml is not', $exception->getMessage());
            }
        }
    }

    public function test_php_object_tags_are_not_turned_into_objects(): void
    {
        $config = ProxyConfig::parse("servers:\n  a:\n    address: !php/object 'O:8:\"stdClass\":0:{}'\n");

        $this->assertSame('', $config->servers()[0]['address']);
    }

    public function test_odd_entries_do_not_break_reading(): void
    {
        $config = ProxyConfig::parse("servers:\n  a: nothing\n  b:\n    address: 5\n  c: {restricted: yes-ish}\nlisteners:\n- priorities: x\n  forced_hosts: [a]\n");

        $this->assertSame([
            ['name' => 'a', 'address' => '', 'motd' => '', 'restricted' => false],
            ['name' => 'b', 'address' => '5', 'motd' => '', 'restricted' => false],
            ['name' => 'c', 'address' => '', 'motd' => '', 'restricted' => false],
        ], $config->servers());
        $this->assertSame([], $config->priorities());
        $this->assertSame([], $config->forcedHosts());
    }
}
