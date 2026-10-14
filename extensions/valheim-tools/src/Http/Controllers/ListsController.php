<?php

namespace ValheimTools\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Pterodactyl\Contracts\Servers\ReadsServerState;
use Pterodactyl\Models\Server;
use ValheimTools\Http\Controllers\Concerns\ValheimServer;
use ValheimTools\Services\AccessLists;

/**
 * The Access lists page: adminlist.txt, bannedlist.txt and permittedlist.txt.
 */
class ListsController extends Controller
{
    use ValheimServer;

    public function __construct(
        private readonly AccessLists $lists,
        private readonly ReadsServerState $state,
    ) {}

    public function index(Request $request, Server $server): JsonResponse
    {
        $this->authorizeLists($request, $server);

        return new JsonResponse($this->payload($request, $server));
    }

    public function add(Request $request, Server $server): JsonResponse
    {
        $this->authorizeLists($request, $server);
        $this->ensureCan($request, $server, 'file.update');

        $data = $request->validate([
            'list' => ['required', 'string', 'in:admin,banned,permitted'],
            'id' => ['required', 'string', 'max:200'],
            'confirm' => ['sometimes', 'boolean'],
        ]);

        $added = $this->lists->add($server, $data['list'], $data['id'], (bool) ($data['confirm'] ?? false));

        return new JsonResponse(['added' => $added] + $this->payload($request, $server));
    }

    public function remove(Request $request, Server $server): JsonResponse
    {
        $this->authorizeLists($request, $server);
        $this->ensureCan($request, $server, 'file.update');

        $data = $request->validate([
            'list' => ['required', 'string', 'in:admin,banned,permitted'],
            'id' => ['required', 'string', 'max:200'],
        ]);

        $removed = $this->lists->remove($server, $data['list'], $data['id']);

        return new JsonResponse(['removed' => $removed] + $this->payload($request, $server));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request, Server $server): array
    {
        return $this->lists->all($server) + [
            'state' => $this->serverState($this->state, $server),
            'editable' => $request->user()->can('file.update', $server),
        ];
    }

    private function authorizeLists(Request $request, Server $server): void
    {
        $this->ensureValheim($server);
        $this->ensureCan($request, $server, ['ext.valheim-tools.lists', 'file.read', 'file.read-content'], 'You do not have permission to see the access lists.');
    }
}
