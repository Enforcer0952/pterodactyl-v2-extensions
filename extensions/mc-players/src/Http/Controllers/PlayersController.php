<?php

namespace McPlayers\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use McPlayers\Services\PlayerLists;
use McPlayers\Support\ListOutput;
use McPlayers\Support\Validates;
use Pterodactyl\Contracts\Servers\ReadsServerLogs;
use Pterodactyl\Contracts\Servers\ReadsServerState;
use Pterodactyl\Contracts\Servers\SendsServerCommands;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Models\Server;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PlayersController extends Controller
{
    /** Which extension permission each action needs. */
    private const array ACTIONS = [
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

    /**
     * Who is online right now. Runs "list" on the server and reads the answer from
     * the console. Answers are shared for a few seconds, so several people looking
     * at the console do not flood it with list commands.
     */
    public function online(Request $request, Server $server): JsonResponse
    {
        $this->authorizeView($request, $server);

        $state = $this->serverState($server);
        if ($state !== 'running') {
            return new JsonResponse(['state' => $state, 'online' => null]);
        }

        $online = Cache::remember('ext:mc-players:online:'.$server->uuid, 5, function () use ($server) {
            $this->commands->send($server, 'list');
            usleep(self::ANSWER_DELAY_MS * 1000);

            return ListOutput::parse($this->logs->read($server, 60));
        });

        return new JsonResponse(['state' => $state, 'online' => $online]);
    }

    public function lists(Request $request, Server $server): JsonResponse
    {
        $this->authorizeView($request, $server);

        return new JsonResponse([
            'state' => $this->serverState($server),
            ...$this->lists->all($server),
        ]);
    }

    /**
     * Kick, ban, whitelist or op a player. A running server gets the matching console
     * command; a stopped one has its JSON files edited instead (kicking needs it running).
     */
    public function action(Request $request, Server $server): JsonResponse
    {
        $this->authorizeView($request, $server);

        $data = $request->validate([
            'action' => ['required', 'string', 'in:'.implode(',', array_keys(self::ACTIONS))],
            'target' => ['nullable', 'string', 'max:64'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $action = $data['action'];
        abort_unless(
            $request->user()->can('ext.mc-players.'.self::ACTIONS[$action], $server),
            403,
            'You do not have permission to do that on this server.',
        );

        $target = $this->target($action, $data['target'] ?? null);
        $reason = Validates::reason($data['reason'] ?? null);
        $state = $this->serverState($server);

        if ($state === 'running') {
            $command = $this->command($action, $target, $reason);
            $this->commands->send($server, $command);
            Activity::event('server:console.command')->property('command', $command)->log();

            // Give the server a moment to write its list files before they are read back.
            usleep(self::ANSWER_DELAY_MS * 1000);
            Cache::forget('ext:mc-players:online:'.$server->uuid);
        } elseif ($state === 'offline') {
            $this->lists->applyOffline($server, $action, (string) $target, $reason, $request->user()->username);
            Activity::event('server:file.write')->property('file', 'player lists ('.$action.' '.$target.')')->log();
        } else {
            throw new HttpException(409, 'The server is '.$state.'. Wait until it is running or stopped.');
        }

        return new JsonResponse([
            'state' => $state,
            ...$this->lists->all($server),
        ]);
    }

    private function target(string $action, ?string $target): ?string
    {
        if (in_array($action, self::NO_TARGET, true)) {
            return null;
        }

        $target = trim((string) $target);
        $valid = str_ends_with($action, '-ip')
            ? filter_var($target, FILTER_VALIDATE_IP) !== false || ($action === 'ban-ip' && preg_match(Validates::NAME, $target) === 1)
            : preg_match(Validates::NAME, $target) === 1;

        if (! $valid) {
            throw new HttpException(422, str_ends_with($action, '-ip')
                ? 'Enter a valid IP address.'
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

    private function serverState(Server $server): string
    {
        try {
            return $this->state->read($server)->state;
        } catch (DaemonConnectionException) {
            throw new HttpException(502, 'Could not reach this server\'s node.');
        }
    }

    private function authorizeView(Request $request, Server $server): void
    {
        // Java servers: the "minecraft" tag, or the "eula" feature every Java egg has
        // (fresh 2.0 installs do not tag the built-in eggs). Bedrock is excluded.
        $tags = array_map('strtolower', $server->tagSlugs());
        $features = array_map('strtolower', (array) ($server->egg?->inherit_features ?? []));
        $java = in_array('minecraft', $tags, true) || in_array('eula', $features, true);
        // Proxies (BungeeCord, Waterfall, Velocity) have no whitelist, bans or ops of their own.
        $proxy = in_array('proxy', $tags, true) || preg_match('/bungee|waterfall|velocity/i', (string) ($server->egg?->name ?? '')) === 1;
        abort_unless($java && ! $proxy && ! in_array('bedrock', $tags, true), 404);
        abort_unless($request->user()->can('ext.mc-players.view', $server), 403, 'You do not have permission to see players on this server.');
    }
}
