<?php

use McNetwork\Support\Analyzer;
use McNetwork\Support\ProxyConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class AnalyzerTest extends TestCase
{
    /** @return list<array{id: string, name: string, ports: list<int>, primary: ?int, java: bool, proxy: bool}> */
    private function panel(): array
    {
        $server = fn (string $id, string $name, int $port, bool $java = true, bool $proxy = false): array => [
            'id' => $id, 'name' => $name, 'ports' => [$port], 'primary' => $port, 'java' => $java, 'proxy' => $proxy,
        ];

        return [
            $server('aaaa0001', 'Lobby', 12550),
            $server('aaaa0002', 'The Kingdom', 12551),
            $server('aaaa0003', 'Minetopia', 12552),
            $server('aaaa0004', 'Survival', 12553),
            $server('aaaa0005', 'Hardcore', 12554),
            $server('aaaa0006', 'Anton X Senna', 12555),
            $server('aaaa0007', 'Terraria', 12556, java: false),
            $server('aaaa0008', 'E-Active', 12557),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function analyzeFixture(): array
    {
        if (! class_exists(Yaml::class)) {
            $this->markTestSkipped('symfony/yaml is not available: run composer install in the repository root.');
        }
        $config = ProxyConfig::parse((string) file_get_contents(__DIR__.'/fixtures/config.yml'));
        $servers = $config->servers();
        $result = Analyzer::analyze($servers, $config->priorities(), $config->forcedHosts(), $this->panel(), [25565], ['node.example.net']);

        $byName = [];
        foreach ($servers as $index => $server) {
            $byName[$server['name']] = $result['entries'][$index];
        }
        $byName['_global'] = $result['problems'];

        return $byName;
    }

    /** @param array<string, mixed> $row */
    private function codes(array $row): array
    {
        return array_column($row['problems'], 'code');
    }

    public function test_a_correct_entry_has_no_problems_and_a_matched_server(): void
    {
        $result = $this->analyzeFixture();

        $this->assertSame([], $this->codes($result['lobby']));
        $this->assertSame(['id' => 'aaaa0001', 'name' => 'Lobby'], $result['lobby']['server']);
        $this->assertSame([], $this->codes($result['Hardcore']));
        $this->assertSame('Minetopia', $result['Minetopia']['server']['name'], '127.0.0.1 counts as this node');
    }

    public function test_port_without_a_panel_server(): void
    {
        $result = $this->analyzeFixture();

        $this->assertSame(['no_server'], $this->codes($result['TheKingdom']));
        $this->assertNull($result['TheKingdom']['server']);
        $this->assertStringContainsString('13261', $result['TheKingdom']['problems'][0]['message']);
    }

    public function test_an_entry_named_differently_from_its_server_shows_the_real_server(): void
    {
        $result = $this->analyzeFixture();

        $this->assertSame('The Kingdom', $result['Survival']['server']['name']);
        $this->assertSame([], $this->codes($result['Survival']));
    }

    public function test_points_at_the_proxy_itself(): void
    {
        $result = $this->analyzeFixture();

        $this->assertSame(['self'], $this->codes($result['Skyblock']));
        $this->assertSame('error', $result['Skyblock']['problems'][0]['level']);
    }

    public function test_non_minecraft_server(): void
    {
        $result = $this->analyzeFixture();

        $this->assertSame(['not_minecraft'], $this->codes($result['Terra']));
        $this->assertSame('Terraria', $result['Terra']['server']['name']);
    }

    public function test_duplicate_ports(): void
    {
        $result = $this->analyzeFixture();

        $this->assertSame(['no_server', 'duplicate_port'], $this->codes($result['Private1']));
        $this->assertStringContainsString('Private2', $result['Private1']['problems'][1]['message']);
        $this->assertStringContainsString('Private1', $result['Private2']['problems'][1]['message']);
    }

    public function test_other_hosts_are_not_matched(): void
    {
        $result = $this->analyzeFixture();

        $this->assertSame(['external'], $this->codes($result['Far']));
        $this->assertFalse($result['Far']['local']);
        $this->assertSame('info', $result['Far']['problems'][0]['level']);
    }

    public function test_names_used_but_missing_from_servers(): void
    {
        $result = $this->analyzeFixture();

        $codes = array_column($result['_global'], 'code');
        $this->assertSame(['unknown_priority', 'unknown_forced_host'], $codes);
        $this->assertSame('Ghost', $result['_global'][0]['name']);
        $this->assertSame('SkyBlock', $result['_global'][1]['name'], 'server names are case sensitive');
    }

    public function test_node_addresses_count_as_local(): void
    {
        $entries = [
            ['name' => 'a', 'address' => 'node.example.net:12550'],
            ['name' => 'b', 'address' => 'NODE.example.net:12551'],
            ['name' => 'c', 'address' => '203.0.113.9:12552'],
            ['name' => 'd', 'address' => '0.0.0.0:12553'],
            ['name' => 'e', 'address' => '[::1]:12554'],
            ['name' => 'f', 'address' => '203.0.113.10:12554'],
        ];
        $result = Analyzer::analyze($entries, ['a'], [], $this->panel(), [25565], ['node.example.net', '203.0.113.9']);

        $this->assertSame(['Lobby', 'The Kingdom', 'Minetopia', 'Survival', 'Hardcore'], array_map(fn (array $row): ?string => $row['server']['name'] ?? null, array_slice($result['entries'], 0, 5)));
        $this->assertSame(['external'], array_column($result['entries'][5]['problems'], 'code'));
    }

    public function test_the_same_port_on_different_remote_hosts_is_not_a_duplicate(): void
    {
        $entries = [
            ['name' => 'a', 'address' => 'one.example.org:25566'],
            ['name' => 'b', 'address' => 'two.example.org:25566'],
            ['name' => 'c', 'address' => 'one.example.org:25566'],
        ];
        $result = Analyzer::analyze($entries, ['a'], [], [], [25565]);

        $this->assertNotContains('duplicate_port', array_column($result['entries'][1]['problems'], 'code'));
        $this->assertContains('duplicate_port', array_column($result['entries'][0]['problems'], 'code'));
        $this->assertContains('duplicate_port', array_column($result['entries'][2]['problems'], 'code'));
    }

    public function test_localhost_and_127_are_the_same_address_for_duplicates(): void
    {
        $result = Analyzer::analyze([['name' => 'a', 'address' => 'localhost:12550'], ['name' => 'b', 'address' => '127.0.0.1:12550']], ['a'], [], $this->panel(), [25565]);

        $this->assertContains('duplicate_port', array_column($result['entries'][0]['problems'], 'code'));
        $this->assertContains('duplicate_port', array_column($result['entries'][1]['problems'], 'code'));
    }

    public function test_invalid_addresses(): void
    {
        $entries = [['name' => 'a', 'address' => 'localhost'], ['name' => 'b', 'address' => 'localhost:0'], ['name' => 'c', 'address' => ''], ['name' => 'd', 'address' => 'unix:///tmp/x.sock']];
        $result = Analyzer::analyze($entries, ['a'], [], $this->panel(), [25565]);

        foreach ($result['entries'] as $row) {
            $this->assertSame(['invalid_address'], array_column($row['problems'], 'code'));
            $this->assertNull($row['port']);
        }
    }

    public function test_a_proxy_and_a_bedrock_server_are_not_backends(): void
    {
        $panel = [
            ['id' => 'p1', 'name' => 'Other proxy', 'ports' => [25570], 'primary' => 25570, 'java' => false, 'proxy' => true],
            ['id' => 'b1', 'name' => 'Bedrock', 'ports' => [19132], 'primary' => 19132, 'java' => false, 'proxy' => false],
        ];
        $result = Analyzer::analyze([['name' => 'a', 'address' => 'localhost:25570'], ['name' => 'b', 'address' => 'localhost:19132']], ['a'], [], $panel, [25565]);

        $this->assertSame(['proxy'], array_column($result['entries'][0]['problems'], 'code'));
        $this->assertSame(['not_minecraft'], array_column($result['entries'][1]['problems'], 'code'));
    }

    public function test_a_server_with_several_ports_matches_on_any_and_prefers_its_primary(): void
    {
        $panel = [
            ['id' => 's1', 'name' => 'Extra port', 'ports' => [30000, 30001], 'primary' => 30000, 'java' => true, 'proxy' => false],
            ['id' => 's2', 'name' => 'Main', 'ports' => [30001], 'primary' => 30001, 'java' => true, 'proxy' => false],
        ];
        $result = Analyzer::analyze([['name' => 'a', 'address' => 'localhost:30001']], ['a'], [], $panel, [25565]);

        $this->assertSame('Main', $result['entries'][0]['server']['name']);
    }

    public function test_an_empty_priority_list_is_reported(): void
    {
        $result = Analyzer::analyze([['name' => 'a', 'address' => 'localhost:12550']], [], [], $this->panel(), [25565]);

        $this->assertSame(['no_priorities'], array_column($result['problems'], 'code'));
    }
}
