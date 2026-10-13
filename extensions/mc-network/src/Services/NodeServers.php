<?php

namespace McNetwork\Services;

use McNetwork\Support\Eggs;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;

/**
 * The servers on a proxy's node that a user may see, in the shape the analyzer uses.
 */
class NodeServers
{
    private const int LIMIT = 500;

    /**
     * Root admins see every server on the node, everyone else the servers they own
     * or have access to. The proxy itself is not in the list.
     *
     * @return array{servers: list<array{id: string, name: string, ports: list<int>, primary: ?int, java: bool, proxy: bool}>, models: array<string, Server>, self_ports: list<int>, local_hosts: list<string>}
     */
    public function forProxy(Server $proxy, User $user): array
    {
        $query = $user->root_admin ? Server::query() : $user->accessibleServers();

        $servers = [];
        $models = [];
        foreach ($query->where('servers.node_id', $proxy->node_id)
            ->where('servers.id', '!=', $proxy->id)
            ->with(['allocations', 'egg.tags'])
            ->orderBy('servers.name')
            ->limit(self::LIMIT)
            ->get() as $server) {
            $tags = array_values($server->tagSlugs());
            $features = array_values((array) ($server->egg?->inherit_features ?? []));
            $ports = [];
            foreach ($server->allocations as $allocation) {
                $ports[] = (int) $allocation->port;
            }
            $primary = $server->allocation?->port;
            if ($primary !== null) {
                $ports[] = (int) $primary;
            }

            $models[(string) $server->uuidShort] = $server;
            $servers[] = [
                'id' => (string) $server->uuidShort,
                'name' => (string) $server->name,
                'ports' => array_values(array_unique($ports)),
                'primary' => $primary === null ? null : (int) $primary,
                'java' => Eggs::isJavaBackend($tags, $features),
                'proxy' => Eggs::isProxy($tags),
            ];
        }

        $selfPorts = [];
        foreach ($proxy->allocations as $allocation) {
            $selfPorts[] = (int) $allocation->port;
        }
        if ($proxy->allocation?->port !== null) {
            $selfPorts[] = (int) $proxy->allocation->port;
        }

        return [
            'servers' => $servers,
            'models' => $models,
            'self_ports' => array_values(array_unique($selfPorts)),
            'local_hosts' => $this->localHosts($proxy),
        ];
    }

    /**
     * Names and addresses that point at the proxy's own node: its host name and every
     * address or alias the node has allocations on.
     *
     * @return list<string>
     */
    private function localHosts(Server $proxy): array
    {
        $hosts = [];
        $fqdn = $proxy->node?->fqdn;
        if (is_string($fqdn) && $fqdn !== '') {
            $hosts[] = $fqdn;
        }

        $allocations = Allocation::query()->where('node_id', $proxy->node_id)->get(['ip', 'ip_alias']);
        foreach ($allocations as $allocation) {
            foreach ([$allocation->ip, $allocation->ip_alias] as $host) {
                if (is_string($host) && $host !== '') {
                    $hosts[] = $host;
                }
            }
        }

        return array_values(array_unique(array_map('strtolower', $hosts)));
    }
}
