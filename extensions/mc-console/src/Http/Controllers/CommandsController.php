<?php

namespace McConsole\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use McConsole\Services\PluginCommands;
use Pterodactyl\Contracts\Files\ReadsFileContents;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
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

        $result = $plugins->forServer($server);

        return new JsonResponse([
            'plugins' => $result['commands'],
            'pending' => $result['pending'],
            'players' => $this->players($server, $reader),
        ]);
    }

    /**
     * Names of everyone who has joined, from usercache.json.
     *
     * @return list<string>
     */
    private function players(Server $server, ReadsFileContents $reader): array
    {
        try {
            $cache = json_decode($reader->read($server, '/usercache.json', 1024 * 1024), true);
        } catch (DaemonConnectionException) {
            return [];
        }

        $names = [];
        foreach (is_array($cache) ? $cache : [] as $row) {
            $name = is_array($row) ? ($row['name'] ?? null) : null;
            if (is_string($name) && preg_match('/^\.?[A-Za-z0-9_]{1,16}$/', $name) === 1) {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }
}
