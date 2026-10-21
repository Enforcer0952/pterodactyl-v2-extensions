<?php

namespace NodeResources\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use NodeResources\Support\Failure;
use NodeResources\Support\Retention;
use NodeResources\Support\SystemInfo;
use NodeResources\Support\Tables;
use Pterodactyl\Contracts\Servers\ReadsServerState;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Facades\Daemon;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Server;
use Throwable;

/**
 * Once a minute: ask every node what it has and every server what it uses, and store it.
 *
 * A node that does not answer (connection refused, timed out) gets a row saying so and is
 * skipped. A node that answers with an error is still a node that is up: the server that
 * failed is left out and the minute is marked partial. Nodes are visited oldest sample first
 * and each gets a short time slice, so a dead or slow node cannot starve the others.
 */
class Collector
{
    public const string LOCK = 'node-resources:collect';

    public const string META = 'collector';

    /** Seconds after which a run stops starting new calls. */
    public const int BUDGET = 50;

    /** Seconds one node may take out of that. */
    public const int NODE_BUDGET = 20;

    /** The refresh button gets a shorter deadline: someone is waiting. */
    public const int REFRESH_BUDGET = 15;

    /** Wings calls give up after this many seconds (connect) and (whole call) while collecting. */
    private const int CONNECT_TIMEOUT = 3;

    private const int CALL_TIMEOUT = 8;

    /** Servers whose node answers badly: give up on the node after this many in a row. */
    private const int MAX_FAILURES = 3;

    private const int CHUNK = 250;

    public function __construct(
        private readonly ReadsServerState $state,
        private readonly Probes $probes,
        private readonly Rollups $rollups,
        private readonly Meta $meta,
    ) {}

    /**
     * @param  int|null  $onlyNode  collect just this node (the Refresh button)
     * @return array{skipped: bool, nodes_ok: int, nodes_failed: int, servers: int, probes: int}
     */
    public function tick(?int $onlyNode = null): array
    {
        $lock = Cache::lock(self::LOCK, 120);
        if (! $lock->get()) {
            return ['skipped' => true, 'nodes_ok' => 0, 'nodes_failed' => 0, 'servers' => 0, 'probes' => 0];
        }

        try {
            return $this->run($onlyNode);
        } finally {
            $lock->release();
        }
    }

    /** @return array{skipped: bool, nodes_ok: int, nodes_failed: int, servers: int, probes: int} */
    private function run(?int $onlyNode): array
    {
        $started = time();
        $deadline = $started + ($onlyNode === null ? self::BUDGET : self::REFRESH_BUDGET);
        $result = ['skipped' => false, 'nodes_ok' => 0, 'nodes_failed' => 0, 'servers' => 0, 'probes' => 0];

        $connect = config('pterodactyl.guzzle.connect_timeout');
        $call = config('pterodactyl.guzzle.timeout');
        config([
            'pterodactyl.guzzle.connect_timeout' => min((int) ($connect ?: self::CONNECT_TIMEOUT), self::CONNECT_TIMEOUT),
            'pterodactyl.guzzle.timeout' => min((int) ($call ?: self::CALL_TIMEOUT), self::CALL_TIMEOUT),
        ]);

        try {
            foreach ($this->nodes($onlyNode) as $node) {
                if (time() >= $deadline) {
                    break;
                }

                // A refresh does not add a second sample to a minute that already has one.
                if ($onlyNode !== null && $this->hasSampleThisMinute($node->id, $started)) {
                    $result['nodes_ok']++;

                    continue;
                }

                try {
                    $summary = $this->collectNode($node, $started, min($deadline, time() + self::NODE_BUDGET));
                } catch (Throwable $exception) {
                    // One node must not stop the rest.
                    report($exception);
                    $summary = null;
                }

                if ($summary === null) {
                    $result['nodes_failed']++;

                    continue;
                }
                $result['nodes_ok']++;
                $result['servers'] += $summary['servers'];
                $result['probes'] += $summary['probe'] ? 1 : 0;
            }
        } finally {
            config(['pterodactyl.guzzle.connect_timeout' => $connect, 'pterodactyl.guzzle.timeout' => $call]);
        }

        if ($onlyNode === null) {
            try {
                $this->rollups->refresh($started, $this->retention());
            } catch (Throwable $exception) {
                report($exception);
            }
            $this->meta->put(self::META, [...$result, 'at' => time(), 'seconds' => time() - $started]);
        }

        return $result;
    }

    /**
     * The nodes to visit, the one that was sampled longest ago first.
     *
     * @return list<Node>
     */
    private function nodes(?int $onlyNode): array
    {
        $nodes = Node::query()->when($onlyNode !== null, fn ($query) => $query->whereKey($onlyNode))->orderBy('id')->get();
        if ($onlyNode !== null) {
            return $nodes->all();
        }

        $last = DB::table(Tables::NODE_SAMPLES)->groupBy('node_id')->selectRaw('node_id, MAX(taken_at) AS at')->pluck('at', 'node_id');

        return $nodes->sortBy(fn (Node $node): int => (int) ($last[$node->id] ?? 0))->values()->all();
    }

