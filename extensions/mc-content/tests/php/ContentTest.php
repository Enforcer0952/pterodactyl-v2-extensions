<?php

namespace McContent\Tests;

use McContent\Support\Platform;
use McContent\Support\Updates;
use PHPUnit\Framework\TestCase;

final class ContentTest extends TestCase
{
    public function test_platform_comes_from_the_files_not_the_egg(): void
    {
        // Your Cobblemon server: a Paper egg running Fabric.
        $cobblemon = ['.cache', '.fabric', 'config', 'mods', 'world', 'server.jar', 'server.properties'];
        $this->assertSame(['loader' => 'fabric', 'kind' => 'mods'], Platform::classify($cobblemon));

        $this->assertSame(['loader' => 'paper', 'kind' => 'plugins'], Platform::classify(['plugins', 'bukkit.yml', 'spigot.yml', 'config'], [], ['paper-global.yml']));
        $this->assertSame(['loader' => 'purpur', 'kind' => 'plugins'], Platform::classify(['plugins', 'purpur.yml', 'version_history.json']));
        $this->assertSame(['loader' => 'neoforge', 'kind' => 'mods'], Platform::classify(['libraries', 'mods'], ['minecraftforge', 'neoforged']));
        $this->assertSame(['loader' => 'forge', 'kind' => 'mods'], Platform::classify(['libraries', 'mods'], ['minecraftforge']));
        $this->assertSame(['loader' => 'velocity', 'kind' => 'plugins'], Platform::classify(['plugins', 'velocity.toml']));
        $this->assertSame(['loader' => 'waterfall', 'kind' => 'plugins'], Platform::classify(['plugins', 'config.yml', 'modules.yml', 'waterfall.yml']));
        $this->assertSame(['loader' => 'bungeecord', 'kind' => 'plugins'], Platform::classify(['plugins', 'config.yml', 'modules.yml']));

        // Vanilla, or never started: nothing to go on.
        $this->assertNull(Platform::classify(['world', 'server.jar', 'server.properties', 'eula.txt']));
        $this->assertNull(Platform::classify([]));
    }

    private static function v(string $id, string $date, string $type = 'release', array $games = ['1.21.1']): array
    {
        return ['id' => $id, 'date_published' => $date, 'version_type' => $type, 'game_versions' => $games];
    }

    public function test_prereleases_are_not_offered_over_a_release(): void
    {
        $installed = self::v('mv431', '2023-01-01T00:00:00Z');
        $candidates = [
            self::v('mv4317pre', '2025-06-01T00:00:00Z', 'beta'),
            self::v('mv4316', '2024-05-01T00:00:00Z'),
            self::v('mv431', '2023-01-01T00:00:00Z'),
        ];

        $this->assertSame('mv4316', Updates::pick($installed, $candidates, false, null)['id']);
        $this->assertSame('mv4317pre', Updates::pick($installed, $candidates, true, null)['id']);
        $this->assertFalse(Updates::acceptable($installed, $candidates[0], false, null));
    }

    public function test_someone_on_a_beta_keeps_getting_betas(): void
    {
        $installed = self::v('b1', '2024-01-01T00:00:00Z', 'beta');

        $this->assertSame('b2', Updates::pick($installed, [self::v('b2', '2024-02-01T00:00:00Z', 'beta')], false, null)['id']);
    }

    public function test_updates_for_another_minecraft_version_are_dropped(): void
    {
        $installed = self::v('f11', '2024-08-01T00:00:00Z');
        $candidates = [
            self::v('f16', '2025-10-01T00:00:00Z', 'release', ['1.21.10']),
            self::v('f12', '2024-09-01T00:00:00Z', 'release', ['1.21', '1.21.1']),
        ];

        $this->assertSame('f12', Updates::pick($installed, $candidates, false, '1.21.1')['id']);
        $this->assertNull(Updates::pick($installed, [$candidates[0]], false, '1.21.1'));
    }

    public function test_never_a_backport_or_the_same_version(): void
    {
        $installed = self::v('new', '2025-01-01T00:00:00Z');

        $this->assertNull(Updates::pick($installed, [self::v('old', '2024-01-01T00:00:00Z')], false, null));
        $this->assertNull(Updates::pick($installed, [$installed], false, null));
        $this->assertTrue(Updates::acceptable($installed, $installed, false, null));
    }
}
