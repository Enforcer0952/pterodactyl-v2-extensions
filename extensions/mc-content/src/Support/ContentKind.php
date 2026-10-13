<?php

namespace McContent\Support;

use Pterodactyl\Models\Server;

/**
 * The two kinds of content this extension manages, with the Modrinth loaders and
 * server directory that belong to each.
 */
enum ContentKind: string
{
    case Plugins = 'plugins';
    case Mods = 'mods';

    /**
     * Modrinth loader slugs accepted for this kind, in the order they are tried
     * when nothing more specific is known about the server.
     *
     * @return list<string>
     */
    public function loaders(): array
    {
        return match ($this) {
            self::Plugins => ['paper', 'purpur', 'folia', 'spigot', 'bukkit', 'sponge', 'velocity', 'waterfall', 'bungeecord'],
            self::Mods => ['fabric', 'forge', 'neoforge', 'quilt'],
        };
    }

    /**
     * Proxy loaders run plugins that do not depend on a Minecraft version, so
     * searches for them ignore the version filter.
     */
    public static function isProxy(string $loader): bool
    {
        return in_array($loader, ['velocity', 'waterfall', 'bungeecord'], true);
    }

    /**
     * The directory inside the server's files that holds this kind of content.
     */
    public function directory(): string
    {
        return match ($this) {
            self::Plugins => '/plugins',
            self::Mods => '/mods',
        };
    }

    /**
     * Loaders whose builds can also run plugins built for another loader. Paper
     * runs Spigot and Bukkit plugins, Purpur and Folia run Paper plugins, and so on.
     *
     * @return list<string>
     */
    public static function compatibleLoaders(string $loader): array
    {
        return match ($loader) {
            'purpur' => ['purpur', 'paper', 'spigot', 'bukkit'],
            'paper' => ['paper', 'spigot', 'bukkit'],
            'folia' => ['folia'],
            'spigot' => ['spigot', 'bukkit'],
            'quilt' => ['quilt', 'fabric'],
            'waterfall' => ['waterfall', 'bungeecord'],
            default => [$loader],
        };
    }

    /**
     * Work out the server's loader from its egg tags first, then from the egg's name.
     */
    public function detectLoader(Server $server): ?string
    {
        // NeoForge before Forge, and Purpur/Folia before Paper, so the more specific
        // loader wins when an egg is tagged or named with both.
        $specificFirst = match ($this) {
            self::Plugins => ['velocity', 'waterfall', 'bungeecord', 'purpur', 'folia', 'paper', 'spigot', 'bukkit', 'sponge'],
            self::Mods => ['neoforge', 'forge', 'quilt', 'fabric'],
        };

        $tags = array_map('strtolower', $server->tagSlugs());
        foreach ($specificFirst as $loader) {
            if (in_array($loader, $tags, true)) {
                return $loader;
            }
        }

        $name = strtolower((string) ($server->egg?->name ?? ''));
        foreach ($specificFirst as $loader) {
            if (str_contains($name, $loader)) {
                return $loader;
            }
        }

        return null;
    }

    /**
     * Read the Minecraft version from the usual egg variables. Returns null when
     * the egg tracks "latest" or does not expose a version at all.
     */
    public static function detectGameVersion(Server $server): ?string
    {
        $candidates = ['MINECRAFT_VERSION', 'MC_VERSION', 'VANILLA_VERSION', 'GAME_VERSION', 'VERSION'];
        $values = [];

        foreach ($server->variables as $variable) {
            $value = $variable->server_value ?? $variable->default_value;
            if (is_string($value)) {
                $values[strtoupper((string) $variable->env_variable)] = trim($value);
            }
        }

        foreach ($candidates as $key) {
            $value = $values[$key] ?? null;
            // A bare VERSION variable often holds a loader or build number, so it only
            // counts when it looks like a Minecraft release (1.x or the yearly 2x.x).
            $pattern = $key === 'VERSION'
                ? '/^(1\.\d{1,2}(\.\d{1,2})?|2\d\.\d{1,2}(\.\d{1,2})?)$/'
                : '/^\d+(\.\d+){1,2}([-.][A-Za-z0-9.]+)?$/';
            if ($value !== null && preg_match($pattern, $value) === 1) {
                return $value;
            }
        }

        return null;
    }
}
