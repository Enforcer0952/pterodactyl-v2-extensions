<?php

namespace McPlayers\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use McPlayers\Services\PlayerActions;
use McPlayers\Services\PlayerStatus;
use McPlayers\Support\MinecraftServer;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * One player across every Minecraft server the user may see players on, and
 * actions on several of them at once. Every server is checked against the user's
 * own permissions on that server.
 */
class NetworkController extends Controller
{
    private const int MAX_SERVERS = 30;

    public function __construct(
        private readonly PlayerStatus $status,
        private readonly PlayerActions $actions,
    ) {}

    public function player(Request $request): JsonResponse
    {
        $name = (string) $request->validate(['name' => ['required', 'string', 'max:64']])['name'];

        $servers = [];
        foreach ($this->servers($request->user()) as $server) {
            // Only answers already cached: asking every server for "list" would be slow.
            $servers[] = $this->status->forServer($server, $name, withStats: false, sendList: false) + [
                'can' => array_map(fn (string $action): bool => $this->actions->allowed($request->user(), $server, $action), array_combine(array_keys(PlayerActions::ACTIONS), array_keys(PlayerActions::ACTIONS))),
            ];
        }

        return new JsonResponse(['name' => $name, 'servers' => $servers]);
    }

    public function action(Request $request): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'string', 'in:kick,ban,pardon,whitelist-add,whitelist-remove,op,deop'],
            'target' => ['required', 'string', 'max:64'],
            'reason' => ['nullable', 'string', 'max:500'],
            'servers' => ['required', 'array', 'min:1', 'max:'.self::MAX_SERVERS],
            'servers.*' => ['string', 'max:36'],
        ]);

        $wanted = array_flip($data['servers']);
        $results = [];
        foreach ($this->servers($request->user()) as $server) {
            if (! isset($wanted[$server->uuid]) && ! isset($wanted[$server->uuidShort])) {
                continue;
            }

            $row = ['server' => ['id' => $server->uuidShort, 'name' => $server->name, 'uuid' => $server->uuid]];
            if (! $this->actions->allowed($request->user(), $server, $data['action'])) {
                $results[] = $row + ['ok' => false, 'message' => 'No permission on this server.'];

                continue;
            }

            try {
                $result = $this->actions->run($server, $request->user(), $data['action'], $data['target'], $data['reason'] ?? null);
                $results[] = $row + ['ok' => true, 'message' => $result['reply'] ?? ($result['state'] === 'offline' ? 'Written to the files; applies on the next start.' : 'Done.')];
            } catch (HttpException $exception) {
                $results[] = $row + ['ok' => false, 'message' => $exception->getMessage()];
            }
        }

        return new JsonResponse(['results' => $results]);
    }

    /**
     * Minecraft servers with player lists this user may view, by name.
     *
     * @return list<Server>
     */
    private function servers(User $user): array
    {
        $query = $user->root_admin ? Server::query() : $user->accessibleServers();

        return $query->with('egg')
            ->orderBy('servers.name')
            ->limit(200)
            ->get()
            ->filter(fn (Server $server): bool => MinecraftServer::hasPlayerLists($server) && $user->can('ext.mc-players.view', $server))
            ->take(self::MAX_SERVERS)
            ->values()
            ->all();
    }
}
