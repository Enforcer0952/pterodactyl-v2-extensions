<?php

namespace McCrash\Tests;

use McCrash\Support\JarInspector;
use McCrash\Support\JarMap;
use McCrash\Support\JarMeta;
use PHPUnit\Framework\TestCase;

final class JarTest extends TestCase
{
    /** @var list<string> */
    private array $temp = [];

    protected function tearDown(): void
    {
        foreach ($this->temp as $file) {
            @unlink($file);
        }
    }

    /** @param array<string, string> $entries */
    private function zip(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mccr-test');
        $this->temp[] = $path;
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return $path;
    }

    public function test_bukkit_plugin_yml(): void
    {
        $yaml = "name: LuckPerms\nmain: me.lucko.luckperms.bukkit.loader.BukkitLoaderPlugin\nversion: '5.4.102'\napi-version: 1.13\ndepend: [Vault, ProtocolLib]\ncommands:\n  lp:\n    main: not.this.One\n";
        $meta = JarMeta::parse('plugin.yml', $yaml);

        $this->assertSame('bukkit', $meta['loader']);
        $this->assertSame(['LuckPerms'], $meta['names']);
        $this->assertSame(['luckperms'], $meta['ids']);
        $this->assertSame(['me.lucko.luckperms.bukkit.loader.BukkitLoaderPlugin'], $meta['mains']);
        $this->assertSame('5.4.102', $meta['version']);
        $this->assertSame('1.13', $meta['api']);
        $this->assertSame(['Vault', 'ProtocolLib'], $meta['depends']);
    }

    public function test_paper_plugin_yml_with_block_depend_and_quotes(): void
    {
        $yaml = "name: \"Fancy Plugin\" # comment\nmain: 'com.fancy.Main'\nversion: 2.0\ndepend:\n  - Vault\n  - 'PlaceholderAPI'\n";
        $meta = JarMeta::parse('paper-plugin.yml', $yaml);

        $this->assertSame(['Fancy Plugin'], $meta['names']);
        $this->assertSame(['com.fancy.Main'], $meta['mains']);
        $this->assertSame(['Vault', 'PlaceholderAPI'], $meta['depends']);
    }

    public function test_a_main_class_with_odd_characters_is_refused(): void
    {
        $meta = JarMeta::parse('plugin.yml', "name: Evil\nmain: com.evil.Main; rm -rf\n");

        $this->assertSame([], $meta['mains']);
    }

    public function test_fabric_mod_json_entrypoints_in_all_shapes(): void
    {
        $json = json_encode([
            'id' => 'sodium-extra', 'name' => 'Sodium Extra', 'version' => '0.5.4',
            'entrypoints' => [
                'client' => ['me.flashyreese.mods.sodiumextra.client.SodiumExtraClientMod', ['value' => 'me.flashyreese.mods.sodiumextra.Other::init', 'adapter' => 'kotlin']],
                'main' => 'bad value',
            ],
            'depends' => ['fabricloader' => '>=0.14', 'sodium' => '*'],
        ]);
        $meta = JarMeta::parse('fabric.mod.json', (string) $json);

        $this->assertSame('fabric', $meta['loader']);
        $this->assertSame(['sodium-extra'], $meta['ids']);
        $this->assertSame(['me.flashyreese.mods.sodiumextra.client.SodiumExtraClientMod', 'me.flashyreese.mods.sodiumextra.Other'], $meta['mains']);
        $this->assertSame(['fabricloader', 'sodium'], $meta['depends']);
    }

    public function test_quilt_mod_json(): void
    {
        $json = json_encode(['quilt_loader' => ['id' => 'qsl', 'version' => '1.0', 'metadata' => ['name' => 'QSL'], 'entrypoints' => ['init' => 'org.quiltmc.qsl.Init']]]);
        $meta = JarMeta::parse('quilt.mod.json', (string) $json);

        $this->assertSame('quilt', $meta['loader']);
        $this->assertSame(['qsl'], $meta['ids']);
        $this->assertSame(['org.quiltmc.qsl.Init'], $meta['mains']);
    }

