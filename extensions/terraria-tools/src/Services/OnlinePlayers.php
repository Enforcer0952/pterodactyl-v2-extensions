<?php

namespace TerrariaTools\Services;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Pterodactyl\Contracts\Servers\ReadsServerLogs;
use Pterodactyl\Contracts\Servers\SendsServerCommands;
use Pterodactyl\Models\Server;
use TerrariaTools\Support\ConsoleDelta;
use TerrariaTools\Support\PlayingOutput;

/**
 * Who is online: runs "playing" and reads the answer from the console log. The
 * answer is shared for a few seconds, and only one command runs per server at a
 * time, so several people watching the console do not flood it.
 */
class OnlinePlayers
{
    /** How long an answer is reused. */
    private const int TTL = 5;

    /** A refresh asked for within this many seconds of the last answer reuses it. */
    private const int MIN_AGE = 2;

    private const int POLLS = 8;

    private const int POLL_MS = 250;

    public function __construct(
        private readonly SendsServerCommands $commands,
        private readonly ReadsServerLogs $logs,
    ) {}

    /**
     * @return array{players: list<string>|null, at: int} players is null when the console gave no readable answer
     */
    public function get(Server $server, bool $refresh = false): array
    {
        $key = $this->key($server);
        $cached = Cache::get($key);
        if (is_array($cached) && (! $refresh || time() - $cached['at'] < self::MIN_AGE)) {
            return $cached;
        }

        try {
            return Cache::lock($key.':lock', 15)->block(5, function () use ($server, $key, $refresh): array {
                // Someone else may have just asked while this request waited for the lock.
                $cached = Cache::get($key);
                if (is_array($cached) && time() - $cached['at'] < ($refresh ? self::MIN_AGE : self::TTL)) {
                    return $cached;
                }

                $before = $this->logs->read($server, ReadsServerLogs::MAX_LINES);
                $this->commands->send($server, 'playing');

                $players = null;
                for ($poll = 0; $poll < self::POLLS && $players === null; $poll++) {
                    usleep(self::POLL_MS * 1000);
                    // Only lines that arrived after the command, so an old answer is never reused.
                    $players = PlayingOutput::parse(ConsoleDelta::since($before, $this->logs->read($server, ReadsServerLogs::MAX_LINES)));
                }

                $result = ['players' => $players, 'at' => time()];
                // A failed read is remembered briefly, so it is not retried on every poll.
                Cache::put($key, $result, $players === null ? 3 : self::TTL);

                return $result;
            });
        } catch (LockTimeoutException) {
            $cached = Cache::get($key);

            return is_array($cached) ? $cached : ['players' => null, 'at' => time()];
        }
    }

    public function forget(Server $server): void
    {
        Cache::forget($this->key($server));
    }

    private function key(Server $server): string
    {
        return 'ext:terraria-tools:online:'.$server->uuid;
    }
}
