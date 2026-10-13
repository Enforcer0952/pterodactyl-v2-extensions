<?php

namespace TerrariaTools\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Pterodactyl\Contracts\Servers\ReadsServerState;
use Pterodactyl\Models\Server;
use Symfony\Component\HttpKernel\Exception\HttpException;
use TerrariaTools\Http\Controllers\Concerns\TerrariaServer;
use TerrariaTools\Services\Worlds;
use TerrariaTools\Support\Terraria;

/**
 * The Worlds page: world files, backups, switching and creating worlds.
 */
class WorldsController extends Controller
{
    use TerrariaServer;

    private const string PERMISSION = 'ext.terraria-tools.worlds';

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
        $this->authorizeWorlds($request, $server, ['file.update']);
        $name = $this->name($request);
        $this->ensureStopped($this->state, $server, 'Stop the server to switch worlds.');

        $variables = $this->worlds->switch($server, $name, $request->user()->can('startup.update', $server));

        return new JsonResponse(['result' => ['name' => $name, 'variables' => $variables]] + $this->payload($server));
    }

    public function backup(Request $request, Server $server): JsonResponse
    {
        $this->authorizeWorlds($request, $server, ['file.create', 'file.update']);
        $name = $this->name($request);

        $running = $this->serverState($this->state, $server) === 'running';
        if ($running) {
            // A running server is asked to save first, which is a console command.
            $this->ensureCan($request, $server, 'control.console', 'Stop the server, or ask for the console permission, to back up a running world.');
        }

        $file = $this->worlds->backup($server, $name, $running);

        return new JsonResponse(['result' => ['backup' => $file]] + $this->payload($server));
    }

    public function restore(Request $request, Server $server): JsonResponse
    {
        $this->authorizeWorlds($request, $server, ['file.create', 'file.update', 'file.delete']);
        $file = $this->file($request);
        $this->ensureStopped($this->state, $server, 'Stop the server to restore a backup.');

        $result = $this->worlds->restore($server, $file);

        return new JsonResponse(['result' => $result] + $this->payload($server));
    }

    public function deleteBackup(Request $request, Server $server): JsonResponse
    {
        $this->authorizeWorlds($request, $server, ['file.delete']);
        $this->worlds->deleteBackup($server, $this->file($request));

        return new JsonResponse($this->payload($server));
    }

    public function delete(Request $request, Server $server): JsonResponse
    {
        $data = $request->validate(['backup' => ['sometimes', 'boolean']]);
        $backup = (bool) ($data['backup'] ?? true);

        $this->authorizeWorlds($request, $server, $backup ? ['file.delete', 'file.create', 'file.update'] : ['file.delete']);
        $name = $this->name($request);
        $this->ensureStopped($this->state, $server, 'Stop the server to delete a world.');

        $file = $this->worlds->delete($server, $name, $backup);

        return new JsonResponse(['result' => ['backup' => $file]] + $this->payload($server));
    }

    public function create(Request $request, Server $server): JsonResponse
    {
        $this->authorizeWorlds($request, $server, ['file.update']);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'size' => ['required', 'integer', 'between:1,3'],
            'difficulty' => ['required', 'integer', 'between:0,3'],
            'seed' => ['nullable', 'string', 'max:200'],
        ]);

        $name = trim($data['name']);
        if (! Terraria::validNewWorld($name)) {
            throw new HttpException(422, 'Use letters, numbers, spaces, dashes and underscores in a world name (up to 40 characters).');
        }
        $seed = Terraria::text($data['seed'] ?? null, 64);

        $this->ensureStopped($this->state, $server, 'Stop the server to set up a new world.');

        $variables = $this->worlds->create($server, [
            'name' => $name,
            'size' => (int) $data['size'],
            'difficulty' => (int) $data['difficulty'],
            'seed' => $seed,
        ], $request->user()->can('startup.update', $server));

        return new JsonResponse(['result' => ['name' => $name, 'variables' => $variables]] + $this->payload($server));
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
        $this->ensureTerraria($server);
        $this->ensureCan($request, $server, [self::PERMISSION, 'file.read', 'file.read-content', ...$extra], 'You do not have permission to manage worlds on this server.');
    }

    private function name(Request $request): string
    {
        return trim((string) $request->validate(['name' => ['required', 'string', 'max:255']])['name']);
    }

    private function file(Request $request): string
    {
        return trim((string) $request->validate(['file' => ['required', 'string', 'max:255']])['file']);
    }
}