    public function test_forge_mods_toml_ignores_dependency_tables(): void
    {
        $toml = <<<'TOML'
modLoader="javafml"
loaderVersion="[47,)"
[[mods]]
modId="create"
version="0.5.1.f"
displayName="Create"
[[mods]]
modId='flywheel'
displayName='Flywheel'
[[dependencies.create]]
    modId="forge"
    mandatory=true
[[dependencies.create]]
    modId="geckolib"
    mandatory=true
TOML;
        $meta = JarMeta::parse('META-INF/mods.toml', $toml);

        $this->assertSame('forge', $meta['loader']);
        $this->assertSame(['create', 'flywheel'], $meta['ids']);
        $this->assertSame(['Create', 'Flywheel'], $meta['names']);
        $this->assertSame('0.5.1.f', $meta['version']);
        $this->assertSame(['geckolib'], $meta['depends']);
    }

    public function test_neoforge_and_old_forge_descriptors(): void
    {
        $neo = JarMeta::parse('META-INF/neoforge.mods.toml', "[[mods]]\nmodId=\"jade\"\n");
        $this->assertSame('neoforge', $neo['loader']);
        $this->assertSame(['jade'], $neo['ids']);

        $old = JarMeta::parse('mcmod.info', json_encode([['modid' => 'jei', 'name' => 'Just Enough Items', 'version' => '4.16']]));
        $this->assertSame(['jei'], $old['ids']);
    }

    public function test_bungee_velocity_and_sponge(): void
    {
        $this->assertSame(['com.example.Bungee'], JarMeta::parse('bungee.yml', "name: Bungee\nmain: com.example.Bungee\n")['mains']);
        $this->assertSame(['com.example.Velo'], JarMeta::parse('velocity-plugin.json', '{"id":"velo","main":"com.example.Velo","name":"Velo"}')['mains']);
        $sponge = JarMeta::parse('META-INF/sponge_plugins.json', '{"plugins":[{"id":"sp","name":"Sp","entrypoint":"com.example.Sp","version":"1"}]}');
        $this->assertSame(['sp'], $sponge['ids']);
    }

    public function test_garbage_and_oversized_descriptors_are_refused(): void
    {
        $this->assertNull(JarMeta::parse('plugin.yml', ''));
        $this->assertNull(JarMeta::parse('fabric.mod.json', 'not json'));
        $this->assertNull(JarMeta::parse('plugin.yml', 'name: X'.str_repeat("\n# padding", JarMeta::MAX_BYTES)));
        $this->assertNull(JarMeta::parse('something.txt', 'name: X'));
    }

    public function test_inspector_reads_descriptor_and_packages_from_a_real_zip(): void
    {
        $path = $this->zip([
            'plugin.yml' => "name: ChestShopLite\nmain: com.chestshoplite.ChestShopLite\nversion: 2.1.0\n",
            'com/chestshoplite/ChestShopLite.class' => 'x',
            'com/chestshoplite/listener/SignListener.class' => 'x',
            'com/chestshoplite/listener/SignListener$1.class' => 'x',
            'kotlin/Unit.class' => 'x',
            'META-INF/versions/9/module-info.class' => 'x',
            'Root.class' => 'x',
            'config.yml' => 'a: b',
        ]);
        $info = JarInspector::inspect($path, 'ChestShopLite-2.1.0.jar', 1000, 'plugin');

        $this->assertSame('bukkit', $info['loader']);
        $this->assertSame('plugin', $info['kind']);
        $this->assertSame(['com.chestshoplite', 'com.chestshoplite.listener'], $info['packages']);
        $this->assertFalse($info['coarse']);
        $this->assertSame(['com.chestshoplite.ChestShopLite'], $info['mains']);
    }

    public function test_a_fabric_jar_in_the_plugins_folder_is_still_a_mod(): void
    {
        $path = $this->zip(['fabric.mod.json' => '{"id":"x","version":"1"}']);

        $this->assertSame('mod', JarInspector::inspect($path, 'x.jar', 10, 'plugin')['kind']);
    }

    public function test_inspector_returns_null_for_something_that_is_not_a_zip(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'mccr-test');
        $this->temp[] = $path;
        file_put_contents($path, 'this is not a zip');

