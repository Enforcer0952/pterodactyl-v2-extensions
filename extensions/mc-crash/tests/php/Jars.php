<?php

namespace McCrash\Tests;

use McCrash\Support\JarMap;

/** A small set of installed plugins and mods for the tests. */
final class Jars
{
    /** @return list<array<string, mixed>> */
    public static function all(): array
    {
        return [
            self::jar('ChestShopLite-2.1.0.jar', 'plugin', 'bukkit', ['chestshoplite'], ['ChestShopLite'], ['com.chestshoplite.ChestShopLite'], ['com.chestshoplite', 'com.chestshoplite.listener', 'com.chestshoplite.tasks', 'com.chestshoplite.util'], '2.1.0'),
            self::jar('ShopGUIPlus-1.99.jar', 'plugin', 'paper', ['shopguiplus'], ['ShopGUIPlus'], ['net.brcdev.shopgui.ShopGuiPlugin'], ['net.brcdev.shopgui'], '1.99'),
            self::jar('DynmapLite-1.4.jar', 'plugin', 'paper', ['dynmaplite'], ['DynmapLite'], ['com.example.dynmaplite.DynmapLite'], ['com.example.dynmaplite', 'com.example.dynmaplite.render'], '1.4'),
            self::jar('FancyHolograms-2.4.0.jar', 'plugin', 'paper', ['fancyholograms'], ['FancyHolograms'], ['de.oliver.fancyholograms.FancyHolograms'], ['de.oliver.fancyholograms', 'de.oliver.fancyholograms.api'], '2.4.0'),
            self::jar('RegionCompress-0.9.jar', 'plugin', 'bukkit', ['regioncompress'], ['RegionCompress'], ['com.example.regioncompress.RegionCompress'], ['com.example.regioncompress', 'com.example.regioncompress.io'], '0.9'),
            self::jar('sodium-extra-fabric-0.5.4+mc1.20.1.jar', 'mod', 'fabric', ['sodium-extra'], ['Sodium Extra'], ['me.flashyreese.mods.sodiumextra.client.SodiumExtraClientMod'], ['me.flashyreese.mods.sodiumextra.client', 'me.flashyreese.mods.sodiumextra.mixin.gui'], '0.5.4'),
            self::jar('create-1.20.1-0.5.1.f.jar', 'mod', 'forge', ['create'], ['Create'], [], ['com.simibubi.create', 'com.simibubi.create.content.contraptions'], '0.5.1.f'),
            self::jar('modernfix-forge-5.19.jar', 'mod', 'forge', ['modernfix'], ['ModernFix'], [], ['org.embeddedt.modernfix', 'org.embeddedt.modernfix.common.mixin.perf'], '5.19'),
            self::jar('embeddium-1.0.jar', 'mod', 'forge', ['embeddium'], ['Embeddium'], [], ['org.embeddedt.embeddium', 'org.embeddedt.embeddium.impl'], '1.0'),
        ];
    }

    public static function map(): JarMap
    {
        return new JarMap(self::all());
    }

    /**
     * @param  list<string>  $ids
     * @param  list<string>  $names
     * @param  list<string>  $mains
     * @param  list<string>  $packages
     * @return array<string, mixed>
     */
    public static function jar(string $file, string $kind, string $loader, array $ids, array $names, array $mains, array $packages, ?string $version = null): array
    {
        return [
            'file' => $file, 'size' => 1000, 'kind' => $kind, 'loader' => $loader, 'ids' => $ids, 'names' => $names, 'version' => $version,
            'mains' => $mains, 'api' => null, 'depends' => [], 'packages' => $packages, 'coarse' => false,
        ];
    }

    public static function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/fixtures/'.$name);
    }
}
