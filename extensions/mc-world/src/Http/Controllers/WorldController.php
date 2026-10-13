<?php

namespace McWorld\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use McWorld\Http\Controllers\Concerns\McWorldServer;
use McWorld\Services\Worlds;
use Pterodactyl\Models\Server;

/**
 * The Worlds card: list worlds and backups, back up, restore, reset, switch and delete.
 * Each action checks the same panel permissions the file manager would ask for.
 */
class WorldController extends Controller
{
    use McWorldServer;

    public function __construct(private readonly Worlds $worlds) {}

    public function show(Request $request, Server $server): JsonResponse
    {
        $this->ensureJava($server);
        $this->ensureCan($request, $server, ['file.read'], 'You do not have permission to see this server\'s files.');

        return new JsonResponse($this->guard(fn (): array => $this->worlds->overview($server)));
    }

    public function backup(Request $request, Server $server): JsonResponse
    {
        $this->ensureJava($server);
        $this->ensureCan($request, $server, ['file.read', 'file.archive', 'file.create', 'file.update'], 'You do not have permission to make backups.');
        $data = $request->validate(['world' => ['required', 'string', 'max:120']]);
        set_time_limit(600);

        $file = $this->guard(fn (): string => $this->worlds->backup($server, $data['world']));

        return new JsonResponse(['result' => ['backup' => $file]] + $this->worlds->overview($server));
    }

    public function restore(Request $request, Server $server): JsonResponse
    {
        $this->ensureJava($server);
        $this->ensureCan($request, $server, ['file.read', 'file.archive', 'file.update'], 'You do not have permission to restore backups.');
        $data = $request->validate(['backup' => ['required', 'string', 'max:200']]);
        set_time_limit(600);

        $result = $this->guard(fn (): array => $this->worlds->restore($server, $data['backup']));

        return new JsonResponse(['result' => $result] + $this->worlds->overview($server));
    }

    public function deleteBackup(Request $request, Server $server, string $backup): JsonResponse
    {
        $this->ensureJava($server);
        $this->ensureCan($request, $server, ['file.read', 'file.delete'], 'You do not have permission to delete files.');

        $this->guard(fn () => $this->worlds->deleteBackup($server, $backup));

        return new JsonResponse($this->worlds->overview($server));
    }

    public function reset(Request $request, Server $server): JsonResponse
    {
        $this->ensureJava($server);
        $data = $request->validate([
            'world' => ['required', 'string', 'max:120'],
            // The panel turns an empty string into null, which also means "random".
            'seed' => ['nullable', 'string', 'max:200'],
            'backup' => ['required', 'boolean'],
            'start' => ['required', 'boolean'],
        ]);

        $needs = ['file.read', 'file.delete', 'file.update'];
        if ($data['backup']) {
            array_push($needs, 'file.archive', 'file.create');
        }
        if ($data['start']) {
            $needs[] = 'control.start';
        }
        $this->ensureCan($request, $server, $needs, 'You do not have permission to reset the world.');
        set_time_limit(600);

        $deleted = $this->guard(fn (): array => $this->worlds->reset($server, $data['world'], $data['seed'] ?? null, (bool) $data['backup'], (bool) $data['start']));

        return new JsonResponse(['result' => ['deleted' => $deleted]] + $this->worlds->overview($server));
    }

    public function switch(Request $request, Server $server): JsonResponse
    {
        $this->ensureJava($server);
        $this->ensureCan($request, $server, ['file.read', 'file.update'], 'You do not have permission to change server.properties.');
        $data = $request->validate(['world' => ['required', 'string', 'max:120']]);

        $this->guard(fn () => $this->worlds->switch($server, $data['world']));

        return new JsonResponse($this->worlds->overview($server));
    }

    public function destroy(Request $request, Server $server, string $world): JsonResponse
    {
        $this->ensureJava($server);
        $this->ensureCan($request, $server, ['file.read', 'file.delete'], 'You do not have permission to delete files.');

        $deleted = $this->guard(fn (): array => $this->worlds->deleteWorld($server, $world));

        return new JsonResponse(['result' => ['deleted' => $deleted]] + $this->worlds->overview($server));
    }
}
