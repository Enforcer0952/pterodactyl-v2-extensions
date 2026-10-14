<?php

namespace ValheimTools\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Pterodactyl\Contracts\Servers\ReadsServerState;
use Pterodactyl\Models\Server;
use ValheimTools\Http\Controllers\Concerns\ValheimServer;
use ValheimTools\Services\Mods;
use ValheimTools\Services\ThunderstoreClient;

/**
 * The Mods page: BepInEx mods on the server, and Thunderstore search and install.
 */
class ModsController extends Controller
{
    use ValheimServer;

    private const string PERMISSION = 'ext.valheim-tools.mods';

    public function __construct(
        private readonly Mods $mods,
        private readonly ThunderstoreClient $store,
        private readonly ReadsServerState $state,
    ) {}

    public function index(Request $request, Server $server): JsonResponse
    {
        $this->authorizeMods($request, $server, []);

        return new JsonResponse($this->payload($server));
    }

    public function updates(Request $request, Server $server): JsonResponse
    {
        $this->authorizeMods($request, $server, []);

        return new JsonResponse(['updates' => $this->mods->available($server) ? $this->mods->updates($server) : (object) []]);
    }

    public function search(Request $request, Server $server): JsonResponse
    {
        $this->authorizeMods($request, $server, []);
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'ordering' => ['sometimes', 'string', 'in:'.implode(',', ThunderstoreClient::ORDERINGS)],
            'page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        return new JsonResponse($this->store->search((string) ($data['q'] ?? ''), $data['ordering'] ?? 'most-downloaded', (int) ($data['page'] ?? 1)));
    }

    public function install(Request $request, Server $server): JsonResponse
    {
        $this->authorizeMods($request, $server, ['file.create', 'file.update', 'file.delete', 'file.archive']);
        $data = $request->validate([
            'package' => ['required', 'string', 'max:300'],
            'version' => ['nullable', 'string', 'regex:/^\d{1,9}\.\d{1,9}\.\d{1,9}$/'],
        ]);
        $this->ensureStopped($this->state, $server, 'Stop the server to install mods.');

        $result = $this->mods->install($server, $data['package'], $data['version'] ?? null);

        return new JsonResponse(['result' => $result] + $this->payload($server));
    }

    public function remove(Request $request, Server $server): JsonResponse
    {
        $this->authorizeMods($request, $server, ['file.delete']);
        $data = $request->validate(['kind' => ['required', 'string', 'in:'.implode(',', Mods::KINDS)], 'name' => ['required', 'string', 'max:255']]);
        $this->ensureStopped($this->state, $server, 'Stop the server to remove mods.');

        $this->mods->remove($server, $data['kind'], $data['name']);

        return new JsonResponse($this->payload($server));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Server $server): array
    {
        $available = $this->mods->available($server);

        return [
            'available' => $available,
            'mods' => $available ? $this->mods->installed($server) : [],
            'state' => $this->serverState($this->state, $server),
        ];
    }

    /**
     * @param  list<string>  $extra  panel permissions this action also needs
     */
    private function authorizeMods(Request $request, Server $server, array $extra): void
    {
        $this->ensureValheim($server);
        $this->ensureCan($request, $server, [self::PERMISSION, 'file.read', ...$extra], 'You do not have permission to manage mods on this server.');
    }
}
