<?php

namespace PalworldTools\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use PalworldTools\Http\Controllers\Concerns\PalworldServer;
use PalworldTools\Services\Saves;
use PalworldTools\Support\Palworld;
use Pterodactyl\Contracts\Servers\ReadsServerState;
use Pterodactyl\Models\Server;

/**
 * The Saves page: worlds, panel backups and the game's own snapshots.
 */
class SavesController extends Controller
{
    use PalworldServer;

    private const string PERMISSION = 'ext.palworld-tools.saves';

    public function __construct(
        private readonly Saves $saves,
        private readonly ReadsServerState $state,
    ) {}

    public function show(Request $request, Server $server): JsonResponse
    {
        $this->authorizeSaves($request, $server, ['file.read']);

        return new JsonResponse($this->payload($server));
    }

    public function backup(Request $request, Server $server): JsonResponse
    {
        $this->authorizeSaves($request, $server, ['file.read', 'file.create', 'file.update', 'file.archive']);
        $data = $request->validate(['world' => ['required', 'string', 'regex:'.Palworld::WORLD_ID]]);

        $result = $this->saves->backup($server, $data['world']);

        return new JsonResponse(['result' => $result] + $this->payload($server));
    }

    public function restore(Request $request, Server $server): JsonResponse
    {
        $this->authorizeSaves($request, $server, ['file.read', 'file.create', 'file.update', 'file.delete', 'file.archive']);
        $data = $request->validate(['file' => ['required', 'string', 'max:100']]);

        return new JsonResponse(['result' => $this->saves->restore($server, $data['file'])] + $this->payload($server));
    }

    public function restoreSnapshot(Request $request, Server $server): JsonResponse
    {
        $this->authorizeSaves($request, $server, ['file.read', 'file.create', 'file.update']);
        $data = $request->validate([
            'world' => ['required', 'string', 'regex:'.Palworld::WORLD_ID],
            'snapshot' => ['required', 'string', 'max:30'],
        ]);

        return new JsonResponse(['result' => $this->saves->restoreSnapshot($server, $data['world'], $data['snapshot'])] + $this->payload($server));
    }

    public function deleteBackup(Request $request, Server $server): JsonResponse
    {
        $this->authorizeSaves($request, $server, ['file.read', 'file.delete']);
        $data = $request->validate(['file' => ['required', 'string', 'max:100']]);
        $this->saves->deleteBackup($server, $data['file']);

        return new JsonResponse($this->payload($server));
    }

    /**
     * @param  list<string>  $core
     */
    private function authorizeSaves(Request $request, Server $server, array $core): void
    {
        $this->ensurePalworld($server);
        $this->ensureCan($request, $server, [self::PERMISSION, ...$core], 'You do not have permission to manage saves on this server.');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Server $server): array
    {
        return ['state' => $this->serverState($this->state, $server)] + $this->saves->overview($server);
    }
}
