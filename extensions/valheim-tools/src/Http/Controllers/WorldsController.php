<?php

namespace ValheimTools\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Pterodactyl\Contracts\Servers\ReadsServerState;
use Pterodactyl\Models\Server;
use Symfony\Component\HttpKernel\Exception\HttpException;
use ValheimTools\Http\Controllers\Concerns\ValheimServer;
use ValheimTools\Services\Worlds;

/**
 * The Worlds page: world files, backups, and switching the world the server loads.
 */
class WorldsController extends Controller
{
    use ValheimServer;

    private const string PERMISSION = 'ext.valheim-tools.worlds';

    public function __construct(
        private readonly Worlds $worlds,
        private readonly ReadsServerState $state,
    ) {}

    public function show(Request $request, Server $server): JsonResponse
    {
        $this->authorizeWorlds($request, $server, []);

        return new JsonResponse($this->payload($server));
    }

    public function switch(Request $request, Server $server): JsonResponse
    {
        $this->authorizeWorlds($request, $server, ['startup.read', 'startup.update']);
        $data = $request->validate(['name' => ['required', 'string', 'max:100'], 'create' => ['sometimes', 'boolean']]);

        $this->worlds->switch($server, trim($data['name']), (bool) ($data['create'] ?? false));

        return new JsonResponse(['result' => ['name' => trim($data['name'])]] + $this->payload($server));
    }

    public function backup(Request $request, Server $server): JsonResponse
    {
        $this->authorizeWorlds($request, $server, ['file.create', 'file.archive', 'file.update', 'file.delete']);
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'running' => ['sometimes', 'boolean']]);

        // Valheim has no save command for a dedicated server, so a copy of a running world may be caught mid-write.
        if ($this->serverState($this->state, $server) !== 'offline' && ! ($data['running'] ?? false)) {
            throw new HttpException(409, 'The server is running, so the world files may change while they are copied. Stop the server for a safe backup, or confirm to back up anyway.');
        }

        $folder = $this->worlds->backup($server, trim($data['name']));

        return new JsonResponse(['result' => ['backup' => $folder]] + $this->payload($server));
    }

    public function restore(Request $request, Server $server): JsonResponse
    {
        $this->authorizeWorlds($request, $server, ['file.create', 'file.archive', 'file.update', 'file.delete']);
        $data = $request->validate(['id' => ['required', 'string', 'max:255'], 'automatic' => ['sometimes', 'boolean']]);
        $this->ensureStopped($this->state, $server, 'Stop the server to restore a backup.');

        $id = trim($data['id']);
        $result = ($data['automatic'] ?? false) ? $this->worlds->restoreAutomatic($server, $id) : $this->worlds->restore($server, $id);

        return new JsonResponse(['result' => $result] + $this->payload($server));
    }

    public function deleteBackup(Request $request, Server $server): JsonResponse
    {
        $this->authorizeWorlds($request, $server, ['file.delete']);
        $data = $request->validate(['id' => ['required', 'string', 'max:255']]);

        $this->worlds->deleteBackup($server, trim($data['id']));

        return new JsonResponse($this->payload($server));
    }

    public function delete(Request $request, Server $server): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'backup' => ['sometimes', 'boolean']]);
        $backup = (bool) ($data['backup'] ?? true);

        $this->authorizeWorlds($request, $server, $backup ? ['file.delete', 'file.create', 'file.archive', 'file.update'] : ['file.delete']);
        $this->ensureStopped($this->state, $server, 'Stop the server to delete a world.');

        $folder = $this->worlds->delete($server, trim($data['name']), $backup);

        return new JsonResponse(['result' => ['backup' => $folder]] + $this->payload($server));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Server $server): array
    {
        return $this->worlds->overview($server) + ['state' => $this->serverState($this->state, $server)];
    }

    /**
     * @param  list<string>  $extra  panel permissions this action also needs
     */
    private function authorizeWorlds(Request $request, Server $server, array $extra): void
    {
        $this->ensureValheim($server);
        $this->ensureCan($request, $server, [self::PERMISSION, 'file.read', ...$extra], 'You do not have permission to manage worlds on this server.');
    }
}
