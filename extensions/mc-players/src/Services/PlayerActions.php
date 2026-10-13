<?php

namespace McPlayers\Services;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use McPlayers\Support\ListOutput;
use McPlayers\Support\Validates;
use Pterodactyl\Contracts\Servers\ReadsServerLogs;
use Pterodactyl\Contracts\Servers\ReadsServerState;
use Pterodactyl\Contracts\Servers\SendsServerCommands;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Kick, ban, whitelist or op a player on one server. A running server gets the
 * matching console command; a stopped one has its JSON files edited instead.
 * Used by the players card and by the all-servers view.
 */
class PlayerActions
{
    /** Which extension permission each action needs. */
    public const array ACTIONS = [
        'kick' => 'kick',
        'ban' => 'ban',
        'pardon' => 'ban',
        'ban-ip' => 'ban',
        'pardon-ip' => 'ban',
        'whitelist-add' => 'whitelist',
        'whitelist-remove' => 'whitelist',
        'whitelist-on' => 'whitelist',
        'whitelist-off' => 'whitelist',
        'op' => 'op',
        'deop' => 'op',
    ];

    /** Actions that do not name a player or IP. */
    private const array NO_TARGET = ['whitelist-on', 'whitelist-off'];

    /** How long to wait for the server to answer a command before reading the console. */
    private const int ANSWER_DELAY_MS = 700;

    public function __construct(
        private readonly ReadsServerState $state,
        private readonly SendsServerCommands $commands,
        private readonly ReadsServerLogs $logs,
        private readonly PlayerLists $lists,
    ) {}

    public function allowed(User $user, Server $server, string $action): bool
    {
        return isset(self::ACTIONS[$action]) && $user->can('ext.mc-players.'.self::ACTIONS[$action], $server);
    }

    /**
     * @return array{state: string, reply: ?string}
     *
     * @throws HttpException with a message meant for the user
     */
    public function run(Server $server, User $user, string $action, ?string $rawTarget, ?string $rawReason): array
    {
        try {
            $reason = Validates::reason($rawReason);
        } catch (\InvalidArgumentException $exception) {
            throw new HttpException(422, $exception->getMessage());
        }

        $state = $this->serverState($server);

        if ($state === 'running') {
            $target = $this->target($action, $rawTarget);
            $command = $this->command($action, $target, $reason);
            // The panel's command action records this in the activity log.
            $before = count($this->logs->read($server, 40));
            $this->commands->send($server, $command);

            // Give the server a moment to answer and write its list files.
            usleep(self::ANSWER_DELAY_MS * 1000);
            Cache::forget('ext:mc-players:online:'.$server->uuid);

            $reply = ListOutput::reply(array_slice($this->logs->read($server, 40), max(0, $before - 5)), $command);
            if ($reply !== null && $reply['failed']) {
                throw new HttpException(422, $reply['message']);
            }

            return ['state' => $state, 'reply' => $reply['message'] ?? null];
        }

        if ($state !== 'offline') {
            throw new HttpException(409, 'The server is '.$state.'. Wait until it is running or stopped.');
        }

        // Removing an entry is allowed for any name already on the list, even one that
        // no longer passes today's username rules. Nothing reaches a command here.
        $raw = trim((string) $rawTarget);
        $target = $raw !== '' && $this->lists->listed($server, $action, $raw) ? $raw : $this->target($action, $raw);

        // One edit per server at a time, and the server must still be off when writing,
        // or it would overwrite the change from memory when it stops again.
        try {
            Cache::lock('ext:mc-players:files:'.$server->uuid, 15)->block(10, function () use ($server, $action, $target, $reason, $user): void {
                if (($now = $this->serverState($server)) !== 'offline') {
                    throw new HttpException(409, 'The server is '.$now.' now. Try again once it is running or stopped.');
                }
                // The panel's file write action records this in the activity log.
                $this->lists->applyOffline($server, $action, (string) $target, $reason, $user->username);
            });
        } catch (LockTimeoutException) {
            throw new HttpException(409, 'Someone else is changing the player lists. Try again in a moment.');
        }

        return ['state' => $state, 'reply' => null];
    }

    public function serverState(Server $server): string
    {
        try {
            return $this->state->read($server)->state;
        } catch (DaemonConnectionException) {
            throw new HttpException(502, 'Could not reach this server\'s node.');
        }
    }

    private function target(string $action, ?string $target): ?string
    {
        if (in_array($action, self::NO_TARGET, true)) {
            return null;
        }

        $target = trim((string) $target);
        // Vanilla ban-ip and pardon-ip only understand IPv4 addresses (or, for ban-ip, a name).
        $valid = str_ends_with($action, '-ip')
            ? filter_var($target, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false || ($action === 'ban-ip' && preg_match(Validates::NAME, $target) === 1)
            : preg_match(Validates::NAME, $target) === 1;

        if (! $valid) {
            throw new HttpException(422, str_ends_with($action, '-ip')
                ? 'Enter a valid IPv4 address.'
                : 'Enter a valid Minecraft username (letters, numbers and underscores).');
        }

        return $target;
    }

    private function command(string $action, ?string $target, ?string $reason): string
    {
        $withReason = fn (string $base): string => $reason === null ? $base : $base.' '.$reason;

        return match ($action) {
            'kick' => $withReason('kick '.$target),
            'ban' => $withReason('ban '.$target),
            'pardon' => 'pardon '.$target,
            'ban-ip' => $withReason('ban-ip '.$target),
            'pardon-ip' => 'pardon-ip '.$target,
            'whitelist-add' => 'whitelist add '.$target,
            'whitelist-remove' => 'whitelist remove '.$target,
            'whitelist-on' => 'whitelist on',
            'whitelist-off' => 'whitelist off',
            'op' => 'op '.$target,
            'deop' => 'deop '.$target,
        };
    }
}
