<?php

namespace McContent\Tests;

use McContent\Support\FileChange;
use McContent\Support\JarName;
use McContent\Support\Platform;
use McContent\Support\TimeBudget;
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

    public function test_normal_jar_names_are_accepted(): void
    {
        foreach (['Plugin-1.0+mc1.21.jar', 'My%20Mod.jar', '[1.21] Thing (beta).jar', '_Fancy_.jar', "Dr.Who's-Mod.jar", 'Mod.jar.disabled', 'Ünï.JAR'] as $name) {
            $this->assertTrue(JarName::isSafe($name), $name);
        }
    }

    public function test_jar_names_cannot_leave_the_folder(): void
    {
        foreach (['../evil.jar', 'a/b.jar', 'a\\b.jar', '..jar', '.hidden.jar', "a\0.jar", "a\n.jar", 'a.jar ', ' a.jar', 'notajar.txt', '', 'x.jar.disabled.sh', str_repeat('a', 220).'.jar', 'a:b.jar'] as $name) {
            $this->assertFalse(JarName::isSafe($name), json_encode($name));
        }
        $this->assertFalse(JarName::isSafe('Mod.jar.disabled', false));
    }

    public function test_download_names_are_normalised(): void
    {
        $this->assertSame('Plugin-1.0+mc1.21.jar', JarName::normalize('Plugin-1.0+mc1.21.jar', 'abc'));
        $this->assertSame('evil.jar', JarName::normalize('../../evil.jar', 'abc'));
        $this->assertSame('Thing.jar', JarName::normalize("Thing\u{202E}.jar", 'abc'));
        $this->assertSame('abc12345.jar', JarName::normalize('...jar', 'abc12345'));
        $this->assertSame('a.b.jar', JarName::normalize('a..b.jar', 'x'));
        $this->assertTrue(JarName::isSafe(JarName::normalize("we\x01ird/..\\name.jar", 'x')));
    }

    public function test_a_jar_changed_by_hand_is_noticed(): void
    {
        $this->assertSame(FileChange::SAME, FileChange::compare(100, 1000, 100, 1000));
        $this->assertSame(FileChange::CHANGED, FileChange::compare(100, 1000, 101, 1000));
        $this->assertSame(FileChange::CHANGED, FileChange::compare(100, 1000, 100, 2000));
        // Records from before size and time were kept, or made by an install.
        $this->assertSame(FileChange::ADOPT, FileChange::compare(null, null, 100, 1000));
        $this->assertSame(FileChange::ADOPT, FileChange::compare(100, null, 100, 1000));
        $this->assertSame(FileChange::CHANGED, FileChange::compare(100, null, 90, 1000));
        // A listing without a time cannot contradict the record.
        $this->assertSame(FileChange::SAME, FileChange::compare(100, 1000, 100, null));
    }

    public function test_listing_times_become_unix_seconds(): void
    {
        $this->assertSame(1767225600, FileChange::timestamp('2026-01-01T00:00:00Z'));
        $this->assertSame(5, FileChange::timestamp(5));
        $this->assertNull(FileChange::timestamp(''));
        $this->assertNull(FileChange::timestamp('not a date'));
        $this->assertNull(FileChange::timestamp(null));
    }

    public function test_install_time_budget(): void
    {
        $now = 1000.0;
        $budget = new TimeBudget(120, function () use (&$now): float {
            return $now;
        });

        $this->assertSame(120, $budget->remaining());
        $this->assertSame(120, $budget->slice(120));
        $this->assertSame(30, $budget->slice(30));
        $this->assertFalse($budget->exhausted());

        $now += 100.5;
        $this->assertSame(19, $budget->remaining());
        $this->assertSame(19, $budget->slice(120));

        $now += 30;
        $this->assertTrue($budget->exhausted());
        $this->assertSame(0, $budget->remaining());
        $this->assertSame(1, $budget->slice(120));
    }
}
