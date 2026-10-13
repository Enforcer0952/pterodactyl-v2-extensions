<?php

namespace McConsole\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use McConsole\Services\PluginCommands;
use Pterodactyl\Contracts\Files\ReadsFileContents;
use Illuminate\Support\Facades\Cache;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Exceptions\Http\Server\FileSizeTooLargeException;
use Pterodactyl\Models\Server;

class CommandsController extends Controller
{
    /**
     * Plugin commands and known player names for a server's console suggestions.
     * Vanilla commands ship with the frontend.
     */
    public function __invoke(Request $request, Server $server, PluginCommands $plugins, ReadsFileContents $reader): JsonResponse
    {
        abort_unless($request->user()->can('control.console', $server), 403, 'You do not have permission to send console commands.');
        abort_unless($request->user()->can('ext.mc-console.suggestions', $server), 403, 'You do not have permission to see plugin commands and player names.');

        $result = $plugins->forServer($server);

        return new JsonResponse([
            'plugins' => $result['commands'],
            'pending' => $result['pending'],
            'players' => Cache::remember('ext:mc-console:players:'.$server->uuid, 60, fn (): array => $this->players($server, $plugins, $reader)),
        ]);
    }

    /**
     * Names of everyone who has joined, from usercache.json.
     *
     * @return list<string>
     */
    private function players(Server $server, PluginCommands $plugins, ReadsFileContents $reader): array
    {
        // Only read the file when it exists: some Wings versions answer a missing file with a 500.
        if (! $plugins->rootHas($server, 'usercache.json')) {
            return [];
        }

        try {
            $cache = json_decode($reader->read($server, '/usercache.json', 8 * 1024 * 1024), true);
        } catch (DaemonConnectionException|FileSizeTooLargeException) {
            return [];
        }

        $names = [];
        foreach (is_array($cache) ? $cache : [] as $row) {
            $name = is_array($row) ? ($row['name'] ?? null) : null;
            if (is_string($name) && preg_match('/^\.?[A-Za-z0-9_]{1,16}$/', $name) === 1) {
                $names[] = $name;
            }
        }

        return array_slice(array_values(array_unique($names)), 0, 2000);
    }
}
