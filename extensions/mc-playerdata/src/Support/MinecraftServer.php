<?php

namespace McPlayerData\Support;

use Pterodactyl\Models\Server;

final class MinecraftServer
{
    /**
     * Java servers: the "minecraft" tag, or the "eula" feature every Java egg has
     * (fresh 2.0 installs do not tag the built-in eggs). Bedrock and proxies
     * (BungeeCord, Waterfall, Velocity) keep no player data of their own.
     */
    public static function isJava(Server $server): bool
    {
        $tags = array_map('strtolower', $server->tagSlugs());
        $features = array_map('strtolower', (array) ($server->egg?->inherit_features ?? []));
        $java = in_array('minecraft', $tags, true) || in_array('eula', $features, true);
        $proxy = in_array('proxy', $tags, true) || preg_match('/bungee|waterfall|velocity/i', (string) ($server->egg?->name ?? '')) === 1;

        return $java && ! $proxy && ! in_array('bedrock', $tags, true);
    }

    /**
     * Folders that can hold a world's player files: 1.21.9 and later use players/data,
     * older versions playerdata.
     *
     * @return list<string> paths below the world folder, newest layout first
     */
    public static function playerDataFolders(): array
    {
        return ['players/data', 'playerdata'];
    }

    /**
     * Which folder to use. The newer one wins, unless it holds no player files and the
     * older one does (a server upgraded in place, before its first save).
     *
     * @param  array<string, list<string>>  $found  file names per existing folder, in preference order
     */
    public static function pickPlayerDataFolder(array $found): ?string
    {
        $hasPlayers = fn (array $names): bool => array_filter($names, fn (string $name): bool => preg_match('/\.dat$/D', $name) === 1) !== [];

        foreach ($found as $folder => $names) {
            if ($hasPlayers($names)) {
                return (string) $folder;
            }
        }

        return array_key_first($found);
    }

    /**
     * The folder of the main world, from level-name in server.properties. Only a plain
     * folder name is accepted, so nothing can point outside the server.
     */
    public static function worldFolder(?string $properties): ?string
    {
        $name = 'world';
        foreach (preg_split('/\R/', (string) $properties) ?: [] as $line) {
            if (str_starts_with($line, 'level-name=')) {
                $name = trim(substr($line, strlen('level-name=')));
                break;
            }
        }

        $name = $name === '' ? 'world' : $name;

        return preg_match('/^(?!\.)[\w .\-]{1,64}$/uD', $name) === 1 && ! str_contains($name, '..') ? $name : null;
    }
}
