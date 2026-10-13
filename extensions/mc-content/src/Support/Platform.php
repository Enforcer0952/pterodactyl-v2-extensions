<?php

namespace McContent\Support;

/**
 * Works out what a Minecraft server actually runs from the files it leaves behind,
 * independent of how its egg is tagged. A Fabric server started from a Paper egg
 * is still a Fabric server, and needs the Mods page, not Plugins.
 */
final class Platform
{
    /**
     * @param  list<string>  $root  names in the server root (files and folders)
     * @param  list<string>  $libraries  names in /libraries/net, when that folder exists
     * @param  list<string>  $config  names in /config, when that folder exists
     * @return array{loader: string, kind: string}|null null when nothing tells
     */
    public static function classify(array $root, array $libraries = [], array $config = []): ?array
    {
        $has = fn (string ...$names): bool => array_intersect(array_map('strtolower', $names), array_map('strtolower', $root)) !== [];

        // Proxies first: their folders also hold a plugins/ directory.
        if ($has('velocity.toml')) {
            return ['loader' => 'velocity', 'kind' => 'plugins'];
        }
        if ($has('waterfall.yml')) {
            return ['loader' => 'waterfall', 'kind' => 'plugins'];
        }
        if ($has('modules.yml') && $has('config.yml') && ! $has('server.properties')) {
            return ['loader' => 'bungeecord', 'kind' => 'plugins'];
        }

        // Mod loaders.
        if ($has('.quilt', 'quilt-server-launch.jar')) {
            return ['loader' => 'quilt', 'kind' => 'mods'];
        }
        if ($has('.fabric', 'fabric-server-launch.jar', 'fabric-server-launcher.properties')) {
            return ['loader' => 'fabric', 'kind' => 'mods'];
        }
        if (in_array('neoforged', array_map('strtolower', $libraries), true)) {
            return ['loader' => 'neoforge', 'kind' => 'mods'];
        }
        if (in_array('minecraftforge', array_map('strtolower', $libraries), true)) {
            return ['loader' => 'forge', 'kind' => 'mods'];
        }

        // Plugin servers, most specific fork first.
        if ($has('purpur.yml')) {
            return ['loader' => 'purpur', 'kind' => 'plugins'];
        }
        if (in_array('paper-global.yml', array_map('strtolower', $config), true) || $has('paper.yml', 'version_history.json')) {
            return ['loader' => 'paper', 'kind' => 'plugins'];
        }
        if ($has('spigot.yml')) {
            return ['loader' => 'spigot', 'kind' => 'plugins'];
        }
        if ($has('bukkit.yml')) {
            return ['loader' => 'bukkit', 'kind' => 'plugins'];
        }

        return null;
    }
}
