<?php

namespace McNetwork\Support;

/**
 * Finds what is wrong with a proxy's backend list by comparing it with the servers
 * on the proxy's node.
 *
 * A panel server is an array with: id, name, ports (every port it has an allocation
 * on), primary (its main port), java (a Java server a proxy can use) and proxy.
 */
final class Analyzer
{
    private const array LOCAL = ['localhost', '127.0.0.1', '0.0.0.0', '::1'];

    /**
     * @param  list<array{name: string, address: string}>  $entries
     * @param  list<string>  $priorities
     * @param  list<array{host: string, server: string}>  $forcedHosts
     * @param  list<array{id: string, name: string, ports: list<int>, primary: ?int, java: bool, proxy: bool}>  $panelServers  the servers on the node the user may access, without the proxy
     * @param  list<int>  $selfPorts  the ports of the proxy server itself
     * @param  list<string>  $localHosts  extra host names and addresses that mean this node
     * @return array{entries: list<array{host: ?string, port: ?int, local: bool, server: ?array{id: string, name: string}, problems: list<array{code: string, level: string, message: string}>}>, problems: list<array{code: string, level: string, message: string, name: string}>}
     */
    public static function analyze(array $entries, array $priorities, array $forcedHosts, array $panelServers, array $selfPorts, array $localHosts = []): array
    {
        $local = array_flip(array_map(strtolower(...), [...self::LOCAL, ...$localHosts]));
        $parsed = [];
        $groups = [];
        foreach ($entries as $index => $entry) {
            $address = Address::parse($entry['address']);
            $parsed[$index] = $address;
            if ($address === null) {
                continue;
            }
            $isLocal = isset($local[strtolower(trim($address['host'], '[]'))]);
            $groups[$isLocal ? 'local:'.$address['port'] : strtolower($address['host']).':'.$address['port']][] = $index;
        }

        $rows = [];
        foreach ($entries as $index => $entry) {
            $address = $parsed[$index];
            $row = ['host' => $address['host'] ?? null, 'port' => $address['port'] ?? null, 'local' => false, 'server' => null, 'problems' => []];

            if ($address === null) {
                $row['problems'][] = self::problem('invalid_address', 'error', 'This is not a host and port. BungeeCord cannot connect to it.');
                $rows[] = $row;

                continue;
            }

            $row['local'] = isset($local[strtolower(trim($address['host'], '[]'))]);
            $port = $address['port'];

            if (! $row['local']) {
                $row['problems'][] = self::problem('external', 'info', 'Not on this node, so it cannot be matched with a panel server.');
            } elseif (in_array($port, $selfPorts, true)) {
                $row['problems'][] = self::problem('self', 'error', 'Points at the proxy itself (port '.$port.'). Players would be sent back to the proxy.');
            } else {
                $match = self::match($panelServers, $port);
                if ($match === null) {
                    $row['problems'][] = self::problem('no_server', 'warning', 'No panel server you can access uses port '.$port.'.');
                } else {
                    $row['server'] = ['id' => $match['id'], 'name' => $match['name']];
                    if ($match['proxy']) {
                        $row['problems'][] = self::problem('proxy', 'error', $match['name'].' is a proxy, not a game server.');
                    } elseif (! $match['java']) {
                        $row['problems'][] = self::problem('not_minecraft', 'error', $match['name'].' is not a Minecraft Java server.');
                    }
                }
            }

            $key = $row['local'] ? 'local:'.$port : strtolower($address['host']).':'.$port;
            $others = array_values(array_filter($groups[$key] ?? [], fn (int $other): bool => $other !== $index));
            if ($others !== []) {
                $names = array_map(fn (int $other): string => $entries[$other]['name'], $others);
                $row['problems'][] = self::problem('duplicate_port', 'warning', 'Same address as '.self::list($names).'.');
            }

            $rows[] = $row;
        }

        $names = [];
        foreach ($entries as $entry) {
            $names[$entry['name']] = true;
        }

        $problems = [];
        foreach ($priorities as $name) {
            if (! isset($names[$name])) {
                $problems[] = self::problem('unknown_priority', 'error', 'The priority list uses "'.$name.'", but no server has that name.') + ['name' => $name];
            }
        }
        foreach ($forcedHosts as $forced) {
            if (! isset($names[$forced['server']])) {
                $problems[] = self::problem('unknown_forced_host', 'error', 'The forced host '.$forced['host'].' sends players to "'.$forced['server'].'", but no server has that name.') + ['name' => $forced['server']];
            }
        }
        if ($priorities === [] && $entries !== []) {
            $problems[] = self::problem('no_priorities', 'warning', 'The priority list is empty, so new players have no default server.') + ['name' => ''];
        }

        return ['entries' => $rows, 'problems' => $problems];
    }

    /**
     * @param  list<array{id: string, name: string, ports: list<int>, primary: ?int, java: bool, proxy: bool}>  $servers
     * @return array{id: string, name: string, ports: list<int>, primary: ?int, java: bool, proxy: bool}|null
     */
    private static function match(array $servers, int $port): ?array
    {
        $found = null;
        foreach ($servers as $server) {
            if (! in_array($port, $server['ports'], true)) {
                continue;
            }
            if ($server['primary'] === $port) {
                return $server;
            }
            $found ??= $server;
        }

        return $found;
    }

    /**
     * @return array{code: string, level: string, message: string}
     */
    private static function problem(string $code, string $level, string $message): array
    {
        return ['code' => $code, 'level' => $level, 'message' => $message];
    }

    /**
     * @param  list<string>  $names
     */
    private static function list(array $names): string
    {
        $shown = array_slice($names, 0, 3);
        $more = count($names) - count($shown);

        return implode(', ', $shown).($more > 0 ? ' and '.$more.' more' : '');
    }
}
