<?php

namespace McVersion\Support;

use Pterodactyl\Models\Server;

final class MinecraftServer
{
    /**
     * Java servers that run a server jar: the "minecraft" tag, or the "eula" feature
     * every Java egg has (fresh installs do not tag the built-in eggs). Bedrock and
     * proxies (BungeeCord, Waterfall, Velocity) are not.
     */
    public static function isJava(Server $server): bool
    {
        $tags = array_map('strtolower', $server->tagSlugs());
        $features = array_map('strtolower', (array) ($server->egg?->inherit_features ?? []));
        $java = in_array('minecraft', $tags, true) || in_array('eula', $features, true);
        $proxy = in_array('proxy', $tags, true) || preg_match('/bungee|waterfall|velocity/i', (string) ($server->egg?->name ?? '')) === 1;

        return $java && ! $proxy && ! in_array('bedrock', $tags, true);
    }
}
