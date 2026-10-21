<?php

use McNetwork\Support\Drift;
use McNetwork\Support\ProxyConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class DriftTest extends TestCase
{
    protected function setUp(): void
    {
        if (! class_exists(Yaml::class)) {
            $this->markTestSkipped('symfony/yaml is not available: run composer install in the repository root.');
        }
    }

    private function fixture(): string
    {
        return (string) file_get_contents(__DIR__.'/fixtures/config.yml');
    }

    private function rewrite(string $yaml): string
    {
        $config = ProxyConfig::parse($yaml);

        return $config->replace(
            array_map(fn (array $server): array => $server + ['was' => $server['name']], $config->servers()),
            $config->priorities(),
            $config->forcedHosts(),
        )->dump();
    }

    public function test_the_sample_config_is_safe_and_rewrites_without_other_changes(): void
    {
        $this->assertSame([], Drift::risky($this->fixture()));
        $this->assertSame([], Drift::changes($this->fixture(), $this->rewrite($this->fixture())));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('yes_no_words')]
    public function test_plain_yaml_11_booleans_are_found(string $word): void
    {
        $yaml = "ip_forward: $word\nservers: {}\nlisteners:\n- host: 0.0.0.0:25565\n  priorities: []\n  forced_hosts: {}\n";

        $risky = Drift::risky($yaml);
        $this->assertSame(['ip_forward is '.$word], $risky);
    }

    public static function yes_no_words(): array
    {
        return array_combine(
            ['yes', 'No', 'ON', 'off', 'y', 'N', 'YES', 'Off'],
            array_map(fn (string $word): array => [$word], ['yes', 'No', 'ON', 'off', 'y', 'N', 'YES', 'Off']),
        );
    }

    public function test_quoted_words_are_fine_because_they_stay_text(): void
    {
        $yaml = "motd: 'yes'\nother: \"no\"\nservers: {}\nlisteners:\n- priorities: []\n  forced_hosts: {}\n";

        $this->assertSame([], Drift::risky($yaml));
        $this->assertSame([], Drift::changes($yaml, $this->rewrite($yaml)));
    }

    public function test_sexagesimal_numbers_are_found(): void
    {
        foreach (['1:30', '12:00:00', '-3:15', '0:59.5'] as $value) {
            $yaml = "interval: $value\nservers: {}\n";
            $this->assertSame(['interval is '.$value], Drift::risky($yaml), $value);
        }
        // An address or a UUID is not a base-60 number.
        $this->assertSame([], Drift::risky("host: 0.0.0.0:25565\nstats: 8f1c0a3e-0000-4000-8000-000000000000\nport: 25565:25565\nservers: {}\n"));
    }

    public function test_values_inside_lists_and_nested_maps_are_found(): void
    {
        $yaml = "permissions:\n  default:\n  - bungeecord.command.server\n  - on\ngroups:\n  Anton: [admin, no]\nservers: {}\n";

        $this->assertEqualsCanonicalizing(['permissions.default[1] is on', 'groups.Anton[1] is no'], Drift::risky($yaml));
    }

    public function test_the_edited_parts_are_not_reported(): void
    {
        $yaml = "servers:\n  lobby:\n    restricted: yes\n    address: localhost:25566\n    motd: x\nlisteners:\n- host: 0.0.0.0:25565\n  priorities:\n  - no\n  forced_hosts:\n    a.example.com: on\n  force_default_server: false\n";

        $this->assertSame([], Drift::risky($yaml));
    }

    public function test_the_second_listener_is_not_edited_so_it_is_checked(): void
    {
        $yaml = "servers: {}\nlisteners:\n- host: 0.0.0.0:25565\n  priorities: []\n- host: 0.0.0.0:25566\n  priorities:\n  - yes\n";

        $this->assertSame(['listeners[1].priorities[0] is yes'], Drift::risky($yaml));
    }

    public function test_a_rewrite_that_changes_a_value_is_reported(): void
    {
        $before = "timeout: 30000\nip_forward: true\nservers: {}\nlisteners:\n- host: a\n  max_players: 100\n  priorities: [lobby]\n  forced_hosts: {}\n- host: b\n  max_players: 5\n";

        $this->assertSame([], Drift::changes($before, $before));
        $this->assertSame(['timeout'], Drift::changes($before, str_replace('30000', '"30000"', $before)));
        $this->assertSame(['ip_forward'], Drift::changes($before, str_replace('ip_forward: true', "ip_forward: 'true'", $before)));
        $this->assertSame(['listeners[0]'], Drift::changes($before, str_replace('max_players: 100', 'max_players: 99', $before)));
        $this->assertSame(['listeners[1]'], Drift::changes($before, str_replace('max_players: 5', 'max_players: 5.0', $before)));
        $this->assertSame(['extra'], Drift::changes($before, $before."extra: 1\n"));
        $this->assertSame(['timeout'], Drift::changes($before, str_replace("timeout: 30000\n", '', $before)));
    }

    public function test_edits_to_servers_and_the_first_listeners_priorities_are_allowed(): void
    {
        $before = "timeout: 1\nservers:\n  a:\n    address: 'x:1'\nlisteners:\n- host: a\n  priorities: [a]\n  forced_hosts: {}\n";
        $after = "timeout: 1\nservers:\n  b:\n    address: 'y:2'\nlisteners:\n- host: a\n  priorities: [b]\n  forced_hosts:\n    h.example.com: b\n";

        $this->assertSame([], Drift::changes($before, $after));
    }

    public function test_the_order_of_keys_does_not_matter(): void
    {
        $this->assertSame([], Drift::changes("a: 1\nb: 2\nservers: {}\n", "b: 2\na: 1\nservers: {}\n"));
    }

    public function test_a_yes_that_the_writer_quotes_is_exactly_what_the_guard_catches(): void
    {
        $yaml = "ip_forward: yes\nservers: {}\nlisteners:\n- host: a\n  priorities: []\n  forced_hosts: {}\n";

        // The writer turns the plain word into text; a re-parse alone cannot tell the difference.
        $this->assertStringContainsString("'yes'", $this->rewrite($yaml));
        $this->assertSame([], Drift::changes($yaml, $this->rewrite($yaml)));
        $this->assertSame(['ip_forward is yes'], Drift::risky($yaml));
    }

    public function test_broken_yaml_is_never_called_safe_to_save(): void
    {
        $this->assertSame(['config.yml'], Drift::changes("a: [1\n", "a: 1\n"));
    }
}
