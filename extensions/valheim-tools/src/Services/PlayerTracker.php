<?php

namespace ValheimTools\Services;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Pterodactyl\Contracts\Servers\ReadsServerLogs;
use Pterodactyl\Contracts\Servers\ReadsServerState;
use Pterodactyl\Models\Server;
use ValheimTools\Support\ConsoleDelta;
use ValheimTools\Support\LogParser;
use ValheimTools\Support\PlayerIds;

/**
 * Who is online, read from the console. Valheim's dedicated server has no player list
 * command, so the joins and leaves in the last 100 console lines are replayed on top of
 * what was known at the last read. Someone has to be looking (the Players card polls)
 * for joins to be seen; a join that scrolled out of the console before anyone looked
 * is not known.
 */
class PlayerTracker
{
    private const int TTL = 86400;

    /** A read newer than this many seconds is reused. */
    private const int MIN_AGE = 3;

    public function __construct(
        private readonly ReadsServerLogs $logs,
        private readonly ReadsServerState $state,
        private readonly KnownPlayers $known,
    ) {}

    /**
     * @return array{state: string, players: list<array{name: string, id: ?string, steam_id: ?string, steam: bool}>}
     */
    public function online(Server $server): array
    {
        $state = $this->state->read($server)->state;
        $key = 'ext:valheim-tools:tracker:'.$server->uuid;

        if ($state !== 'running') {
            Cache::forget($key);

            return ['state' => $state, 'players' => []];
        }

        try {
            return Cache::lock($key.':lock', 20)->block(5, function () use ($server, $key, $state): array {
                $saved = Cache::get($key);
                if (is_array($saved) && isset($saved['players']) && time() - (int) ($saved['at'] ?? 0) < self::MIN_AGE) {
                    return ['state' => $state, 'players' => $saved['players']];
                }

                $lines = $this->logs->read($server, ReadsServerLogs::MAX_LINES);
                $parser = is_array($saved) && is_array($saved['parser'] ?? null) ? LogParser::import($saved['parser']) : new LogParser;
                $previous = is_array($saved) && is_array($saved['lines'] ?? null) ? array_values(array_filter($saved['lines'], 'is_string')) : null;
                $fresh = $previous === null ? $lines : ConsoleDelta::since($previous, $lines);

                $parser->feed($fresh);

                $players = [];
                foreach ($parser->players() as $player) {
                    $players[] = [
                        'name' => $player['name'],
                        'id' => $player['id'],
                        'steam_id' => $player['id'] !== null ? PlayerIds::steamId($player['id']) : null,
                        'steam' => $player['steam'],
                    ];
                }

                // New players are recorded; players who stay get their last seen time refreshed.
                $this->known->seen($server, $players);

                Cache::put($key, ['parser' => $parser->export(), 'lines' => $lines, 'players' => $players, 'at' => time()], self::TTL);

                return ['state' => $state, 'players' => $players];
            });
        } catch (LockTimeoutException) {
            $saved = Cache::get($key);

            return ['state' => $state, 'players' => is_array($saved) ? ($saved['players'] ?? []) : []];
        }
    }
}