        $this->assertNull(JarInspector::inspect($path, 'a.jar', 17, 'plugin'));
    }

    public function test_a_jar_without_a_descriptor_keeps_its_default_kind_and_packages(): void
    {
        $path = $this->zip(['org/lib/Thing.class' => 'x']);
        $info = JarInspector::inspect($path, 'lib.jar', 10, 'mod');

        $this->assertSame('mod', $info['kind']);
        $this->assertSame('unknown', $info['loader']);
        $this->assertSame(['org.lib'], $info['packages']);
    }

    public function test_many_packages_are_cut_down_to_prefixes(): void
    {
        $entries = [];
        for ($i = 0; $i < 900; $i++) {
            $entries[] = 'com/big/mod/part'.$i.'/Thing.class';
        }
        [$packages, $coarse] = JarInspector::packages($entries);

        $this->assertTrue($coarse);
        $this->assertLessThanOrEqual(JarInspector::MAX_PACKAGES, count($packages));
        $this->assertContains('com.big.mod', $packages);

        // A coarse jar still owns everything below its prefixes.
        $big = Jars::jar('big.jar', 'mod', 'forge', ['big'], ['Big'], [], $packages);
        $big['coarse'] = true;
        $map = new JarMap([$big]);
        $this->assertSame('confirmed', $map->forClass('com.big.mod.part512.Thing')['tier']);
    }

    public function test_ownership_when_one_package_is_in_one_jar(): void
    {
        $found = Jars::map()->forClass('com.chestshoplite.listener.SignListener$1');

        $this->assertSame('confirmed', $found['tier']);
        $this->assertSame('ChestShopLite-2.1.0.jar', $found['jars'][0]['file']);
        $this->assertSame('plugin', $found['jars'][0]['kind']);
        $this->assertSame('2.1.0', $found['jars'][0]['version']);
    }

    public function test_a_package_shared_by_two_jars_is_a_guess_unless_a_main_class_decides(): void
    {
        $a = Jars::jar('a.jar', 'plugin', 'bukkit', ['a'], ['A'], ['com.shared.a.Main'], ['com.shared.a', 'com.shared.lib']);
        $b = Jars::jar('b.jar', 'plugin', 'bukkit', ['b'], ['B'], ['com.other.b.Main'], ['com.other.b', 'com.shared.lib']);
        $map = new JarMap([$a, $b]);

        $shared = $map->forClass('com.shared.lib.Util');
        $this->assertSame('likely', $shared['tier']);
        $this->assertCount(2, $shared['jars']);

        // The main class of a.jar is in com.shared.a, so com.shared.a.x belongs to a.jar even if b.jar also had it.
        $b['packages'][] = 'com.shared.a';
        $decided = (new JarMap([$a, $b]))->forClass('com.shared.a.Thing');
        $this->assertSame('confirmed', $decided['tier']);
        $this->assertSame('a.jar', $decided['jars'][0]['file']);
    }

    public function test_a_name_fragment_is_only_ever_likely(): void
    {
        $map = new JarMap([Jars::jar('Essentials-2.20.jar', 'plugin', 'bukkit', ['essentials'], ['Essentials'], ['com.earth2me.essentials.Essentials'], ['com.earth2me.essentials'])]);

        // Not in the package list, but the name looks the same.
        $found = $map->forClass('net.ess3.essentials.Foo');
        $this->assertSame('likely', $found['tier']);
        $this->assertStringContainsString('guess', $found['basis']);

        // Generic words never match.
        $this->assertNull($map->forClass('com.example.plugin.core.util.Thing'));
    }

    public function test_a_jar_named_in_the_trace_is_confirmed_even_when_gone(): void
    {
        $map = Jars::map();

        $here = $map->forJarFile('chestshoplite-2.1.0.JAR');
        $this->assertSame('confirmed', $here['tier']);
        $this->assertTrue($here['jars'][0]['installed']);

        $gone = $map->forJarFile('Removed-1.0.jar');
        $this->assertSame('confirmed', $gone['tier']);
        $this->assertFalse($gone['jars'][0]['installed']);
        $this->assertSame('Removed', $gone['jars'][0]['name']);
    }

    public function test_names_match_ignoring_case_and_punctuation(): void
    {
        $map = Jars::map();

        $this->assertSame('sodium-extra-fabric-0.5.4+mc1.20.1.jar', $map->forName('Sodium_Extra', 'mod')['jars'][0]['file']);
        $this->assertNull($map->forName('chestshoplite', 'mod'), 'a plugin is not a mod');
        $this->assertNull($map->forName('nothing-installed'));
        $this->assertNull($map->forName('---'));
    }

    public function test_file_name_stems(): void
    {
        $this->assertSame('LuckPerms-Bukkit', JarMap::stem('LuckPerms-Bukkit-5.4.102.jar'));
        $this->assertSame('sodium-extra-fabric', JarMap::stem('sodium-extra-fabric-0.5.4+mc1.20.1.jar'));
        $this->assertSame('Vault', JarMap::stem('Vault.jar'));
    }
}
