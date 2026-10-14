<?php

namespace NodeResources\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use NodeResources\Services\ApplyLimits;
use NodeResources\Services\Collector;
use NodeResources\Services\History;
use NodeResources\Services\Probes;
use NodeResources\Services\Reports;
use NodeResources\Support\Retention;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Server;

/** Admin API: root administrators only (the panel's admin middleware). */
class AdminController extends Controller
{
    public function __construct(
        private readonly Reports $reports,
        private readonly History $history,
        private readonly Probes $probes,
        private readonly Collector $collector,
        private readonly ApplyLimits $limits,
    ) {}

    public function overview(): JsonResponse
    {
        return new JsonResponse($this->reports->overview());
    }

    /** The banner: only the warnings and how the collector is doing. */
    public function alerts(): JsonResponse
    {
        return new JsonResponse($this->reports->alerts());
    }

    /** Nodes to pick from in the server forms. */
    public function nodes(): JsonResponse
    {
        return new JsonResponse(['data' => Node::query()->orderBy('name')->get(['id', 'name'])->map(fn (Node $node): array => ['id' => $node->id, 'name' => $node->name])->all()]);
    }

    public function node(int $node): JsonResponse
    {
        $model = Node::query()->findOrFail($node);

        return new JsonResponse([...$this->reports->node($model), 'collector' => $this->reports->collector(), 'settings' => $this->reports->settings()]);
    }

    /** The numbers for the new-server and build forms: a node's room, without the server table. */
    public function capacity(int $node): JsonResponse
    {
        return new JsonResponse($this->reports->node(Node::query()->findOrFail($node), false));
    }

    public function serverCapacity(int $server): JsonResponse
    {
        $model = Server::query()->without('allocation')->findOrFail($server);

        return new JsonResponse([
            'server' => [
                'id' => $model->id, 'name' => $model->name,
                'memory' => (int) $model->memory * 1_048_576, 'disk' => (int) $model->disk * 1_048_576, 'cpu' => (int) $model->cpu,
            ],
            'report' => $this->reports->node(Node::query()->findOrFail($model->node_id), false),
        ]);
    }

    public function history(Request $request, int $node): JsonResponse
    {
        $data = $request->validate(['range' => ['sometimes', 'string', 'in:'.implode(',', array_keys(History::RANGES))]]);
        Node::query()->findOrFail($node);

        return new JsonResponse($this->history->node($node, $data['range'] ?? '24h', Retention::from($this->reports->settings()['retention'])));
    }

    public function setupProbe(Request $request, int $node): JsonResponse
    {
        $model = Node::query()->findOrFail($node);
        $this->probes->setup($model, $request->user());
        Cache::forget('node-resources:alerts');

        return new JsonResponse([...$this->reports->node($model), 'collector' => $this->reports->collector(), 'settings' => $this->reports->settings()]);
    }

    public function removeProbe(int $node): JsonResponse
    {
        $model = Node::query()->findOrFail($node);
        $this->probes->remove($model);
        Cache::forget('node-resources:alerts');

        return new JsonResponse([...$this->reports->node($model), 'collector' => $this->reports->collector(), 'settings' => $this->reports->settings()]);
    }

    /** Collect this node now instead of waiting for the next minute. */
    public function refresh(int $node): JsonResponse
    {
        $model = Node::query()->findOrFail($node);
        $result = $this->collector->tick($model->id);
        abort_if($result['skipped'], 409, 'A collection is already running. Try again in a few seconds.');
        Cache::forget('node-resources:alerts');

        return new JsonResponse([...$this->reports->node($model), 'collector' => $this->reports->collector(), 'settings' => $this->reports->settings()]);
    }

    public function applyLimits(Request $request, int $server): JsonResponse
    {
        $data = $request->validate([
            'fields' => ['required', 'array', 'min:1', 'max:2'],
            'fields.*' => ['string', 'in:memory,disk'],
        ]);

        $applied = $this->limits->apply(Server::query()->findOrFail($server), array_values($data['fields']));

        return new JsonResponse(['applied' => $applied]);
    }
}
