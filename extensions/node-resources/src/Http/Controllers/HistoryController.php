<?php

namespace NodeResources\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use NodeResources\Services\History;
use NodeResources\Services\Reports;
use NodeResources\Support\Retention;
use Pterodactyl\Models\Server;

/** The History card on a server's console page. */
class HistoryController extends Controller
{
    public function __construct(private readonly History $history, private readonly Reports $reports) {}

    public function show(Request $request, Server $server): JsonResponse
    {
        abort_unless($request->user()->can('ext.node-resources.history', $server), 403, 'You do not have permission to see the history of this server.');
        $data = $request->validate(['range' => ['sometimes', 'string', 'in:'.implode(',', array_keys(History::RANGES))]]);

        $retention = Retention::from($this->reports->settings()['retention']);

        return new JsonResponse([
            ...$this->history->server($server->id, $data['range'] ?? '24h', $retention),
            'limits' => ['memory' => (int) $server->memory * 1_048_576, 'disk' => (int) $server->disk * 1_048_576, 'cpu' => (int) $server->cpu],
            'collector' => ['last_run_at' => $this->reports->collector()['last_run_at']],
        ]);
    }
}
