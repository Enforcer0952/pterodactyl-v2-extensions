<?php

namespace McPlayers\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use McPlayers\Services\PlayerActions;
use McPlayers\Services\PlayerLists;
use McPlayers\Services\PlayerStatus;
use McPlayers\Support\MinecraftServer;
use Pterodactyl\Models\Server;

class PlayersController extends Controller
{
    public function __construct(
        private readonly PlayerLists $lists,
        private readonly PlayerActions $actions,
        private readonly PlayerStatus $status,
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

        return new JsonResponse(['state' => $state, 'online' => $state === 'running' ? $this->status->online($server, true) : null]);
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
            'action' => ['required', 'string', 'in:'.implode(',', array_keys(PlayerActions::ACTIONS))],
            'target' => ['nullable', 'string', 'max:64'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        abort_unless($this->actions->allowed($request->user(), $server, $data['action']), 403, 'You do not have permission to do that on this server.');

        $result = $this->actions->run($server, $request->user(), $data['action'], $data['target'] ?? null, $data['reason'] ?? null);

        return new JsonResponse([
            'state' => $result['state'],
            'reply' => $result['reply'],
            ...$this->lists->all($server),
        ]);
    }

    /**
     * One player on this server: lists, online, first and last seen, and stats.
     */
    public function player(Request $request, Server $server): JsonResponse
    {
        $this->authorizeView($request, $server);
        $name = (string) $request->validate(['name' => ['required', 'string', 'max:64']])['name'];

        return new JsonResponse($this->status->forServer($server, $name, withStats: true, sendList: true));
    }

    private function serverState(Server $server): string
    {
        return $this->actions->serverState($server);
    }

    private function authorizeView(Request $request, Server $server): void
    {
        abort_unless(MinecraftServer::hasPlayerLists($server), 404);
        abort_unless($request->user()->can('ext.mc-players.view', $server), 403, 'You do not have permission to see players on this server.');
    }
}
