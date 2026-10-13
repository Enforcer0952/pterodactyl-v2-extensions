<?php

namespace McVersion\Support;

/**
 * The server software this page can install, and the families it groups what it
 * finds on a server into.
 */
final class Software
{
    /** What can be installed, in the order the picker lists them. */
    public const array INSTALLABLE = ['paper', 'purpur', 'fabric', 'vanilla'];

    private const array LABELS = [
        'paper' => 'Paper',
        'purpur' => 'Purpur',
        'folia' => 'Folia',
        'pufferfish' => 'Pufferfish',
        'spigot' => 'Spigot',
        'bukkit' => 'CraftBukkit',
        'fabric' => 'Fabric',
        'quilt' => 'Quilt',
        'forge' => 'Forge',
        'neoforge' => 'NeoForge',
        'vanilla' => 'Vanilla',
    ];

    public static function label(?string $software): string
    {
        return $software === null ? 'Unknown' : (self::LABELS[$software] ?? ucfirst($software));
    }

    public static function isInstallable(string $software): bool
    {
        return in_array($software, self::INSTALLABLE, true);
    }

    /**
     * "plugins" servers load plugins, "mods" servers load mods, "vanilla" loads neither.
     */
    public static function family(?string $software): ?string
    {
        return match ($software) {
            'paper', 'purpur', 'folia', 'pufferfish', 'spigot', 'bukkit' => 'plugins',
            'fabric', 'quilt', 'forge', 'neoforge' => 'mods',
            'vanilla' => 'vanilla',
            default => null,
        };
    }

    /** The software a log line or a version_history.json names, such as "Paper" or "CraftBukkit". */
    public static function fromName(string $name): ?string
    {
        $name = strtolower($name);

        return match (true) {
            $name === 'craftbukkit', $name === 'bukkit' => 'bukkit',
            $name === 'papermc' => 'paper',
            isset(self::LABELS[$name]) => $name,
            default => null,
        };
    }

    /**
     * What a server's egg is meant for, from its tags and name. Null for generic eggs.
     *
     * @param  list<string>  $tags
     */
    public static function fromEgg(array $tags, string $eggName): ?string
    {
        $tags = array_map('strtolower', $tags);
        $name = strtolower($eggName);

        foreach (['purpur', 'folia', 'paper', 'neoforge', 'forge', 'quilt', 'fabric', 'spigot', 'vanilla'] as $candidate) {
            if (in_array($candidate, $tags, true) || str_contains($name, $candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /** The file name of the server jar, from the SERVER_JARFILE variable or the startup command. */
    public static function jarName(?string $variable, ?string $startup): string
    {
        foreach ([$variable, self::jarFromStartup($startup)] as $candidate) {
            if (is_string($candidate) && self::isJarName(trim($candidate))) {
                return trim($candidate);
            }
        }

        return 'server.jar';
    }

    /** A plain file name in the server root that ends in .jar. */
    public static function isJarName(string $name): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._+\-]{0,100}\.jar$/D', $name) === 1 && ! str_contains($name, '..');
    }

    private static function jarFromStartup(?string $startup): ?string
    {
        return $startup !== null && preg_match('/-jar\s+([^\s{}$"\']+)/', $startup, $match) === 1 ? $match[1] : null;
    }
}
