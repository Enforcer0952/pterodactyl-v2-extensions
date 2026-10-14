<?php

namespace McCrash\Support;

use Pterodactyl\Models\Server;

final class MinecraftServer
{
    /**
     * Java servers and the proxies in front of them: the "minecraft" tag, a proxy tag, or the "eula" feature
     * every Java egg has (fresh 2.0 installs do not tag the built-in eggs). Bedrock has no crash reports like these.
     */
    public static function isJavaOrProxy(Server $server): bool
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
        $proxy = count(array_intersect($tags, ['proxy', 'bungeecord', 'velocity', 'waterfall'])) > 0
            || preg_match('/bungee|waterfall|velocity/i', $eggName) === 1;

        return ($java || $proxy) && ! in_array('bedrock', $tags, true);
    }
}
