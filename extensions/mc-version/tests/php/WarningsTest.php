<?php

namespace McVersion\Tests;

use McVersion\Support\Mismatches;
use McVersion\Support\Software;
use McVersion\Support\Warnings;
use PHPUnit\Framework\TestCase;

final class WarningsTest extends TestCase
{
    /**
     * @param  list<array{code: string, severity: string, message: string}>  $warnings
     * @return array<string, string>
     */
    private static function severities(array $warnings): array
    {
        return array_column($warnings, 'severity', 'code');
    }

    public function test_a_plain_upgrade_needs_no_confirmation(): void
    {
        $warnings = Warnings::for('paper', '1.20.6', 'paper', '1.21.4');

        $this->assertSame(['upgrade' => 'info'], self::severities($warnings));
        $this->assertFalse(Warnings::needsConfirmation($warnings));
    }

    public function test_downgrade_is_dangerous(): void
    {
        $warnings = Warnings::for('paper', '1.21.4', 'paper', '1.20.6');

        $this->assertSame('danger', self::severities($warnings)['downgrade'] ?? null);
        $this->assertTrue(Warnings::needsConfirmation($warnings));
    }

    public function test_paper_to_fabric(): void
    {
        $warnings = Warnings::for('paper', '1.21.1', 'fabric', '1.21.1');

        $this->assertSame('danger', self::severities($warnings)['family_switch'] ?? null);
        $this->assertStringContainsString('Plugins do not run on Fabric', $warnings[0]['message']);
        $this->assertTrue(Warnings::needsConfirmation($warnings));
    }

    public function test_fabric_to_paper(): void
    {
        $warnings = Warnings::for('fabric', '1.21.1', 'paper', '1.21.1');

        $this->assertSame('danger', self::severities($warnings)['family_switch'] ?? null);
        $this->assertStringContainsString('Mods do not run on Paper', $warnings[0]['message']);
    }

    public function test_paper_to_purpur_is_a_fork_switch(): void
    {
        $warnings = Warnings::for('paper', '1.21.1', 'purpur', '1.21.1');

        $this->assertSame(['fork_switch' => 'info'], self::severities($warnings));
        $this->assertFalse(Warnings::needsConfirmation($warnings));
    }

    public function test_vanilla_switches(): void
    {
        $this->assertSame('warning', self::severities(Warnings::for('vanilla', '1.21.1', 'paper', '1.21.1'))['family_switch'] ?? null);
        $this->assertSame('danger', self::severities(Warnings::for('paper', '1.21.1', 'vanilla', '1.21.1'))['family_switch'] ?? null);
    }

    public function test_experimental_target(): void
    {
        $warnings = Warnings::for('paper', '1.21.4', 'paper', '26.4-snapshot-4', false);

        $this->assertSame('warning', self::severities($warnings)['experimental'] ?? null);
        $this->assertTrue(Warnings::needsConfirmation($warnings));
    }

    public function test_unknown_current_state(): void
    {
        $warnings = Warnings::for(null, null, 'paper', '1.21.4');

        $this->assertSame(['unknown_current' => 'info'], self::severities($warnings));
        $this->assertFalse(Warnings::needsConfirmation($warnings));
    }

    public function test_same_version(): void
    {
        $this->assertSame(['same' => 'info'], self::severities(Warnings::for('paper', '1.21.4', 'paper', '1.21.4')));
    }

    public function test_downgrade_across_a_switch_reports_both(): void
    {
        $codes = array_keys(self::severities(Warnings::for('paper', '1.21.4', 'fabric', '1.20.1')));

        $this->assertContains('downgrade', $codes);
        $this->assertContains('family_switch', $codes);
    }

    public function test_mismatch_fabric_files_on_a_paper_egg(): void
    {
        $found = Mismatches::find('paper', ['software' => 'fabric', 'version' => '1.21.1'], ['MINECRAFT_VERSION' => '1.21.1'], true, 'server.jar');

        $this->assertSame(['egg_software'], array_column($found, 'code'));
        $this->assertStringContainsString('Fabric', $found[0]['message']);
    }

    public function test_mismatch_version_variable(): void
    {
        $found = Mismatches::find('paper', ['software' => 'paper', 'version' => '1.21.4'], ['MINECRAFT_VERSION' => '1.16.5'], true, 'server.jar');

        $this->assertSame(['version_variable'], array_column($found, 'code'));
    }

    public function test_no_mismatch_for_latest_or_forks(): void
    {
        $this->assertSame([], Mismatches::find('paper', ['software' => 'purpur', 'version' => '1.21.4'], ['MINECRAFT_VERSION' => 'latest'], true, 'server.jar'));
        $this->assertSame([], Mismatches::find(null, ['software' => 'fabric', 'version' => null], [], true, 'server.jar'));
        $this->assertSame([], Mismatches::find('paper', ['software' => null, 'version' => null], ['MINECRAFT_VERSION' => '1.21.4'], true, 'server.jar'));
    }

    public function test_missing_jar(): void
    {
        $found = Mismatches::find(null, ['software' => null, 'version' => null], [], false, 'paper.jar');

        $this->assertSame(['jar_missing'], array_column($found, 'code'));
        $this->assertStringContainsString('paper.jar', $found[0]['message']);
    }

    public function test_egg_software_from_tags_and_name(): void
    {
        $this->assertSame('paper', Software::fromEgg(['minecraft'], 'Paper'));
        $this->assertSame('purpur', Software::fromEgg([], 'Purpur'));
        $this->assertSame('neoforge', Software::fromEgg(['neoforge'], 'Minecraft'));
        $this->assertSame('vanilla', Software::fromEgg(['minecraft'], 'Vanilla Minecraft'));
        $this->assertNull(Software::fromEgg(['minecraft'], 'Java Minecraft'));
    }

    public function test_jar_name_rules(): void
    {
        $this->assertSame('server.jar', Software::jarName(null, null));
        $this->assertSame('paper.jar', Software::jarName('paper.jar', null));
        $this->assertSame('mc-1.21.jar', Software::jarName(' mc-1.21.jar ', null));
        $this->assertSame('server.jar', Software::jarName('../evil.jar', null));
        $this->assertSame('server.jar', Software::jarName('a/b.jar', null));
        $this->assertSame('server.jar', Software::jarName('server.jar; rm -rf /', null));
        $this->assertSame('server.jar', Software::jarName('.hidden.jar', null));
        $this->assertSame('server.jar', Software::jarName('server..jar', null));
        $this->assertSame('custom.jar', Software::jarName('', 'java -Xms128M -jar custom.jar nogui'));
        $this->assertSame('server.jar', Software::jarName('', 'java -jar {{SERVER_JARFILE}}'));
    }

    public function test_software_names_from_logs(): void
    {
        $this->assertSame('bukkit', Software::fromName('CraftBukkit'));
        $this->assertSame('paper', Software::fromName('PaperMC'));
        $this->assertSame('purpur', Software::fromName('Purpur'));
        $this->assertNull(Software::fromName('Leaf'));
        $this->assertSame('Paper', Software::label('paper'));
        $this->assertSame('Unknown', Software::label(null));
    }
}
