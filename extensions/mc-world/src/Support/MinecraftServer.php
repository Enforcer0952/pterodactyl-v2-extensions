<?php

namespace McWorld\Support;

use Pterodactyl\Models\Server;

final class MinecraftServer
{
    /**
     * Java servers with a world folder and server.properties: the "minecraft" tag, or the
     * "eula" feature every Java egg has (fresh 2.0 installs do not tag the built-in eggs).
     * Bedrock and proxies (BungeeCord, Waterfall, Velocity) are not.
     */
    public static function isJava(Server $server): bool
    {
        return self::matches(
            array_map('strval', $server->tagSlugs()),
            array_map('strval', (array) ($server->egg?->inherit_features ?? [])),
            (string) ($server->egg?->name ?? ''),
        );
    }

    /**
     * @param  list<string>  $tags
     * @param  list<string>  $features
     */
    public static function matches(array $tags, array $features, string $eggName = ''): bool
    {
        $tags = array_map('strtolower', $tags);
        $features = array_map('strtolower', $features);
        $java = in_array('minecraft', $tags, true) || in_array('eula', $features, true);
        $proxy = in_array('proxy', $tags, true) || preg_match('/bungee|waterfall|velocity/i', $eggName) === 1;

        return $java && ! $proxy && ! in_array('bedrock', $tags, true);
    }
}
