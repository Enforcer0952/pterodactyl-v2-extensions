<?php

namespace McPlayers\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use McPlayers\Support\ListFile;
use McPlayers\Support\ListOutput;
use McPlayers\Support\Stats;
use Pterodactyl\Contracts\Servers\ReadsServerLogs;
use Pterodactyl\Contracts\Servers\ReadsServerState;
use Pterodactyl\Contracts\Servers\SendsServerCommands;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Models\Server;

/**
 * One player's standing on one server: online, whitelisted, operator, banned,
 * first and last seen, and the highlights of their stats file.
 */
class PlayerStatus
{
    /** How long to wait for the server to answer "list" before reading the console. */
    private const int ANSWER_DELAY_MS = 700;

    public function __construct(
        private readonly PlayerLists $lists,
        private readonly ReadsServerState $state,
        private readonly SendsServerCommands $commands,
        private readonly ReadsServerLogs $logs,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forServer(Server $server, string $name, bool $withStats, bool $sendList): array
    {
        try {
            $state = $this->state->read($server)->state;
        } catch (DaemonConnectionException) {
            return ['server' => $this->serverInfo($server), 'state' => 'unreachable', 'error' => 'Could not reach this server\'s node.'];
        }

        $lists = $this->lists->all($server);
        $find = fn (array $rows): ?array => array_values(array_filter($rows, fn (array $row): bool => strcasecmp((string) ($row['name'] ?? ''), $name) === 0))[0] ?? null;

        $known = $find($lists['known']);
        $profile = ListFile::cachedProfile($this->lists->usercache($server), $name);
        $uuid = $profile['uuid'] ?? ($known['uuid'] ?? null);

        $online = null;
        if ($state === 'running') {
            $answer = $this->online($server, $sendList);
            $online = is_array($answer) ? in_array(strtolower($name), array_map('strtolower', $answer['players']), true) : null;
        }

        $result = [
            'server' => $this->serverInfo($server),
            'state' => $state,
            'name' => $profile['name'] ?? ($known['name'] ?? $name),
            'uuid' => $uuid,
            'online_mode' => $lists['online_mode'],
            'online' => $online,
            'whitelist_enabled' => $lists['whitelist_enabled'],
            'whitelisted' => $find($lists['whitelist']) !== null,
            'op' => $find($lists['ops']),
            'ban' => $find($lists['bans']),
            'last_seen' => $known['last_seen'] ?? null,
            'joined' => $known !== null,
            'problems' => $lists['problems'],
        ];

        if ($withStats && is_string($uuid)) {
            $result['stats'] = $this->stats($server, $uuid);
        }

        return $result;
    }

    /**
     * The online list: asked from the server when $send is true, otherwise only a
     * recent answer if one is cached. One "list" per server at a time.
     *
     * @return array{count: int, max: int, players: list<string>}|null
     */
    public function online(Server $server, bool $send): ?array
    {
        $key = 'ext:mc-players:online:'.$server->uuid;
        $online = Cache::get($key);
        if ($online !== null || ! $send) {
            return is_array($online) ? $online : null;
        }

        try {
            $online = Cache::lock($key.':lock', 5)->block(3, function () use ($server, $key) {
                $cached = Cache::get($key);
                if ($cached !== null) {
                    return $cached;
                }

                $before = count($this->logs->read($server, 60));
                $this->commands->send($server, 'list');
                usleep(self::ANSWER_DELAY_MS * 1000);
                $lines = $this->logs->read($server, 60);

                // Only lines that arrived after the command, so an old answer is never reused.
                $parsed = ListOutput::parse(array_slice($lines, max(0, min($before, count($lines) - 15))));

                // A failed parse is remembered too (briefly), so it is not retried on every poll.
                Cache::put($key, $parsed ?? false, $parsed === null ? 15 : 5);

                return $parsed ?? false;
            });
        } catch (LockTimeoutException) {
            $online = Cache::get($key, false);
        }

        return is_array($online) ? $online : null;
    }

    /**
     * @return array<string, int>|null
     */
    private function stats(Server $server, string $uuid): ?array
    {
        return Cache::remember('ext:mc-players:stats:'.$server->uuid.':'.$uuid, 120, function () use ($server, $uuid): ?array {
            $world = trim($this->lists->properties($server)['level-name'] ?? 'world') ?: 'world';
            if (preg_match('#^[\w .\-]{1,64}$#', $world) !== 1) {
                return null;
            }

            $contents = $this->lists->readFile($server, '/'.$world.'/stats', $uuid.'.json');
            $json = $contents === null ? null : json_decode($contents, true);

            return is_array($json) ? Stats::summarize($json) : null;
        });
    }

    /**
     * @return array{id: string, name: string, uuid: string}
     */
    private function serverInfo(Server $server): array
    {
        return ['id' => $server->uuidShort, 'name' => $server->name, 'uuid' => $server->uuid];
    }
}
