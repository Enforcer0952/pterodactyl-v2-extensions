<?php

use PHPUnit\Framework\TestCase;
use ValheimTools\Support\PackageLayout;

class PackageLayoutTest extends TestCase
{
    private function f(string $name): array
    {
        return ['name' => $name, 'file' => true];
    }

    private function d(string $name): array
    {
        return ['name' => $name, 'file' => false];
    }

    private function targets(array $plan): array
    {
        return array_column($plan['moves'], 'to', 'from');
    }

    public function test_loose_dll_package(): void
    {
        // Real layout of DigiTeam-AutoOven.
        $plan = PackageLayout::plan([$this->f('manifest.json'), $this->f('README.md'), $this->f('CHANGELOG.md'), $this->f('icon.png'), $this->f('AutoOven.dll')], 'DigiTeam-AutoOven');
        $this->assertNull($plan['error']);
        $this->assertSame(['manifest.json' => 'plugins/DigiTeam-AutoOven/manifest.json', 'AutoOven.dll' => 'plugins/DigiTeam-AutoOven/AutoOven.dll'], $this->targets($plan));
        $this->assertSame(['README.md', 'CHANGELOG.md', 'icon.png'], $plan['skipped']);
    }

    public function test_plugins_folder_package(): void
    {
        // Real layout of Batera-Doorbell.
        $plan = PackageLayout::plan([$this->f('manifest.json'), $this->d('plugins')], 'Batera-Doorbell');
        $this->assertSame('plugins/Batera-Doorbell', $plan['moves'][0]['to']);
        $this->assertTrue($plan['moves'][0]['dir']);
        $this->assertSame('plugins/Batera-Doorbell/manifest.json', $plan['moves'][1]['to']);
    }

    public function test_folder_case_is_ignored(): void
    {
        $plan = PackageLayout::plan([$this->d('Plugins'), $this->d('PATCHERS'), $this->d('monomod')], 'A-B');
        $this->assertSame(['Plugins' => 'plugins/A-B', 'PATCHERS' => 'patchers/A-B', 'monomod' => 'monomod/A-B'], $this->targets($plan));
    }

    public function test_config_goes_to_bepinex_config(): void
    {
        $plan = PackageLayout::plan([$this->d('config'), $this->f('A.dll')], 'A-B');
        $targets = $this->targets($plan);
        $this->assertSame('config', $targets['config']);
        $this->assertTrue($plan['moves'][array_search('config', array_column($plan['moves'], 'from'))]['merge']);
    }

    public function test_core_is_never_installed(): void
    {
        $plan = PackageLayout::plan([$this->d('core'), $this->d('Core'), $this->f('A.dll')], 'A-B');
        $this->assertSame(['A.dll' => 'plugins/A-B/A.dll'], $this->targets($plan));
        $this->assertSame(['core', 'Core'], $plan['skipped']);
    }

    public function test_package_with_its_own_bepinex_folder_is_refused(): void
    {
        $plan = PackageLayout::plan([$this->d('BepInEx'), $this->f('winhttp.dll')], 'A-B');
        $this->assertNotNull($plan['error']);
        $this->assertSame([], $plan['moves']);
    }

    public function test_backslash_names(): void
    {
        $plan = PackageLayout::plan([$this->f('plugins\\Mod.dll'), $this->f('plugins\\assets\\b.bundle'), $this->f('manifest.json')], 'A-B');
        $this->assertSame([
            'plugins\\Mod.dll' => 'plugins/A-B/Mod.dll',
            'plugins\\assets\\b.bundle' => 'plugins/A-B/assets/b.bundle',
            'manifest.json' => 'plugins/A-B/manifest.json',
        ], $this->targets($plan));
    }

    public function test_traversal_is_refused(): void
    {
        foreach (['../evil.dll', 'plugins/../../evil.dll', 'plugins\\..\\..\\evil.dll', '/etc/passwd', '\\evil.dll', 'C:\\evil.dll', "a\nb.dll", "a\x00.dll"] as $name) {
            $plan = PackageLayout::plan([$this->f($name)], 'A-B');
            $this->assertNotNull($plan['error'], json_encode($name));
            $this->assertSame([], $plan['moves']);
        }
    }

    public function test_bad_folder_name(): void
    {
        $this->assertNotNull(PackageLayout::plan([$this->f('A.dll')], '../x')['error']);
        $this->assertNotNull(PackageLayout::plan([$this->f('A.dll')], 'A-B/../C')['error']);
    }

    public function test_nothing_to_install(): void
    {
        $this->assertNotNull(PackageLayout::plan([$this->f('README.md'), $this->f('icon.png')], 'A-B')['error']);
    }

    public function test_hidden_files_are_skipped(): void
    {
        $plan = PackageLayout::plan([$this->f('.DS_Store'), $this->f('A.dll')], 'A-B');
        $this->assertSame(['.DS_Store'], $plan['skipped']);
    }

    public function test_other_folders_go_next_to_the_plugin(): void
    {
        $plan = PackageLayout::plan([$this->d('Assets'), $this->f('A.dll')], 'A-B');
        $this->assertSame('plugins/A-B/Assets', $this->targets($plan)['Assets']);
    }
}
