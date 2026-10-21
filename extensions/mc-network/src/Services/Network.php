<?php

namespace McNetwork\Services;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use McNetwork\Support\Analyzer;
use McNetwork\Support\Drift;
use McNetwork\Support\InvalidConfig;
use McNetwork\Support\Motd;
use McNetwork\Support\ProxyConfig;
use McNetwork\Support\Validate;
use Pterodactyl\Contracts\Servers\ReadsServerState;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Builds what the Network page shows and saves what it edits.
 */
class Network
{
    /** Reading live states stops after this many seconds, so a slow node cannot stall the page. */
    private const float STATE_BUDGET = 4.0;

    private const int MAX_STATES = 40;

    public function __construct(
        private readonly ConfigFile $file,
        private readonly NodeServers $nodes,
        private readonly ReadsServerState $state,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function load(Server $proxy, User $user): array
    {
        $read = $this->file->read($proxy);
        $base = ['proxy_state' => $this->stateOf($proxy), 'servers' => [], 'priorities' => [], 'forced_hosts' => [], 'problems' => [], 'panel_servers' => [], 'has_listener' => false, 'hash' => ''];

        if ($read['contents'] === null) {
            return ['status' => $read['velocity'] ? 'velocity' : 'missing'] + $base;
        }

        try {
            $config = ProxyConfig::parse($read['contents']);
        } catch (InvalidConfig $exception) {
            return ['status' => 'invalid', 'message' => $exception->getMessage()] + $base;
        }

        $servers = $config->servers();
        $priorities = $config->priorities();
        $forced = $config->forcedHosts();
        $node = $this->nodes->forProxy($proxy, $user);
        $analysis = Analyzer::analyze($servers, $priorities, $forced, $node['servers'], $node['self_ports'], $node['local_hosts']);
        $states = $this->states($analysis['entries'], $node['models']);

        $rows = [];
        foreach ($servers as $index => $server) {
            $row = $analysis['entries'][$index];
            if ($row['server'] !== null) {
                $row['server']['state'] = $states[$row['server']['id']] ?? null;
            }
            $rows[] = $server + ['motd_plain' => Motd::plain($server['motd'])] + $row;
        }

        return [
            'status' => 'ok',
            'hash' => ConfigFile::hash($read['contents']),
            'servers' => $rows,
            'priorities' => $priorities,
            'forced_hosts' => $forced,
            'problems' => $analysis['problems'],
            'panel_servers' => $this->choices($node['servers']),
            'has_listener' => $config->hasListener(),
        ] + $base;
    }

    /**
     * The problems of a draft that has not been saved yet.
     *
     * @param  array{servers: list<array<string, mixed>>, priorities: list<string>, forced_hosts: list<array{host: string, server: string}>}  $draft
     * @return array<string, mixed>
     */
    public function check(Server $proxy, User $user, array $draft): array
    {
        $node = $this->nodes->forProxy($proxy, $user);
        $analysis = Analyzer::analyze($draft['servers'], $draft['priorities'], $draft['forced_hosts'], $node['servers'], $node['self_ports'], $node['local_hosts']);

        return ['entries' => $analysis['entries'], 'problems' => $analysis['problems'], 'panel_servers' => $this->choices($node['servers'])];
    }

    /**
     * @param  array{servers: list<array<string, mixed>>, priorities: list<string>, forced_hosts: list<array{host: string, server: string}>}  $draft
     * @return array<string, mixed>
     */
    public function save(Server $proxy, User $user, array $draft, string $hash): array
    {
        try {
            Cache::lock('mc-network:'.$proxy->uuid, 30)->block(10, function () use ($proxy, $draft, $hash): void {
                $this->saveLocked($proxy, $draft, $hash);
            });
        } catch (LockTimeoutException) {
            throw new HttpException(409, 'Another save is in progress. Try again in a moment.');
        }

        return $this->load($proxy, $user) + ['saved' => true];
    }

    /**
     * @param  array{servers: list<array<string, mixed>>, priorities: list<string>, forced_hosts: list<array{host: string, server: string}>}  $draft
     */
    private function saveLocked(Server $proxy, array $draft, string $hash): void
    {
        $read = $this->file->read($proxy);
        if ($read['contents'] === null) {
            throw new HttpException(404, 'config.yml was not found. Start the proxy once so it creates the file.');
        }
        if (! hash_equals(ConfigFile::hash($read['contents']), $hash)) {
            throw new HttpException(409, 'config.yml changed since you opened this page. Reload the page and make your changes again.');
        }

        $risky = Drift::risky($read['contents']);
        if ($risky !== []) {
            throw new HttpException(422, 'config.yml has plain values that BungeeCord reads as yes/no or as numbers ('.implode(', ', array_slice($risky, 0, 3)).'). This page would turn them into text, so nothing was saved. Write true or false, or put quotes around them, in the file manager and try again.');
        }

        try {
            $config = ProxyConfig::parse($read['contents']);
            $errors = Validate::draft($draft, $config->servers());
            if ($errors !== []) {
                throw new HttpException(422, implode(' ', array_slice($errors, 0, 3)));
            }
            $new = $config->replace($draft['servers'], $draft['priorities'], $draft['forced_hosts'])->dump();
            // The result must read back as the same backend list before it replaces the file.
            $check = ProxyConfig::parse($new);
        } catch (InvalidConfig $exception) {
            throw new HttpException(422, $exception->getMessage());
        }
        $drift = Drift::changes($read['contents'], $new);
        if ($drift !== []) {
            throw new HttpException(422, 'Saving would also change other settings in config.yml ('.implode(', ', array_slice($drift, 0, 3)).'), so nothing was saved. Edit the file in the file manager instead.');
        }
        if ($check->priorities() !== $draft['priorities'] || count($check->servers()) !== count($draft['servers'])) {
            throw new HttpException(500, 'The new config.yml could not be written safely. Nothing was changed.');
        }

        $this->file->write($proxy, $read['contents'], $new);
    }

    /**
     * Java servers that can be added, with the port to use.
     *
     * @param  list<array{id: string, name: string, ports: list<int>, primary: ?int, java: bool, proxy: bool}>  $servers
     * @return list<array{id: string, name: string, port: int}>
     */
    private function choices(array $servers): array
    {
        $choices = [];
        foreach ($servers as $server) {
            $port = $server['primary'] ?? ($server['ports'][0] ?? null);
            if ($server['java'] && $port !== null) {
                $choices[] = ['id' => $server['id'], 'name' => $server['name'], 'port' => $port];
            }
        }

        return $choices;
    }

    /**
     * @param  list<array{server: ?array{id: string, name: string}}>  $rows
     * @param  array<string, Server>  $models
     * @return array<string, ?string> live state by server id, null when it could not be read
     */
    private function states(array $rows, array $models): array
    {
        $ids = [];
        foreach ($rows as $row) {
            if ($row['server'] !== null) {
                $ids[$row['server']['id']] = true;
            }
        }

        $states = [];
        $started = microtime(true);
        $failed = false;
        foreach (array_slice(array_keys($ids), 0, self::MAX_STATES) as $id) {
            // Every backend is on the proxy's node: once one read fails, the rest would too.
            if ($failed || ! isset($models[$id]) || microtime(true) - $started > self::STATE_BUDGET) {
                $states[$id] = null;

                continue;
            }
            $states[$id] = $this->stateOf($models[$id]);
            $failed = $states[$id] === null;
        }

        return $states;
    }

    private function stateOf(Server $server): ?string
    {
        try {
            return $this->state->read($server)->state;
        } catch (Throwable) {
            return null;
        }
    }
}
