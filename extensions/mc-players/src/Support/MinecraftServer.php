<?php

namespace McPlayers\Support;

use Pterodactyl\Models\Server;

final class MinecraftServer
{
    /**
     * Java servers that keep their own whitelist, ops and bans: the "minecraft" tag,
     * or the "eula" feature every Java egg has (fresh 2.0 installs do not tag the
     * built-in eggs). Bedrock and proxies (BungeeCord, Waterfall, Velocity) are not.
     */
    /**
     * Whether a server can be asked anything right now: it is not suspended,
     * installing, restoring or failed, and its node is not in maintenance.
     */
    public static function isAvailable(Server $server): bool
    {
        return $server->status === null && ! (bool) ($server->node?->maintenance_mode ?? false);
    }

    public static function hasPlayerLists(Server $server): bool
    {
        $tags = array_map('strtolower', $server->tagSlugs());
        $features = array_map('strtolower', (array) ($server->egg?->inherit_features ?? []));
        $java = in_array('minecraft', $tags, true) || in_array('eula', $features, true);
        $proxy = in_array('proxy', $tags, true) || preg_match('/bungee|waterfall|velocity/i', (string) ($server->egg?->name ?? '')) === 1;

        return $java && ! $proxy && ! in_array('bedrock', $tags, true);
    }
}