    private function hasSampleThisMinute(int $nodeId, int $now): bool
    {
        return DB::table(Tables::NODE_SAMPLES)->where('node_id', $nodeId)->where('taken_at', '>=', intdiv($now, 60) * 60)->exists();
    }

    /** @return array{servers: int, probe: bool}|null null when the node did not answer */
    private function collectNode(Node $node, int $takenAt, int $deadline): ?array
    {
        $sample = [
            'node_id' => $node->id, 'taken_at' => $takenAt, 'reachable' => true, 'partial' => false,
            'mem_total' => null, 'mem_used' => null, 'disk_total' => null, 'disk_used' => null,
            'load1' => null, 'cpus' => null, 'sv_mem' => null, 'sv_disk' => null, 'sv_cpu' => null, 'sv_count' => 0, 'sv_expected' => null,
        ];

        try {
            $info = SystemInfo::fromWings(Daemon::node($node)->systemInformation(2));
            $sample['mem_total'] = $info['memory_total'];
            $sample['cpus'] = $info['cpu_threads'];
        } catch (DaemonConnectionException $exception) {
            if (Failure::isConnection($exception)) {
                $sample['reachable'] = false;
                DB::table(Tables::NODE_SAMPLES)->insert($sample);

                return null;
            }
            // The node answered, with an error: it is up, only its totals are unknown this minute.
        }

        $reading = $this->probes->read($node, $takenAt);
        if ($reading !== null) {
            $sample['mem_total'] = $reading['mem_total'];
            $sample['mem_used'] = $reading['mem_total'] - $reading['mem_available'];
            $sample['disk_total'] = $reading['disk_total'];
            $sample['disk_used'] = $reading['disk_total'] - $reading['disk_free'];
            $sample['load1'] = $reading['load1'];
            // Wings counts threads the way the panel's CPU limits do.
            $sample['cpus'] ??= $reading['cpus'] > 0 ? $reading['cpus'] : null;
        }

        $rows = [];
        $mem = $disk = 0;
        $cpu = 0.0;
        $failures = 0;

        $servers = Server::query()->without('allocation')
            ->where('node_id', $node->id)
            ->where(fn ($query) => $query->whereNull('status')->orWhereNotIn('status', [Server::STATUS_INSTALLING, Server::STATUS_INSTALL_FAILED, Server::STATUS_REINSTALL_FAILED]))
            ->orderBy('id')
            ->get(['id', 'uuid', 'node_id', 'status']);

        foreach ($servers as $server) {
            if (time() >= $deadline) {
                break;
            }
            $server->setRelation('node', $node);

            try {
                $state = $this->state->read($server);
                $failures = 0;
            } catch (DaemonConnectionException $exception) {
                // Only a missing answer says anything about the node. An error for one server
                // (it is installing, its container is gone) just leaves that server out.
                if (Failure::isConnection($exception) && ++$failures >= self::MAX_FAILURES) {
                    break;
                }

                continue;
            }

            $rows[] = [
                'server_id' => $server->id, 'node_id' => $node->id, 'taken_at' => $takenAt,
                'state' => mb_substr($state->state, 0, 16),
                'mem' => max(0, $state->memoryBytes), 'cpu' => round(max(0, (float) $state->cpuAbsolute), 2), 'disk' => max(0, $state->diskBytes),
                'net_rx' => max(0, $state->networkRxBytes), 'net_tx' => max(0, $state->networkTxBytes),
            ];
            $mem += max(0, $state->memoryBytes);
            $disk += max(0, $state->diskBytes);
            $cpu += max(0, (float) $state->cpuAbsolute);
        }

        $sample['sv_mem'] = $mem;
        $sample['sv_disk'] = $disk;
        $sample['sv_cpu'] = round($cpu, 2);
        $sample['sv_count'] = count($rows);
        $sample['sv_expected'] = $servers->count();
        // Fewer servers read than there are: the sums above are too low for this minute.
        $sample['partial'] = count($rows) < $servers->count();

        DB::transaction(function () use ($sample, $rows): void {
            DB::table(Tables::NODE_SAMPLES)->insert($sample);
            foreach (array_chunk($rows, self::CHUNK) as $chunk) {
                DB::table(Tables::SERVER_SAMPLES)->insert($chunk);
            }
        });

        return ['servers' => count($rows), 'probe' => $reading !== null];
    }

    private function retention(): Retention
    {
        return Retention::from(app('ext.node-resources.settings')->get('retention', Retention::DEFAULT));
    }
}
