<?php

namespace TerrariaTools\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Pterodactyl\Contracts\Servers\ReadsServerState;
use Pterodactyl\Contracts\Servers\SendsServerCommands;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Models\Server;
use Symfony\Component\HttpKernel\Exception\HttpException;
use TerrariaTools\Http\Controllers\Concerns\TerrariaServer;
use TerrariaTools\Services\Bans;
use TerrariaTools\Services\OnlinePlayers;
use TerrariaTools\Support\Terraria;

/**
 * Online players (read with the console command "playing"), kick and ban, and the
 * ban list file.
 */
class PlayersController extends Controller
{
    use TerrariaServer;

    public function __construct(
        private readonly ReadsServerState $state,
        private readonly SendsServerCommands $commands,
        private readonly OnlinePlayers $online,
        private readonly Bans $bans,
    ) {}

    public function online(Request $request, Server $server): JsonResponse
    {
        $this->ensureTerraria($server);
        $this->ensureCan($request, $server, ['ext.terraria-tools.view', 'control.console'], 'You need the console permission to see who is online.');

        $state = $this->serverState($this->state, $server);
        if ($state !== 'running') {
            return new JsonResponse(['state' => $state, 'players' => null]);
        }

        $answer = $this->online->get($server, $request->boolean('refresh'));

        return new JsonResponse(['state' => $state, 'players' => $this->shape($answer['players'])]);
    }

    public function action(Request $request, Server $server): JsonResponse
    {
        $this->ensureTerraria($server);

        $data = $request->validate([
            'action' => ['required', 'string', 'in:kick,ban'],
            'name' => ['required', 'string', 'max:200'],
        ]);

        $this->ensureCan($request, $server, ['ext.terraria-tools.'.$data['action'], 'control.console']);

        $name = Terraria::playerName($data['name']);
        if ($name === null) {
            throw new HttpException(422, 'Enter a player name.');
        }

        if ($this->serverState($this->state, $server) !== 'running') {
            throw new HttpException(409, 'Start the server first.');
        }

        // Only players who are on the server right now.
        if (! $this->isOnline($server, $name)) {
            throw new HttpException(409, $name.' is not online any more.');
        }

        // The panel's command action records this in the activity log.
        $this->commands->send($server, $data['action'].' '.$name);
        $this->online->forget($server);

        return new JsonResponse(['sent' => $data['action'], 'name' => $name]);
    }

    public function bans(Request $request, Server $server): JsonResponse
    {
        $this->authorizeBans($request, $server);

        return new JsonResponse($this->banPayload($request, $server));
    }

    public function unban(Request $request, Server $server): JsonResponse
    {
        $this->authorizeBans($request, $server);
        $this->ensureCan($request, $server, 'file.update');
        $this->ensureStopped($this->state, $server, 'Stop the server to remove a ban.');

        $id = (string) $request->validate(['id' => ['required', 'string', 'max:200']])['id'];
        $this->bans->remove($server, $id);

        return new JsonResponse($this->banPayload($request, $server));
    }

    /**
     * @return array<string, mixed>
     */
    private function banPayload(Request $request, Server $server): array
    {
        return $this->bans->all($server) + [
            'state' => $this->serverState($this->state, $server),
            'editable' => $request->user()->can('file.update', $server),
        ];
    }

    private function authorizeBans(Request $request, Server $server): void
    {
        $this->ensureTerraria($server);
        // The list holds addresses, so it needs the ban permission and file access.
        $this->ensureCan($request, $server, ['ext.terraria-tools.ban', 'file.read', 'file.read-content'], 'You do not have permission to see the ban list.');
    }

    private function isOnline(Server $server, string $name): bool
    {
        foreach ([false, true] as $refresh) {
            try {
                $players = $this->online->get($server, $refresh)['players'];
            } catch (DaemonConnectionException) {
                throw new HttpException(502, 'Could not reach this server\'s node.');
            }
            if ($players !== null && in_array($name, $players, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>|null  $players
     * @return list<array{name: string}>|null
     */
    private function shape(?array $players): ?array
    {
        return $players === null ? null : array_map(fn (string $name): array => ['name' => $name], $players);
    }
}
