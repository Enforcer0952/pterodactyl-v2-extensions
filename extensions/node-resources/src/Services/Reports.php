<?php

namespace NodeResources\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use NodeResources\Support\Capacity;
use NodeResources\Support\Forecast;
use NodeResources\Support\Retention;
use NodeResources\Support\Rollup;
use NodeResources\Support\Suggestions;
use NodeResources\Support\Tables;
use NodeResources\Support\Warnings;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Server;

/** Builds what the admin pages show from the stored samples and the panel's own records. */
class Reports
{
    /** Host numbers from the probe are only believed this long. */
    private const int PROBE_MAX_AGE = 600;

    /** The collector is called dead after this many seconds without a run. */
    public const int COLLECTOR_STALE = 300;

    private const int WEEK = 604_800;

    public function __construct(private readonly Probes $probes, private readonly Meta $meta) {}

    /** @return array{disk_percent: int, forecast_days: int, retention: string} */
    public function settings(): array
    {
        $settings = app('ext.node-resources.settings');

        return [
            'disk_percent' => max(1, min(99, (int) $settings->get('disk_warning_percent', Warnings::DISK_PERCENT))),
            'forecast_days' => max(1, min(90, (int) $settings->get('forecast_warning_days', Warnings::FORECAST_DAYS))),
            'retention' => Retention::from($settings->get('retention', Retention::DEFAULT))->key,
        ];
    }

    /** @return array{last_run_at: ?string, age: ?int, stale: bool, nodes_ok: int, nodes_failed: int, servers: int, seconds: int} */
    public function collector(?int $now = null): array
    {
        $now ??= time();
        $meta = $this->meta->get(Collector::META);
        $at = is_array($meta) && isset($meta['at']) ? (int) $meta['at'] : null;

        return [
            'last_run_at' => $at === null ? null : date(DATE_ATOM, $at),
            'age' => $at === null ? null : max(0, $now - $at),
            'stale' => $at === null || $now - $at > self::COLLECTOR_STALE,
            'nodes_ok' => (int) ($meta['nodes_ok'] ?? 0),
            'nodes_failed' => (int) ($meta['nodes_failed'] ?? 0),
            'servers' => (int) ($meta['servers'] ?? 0),
            'seconds' => (int) ($meta['seconds'] ?? 0),
        ];
    }

    /**
     * Everything about one node. Without $detail the server table and suggestions are left out.
     *
     * @return array<string, mixed>
     */
    public function node(Node $node, bool $detail = true, ?int $now = null): array
    {
        $now ??= time();
        $settings = $this->settings();

        $latest = DB::table(Tables::NODE_SAMPLES)->where('node_id', $node->id)->orderByDesc('taken_at')->first();
        $good = $latest !== null && $latest->reachable
            ? $latest
            : DB::table(Tables::NODE_SAMPLES)->where('node_id', $node->id)->where('reachable', true)->orderByDesc('taken_at')->first();
        $probeSample = DB::table(Tables::NODE_SAMPLES)->where('node_id', $node->id)->whereNotNull('mem_used')->whereNotNull('disk_used')->orderByDesc('taken_at')->first();
        if ($probeSample !== null && $now - (int) $probeSample->taken_at > self::PROBE_MAX_AGE) {
            $probeSample = null;
        }

        $host = [
            'mem_total' => self::int($probeSample->mem_total ?? $good->mem_total ?? null),
            'mem_available' => $probeSample === null ? null : max(0, (int) $probeSample->mem_total - (int) $probeSample->mem_used),
            'disk_total' => self::int($probeSample->disk_total ?? null),
            'disk_free' => $probeSample === null ? null : max(0, (int) $probeSample->disk_total - (int) $probeSample->disk_used),
            'cpu_threads' => self::int($good->cpus ?? $probeSample->cpus ?? null),
            'load1' => $probeSample === null || $probeSample->load1 === null ? null : (float) $probeSample->load1,
        ];

        $servers = Server::query()->without('allocation')->where('node_id', $node->id)->orderBy('name')
            ->get(['id', 'uuidShort', 'name', 'status', 'memory', 'disk', 'cpu']);
        $ids = $servers->modelKeys();
        $current = $good === null ? collect() : DB::table(Tables::SERVER_SAMPLES)->where('node_id', $node->id)->where('taken_at', $good->taken_at)->get()->keyBy('server_id');
        $peaks = $this->peaks($ids, $now);
        $probe = $this->probes->status($node, $detail, $now);
        $probeServerId = $probe['server']['id'] ?? null;

        $inputs = [];
        $rows = [];
        foreach ($servers as $server) {
            $sample = $current->get($server->id);
            $peak = $peaks[$server->id] ?? null;
            $row = [
                'memory' => (int) $server->memory, 'disk' => (int) $server->disk, 'cpu' => (int) $server->cpu,
                'mem_now' => $sample === null ? null : (int) $sample->mem,
                'disk_now' => $sample === null ? null : (int) $sample->disk,
                'cpu_now' => $sample === null ? null : (float) $sample->cpu,
                'mem_peak' => $peak['mem'] ?? null, 'disk_peak' => $peak['disk'] ?? null, 'cpu_peak' => $peak['cpu'] ?? null,
            ];
            $inputs[] = $row;

            if ($detail) {
                $isProbe = $probeServerId !== null && $server->uuidShort === $probeServerId;
                $rows[] = $this->serverRow($server, $row, $sample?->state, $isProbe, $peak['first'] ?? null, $now);
            }
        }

        $since = DB::table(Tables::NODE_ROLLUPS)->where('node_id', $node->id)->where('resolution', Rollup::FIVE_MINUTES)->min('bucket');

        $capacity = Capacity::summarize([
            'memory' => (int) $node->memory, 'memory_overallocate' => (int) $node->memory_overallocate,
            'disk' => (int) $node->disk, 'disk_overallocate' => (int) $node->disk_overallocate,
        ], $inputs, $host);

        $forecast = $host['disk_total'] === null ? null : $this->forecast($node->id, $host['disk_total'], $now);

        return [
            'node' => [
                'id' => $node->id, 'name' => $node->name, 'fqdn' => $node->fqdn,
                'memory' => (int) $node->memory, 'memory_overallocate' => (int) $node->memory_overallocate,
                'disk' => (int) $node->disk, 'disk_overallocate' => (int) $node->disk_overallocate,
                'maintenance' => (bool) $node->maintenance_mode,
            ],
            'collected_at' => $good === null ? null : date(DATE_ATOM, (int) $good->taken_at),
            'reachable' => $latest === null ? null : (bool) $latest->reachable,
            'probe' => $probe,
            'ram' => $capacity['ram'],
            'disk' => $capacity['disk'],
            'cpu' => $capacity['cpu'],
            'forecast' => $forecast,
            'warnings' => Warnings::forNode($capacity, $forecast, $settings['disk_percent'], $settings['forecast_days']),
            'data_since' => $since === null ? null : date(DATE_ATOM, (int) $since),
            ...($detail ? ['servers' => $rows] : []),
        ];
    }

    /**
     * What to suggest for one server, computed the same way as in the node table. Only
     * servers with an unlimited memory or disk get one, and only after a day of data.
     *
     * @return array{memory: ?int, disk: ?int}
     */
    public function suggestionsFor(Server $server, ?int $now = null): array
    {
        $now ??= time();
        $peak = $this->peaks([$server->id], $now)[$server->id] ?? null;
        $sample = DB::table(Tables::SERVER_SAMPLES)->where('server_id', $server->id)->orderByDesc('taken_at')->first();
        $probe = DB::table(Tables::PROBES)->where('server_id', $server->id)->exists();

        return $this->suggestions($server, $sample === null ? null : (int) $sample->mem, $sample === null ? null : (int) $sample->disk, $peak, $probe, $now);
    }

    /** @return array<string, mixed> */
    public function overview(?int $now = null): array
    {
        $now ??= time();
        $nodes = [];
        foreach (Node::query()->orderBy('name')->get() as $node) {
            $nodes[] = $this->node($node, false, $now);
        }

        return ['collector' => $this->collector($now), 'settings' => $this->settings(), 'nodes' => $nodes];
    }

    /**
     * Warnings for the banner, cached for half a minute.
     *
     * @return array{collector: array<string, mixed>, warnings: list<array<string, mixed>>}
     */
    public function alerts(): array
    {
        return Cache::remember('node-resources:alerts', 30, function (): array {
            $overview = $this->overview();
            $warnings = [];
            foreach ($overview['nodes'] as $report) {
                foreach ($report['warnings'] as $warning) {
                    $warnings[] = [...$warning, 'node' => ['id' => $report['node']['id'], 'name' => $report['node']['name']]];
                }
            }
            usort($warnings, fn (array $a, array $b): int => ($a['level'] === 'danger' ? 0 : 1) <=> ($b['level'] === 'danger' ? 0 : 1));

            return ['collector' => $overview['collector'], 'warnings' => $warnings];
        });
    }

    /**
     * @param  array{mem: ?int, disk: ?int, cpu: ?float, first: ?int}|null  $peak
     * @return array{memory: ?int, disk: ?int}
     */
    private function suggestions(Server $server, ?int $memNow, ?int $diskNow, ?array $peak, bool $isProbe, int $now): array
    {
        $none = ['memory' => null, 'disk' => null];
        if ($isProbe || $server->status !== null || ! Suggestions::hasHistory($peak['first'] ?? null, $now)) {
            return $none;
        }

        return [
            'memory' => (int) $server->memory === 0 ? Suggestions::memory($peak['mem'] ?? null, $memNow) : null,
            'disk' => (int) $server->disk === 0 ? Suggestions::disk($diskNow) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $row  limits and use, as Capacity reads them
     * @param  array{mem: ?int, disk: ?int, cpu: ?float, first: ?int}|null  $peak
     * @return array<string, mixed>
     */
    private function serverRow(Server $server, array $row, ?string $state, bool $isProbe, ?int $firstSeen, int $now): array
    {
        $peak = ['mem' => $row['mem_peak'], 'disk' => $row['disk_peak'], 'cpu' => $row['cpu_peak'], 'first' => $firstSeen];
        $suggestion = $this->suggestions($server, $row['mem_now'], $row['disk_now'], $peak, $isProbe, $now);

        return [
            'id' => $server->uuidShort,
            'admin_id' => $server->id,
            'name' => $server->name,
            'state' => $state,
            'status' => $server->status,
            'is_probe' => $isProbe,
            'memory' => ['limit' => $row['memory'] * 1_048_576, 'now' => $row['mem_now'], 'peak' => $row['mem_peak']],
            'disk' => ['limit' => $row['disk'] * 1_048_576, 'now' => $row['disk_now'], 'peak' => $row['disk_peak']],
            'cpu' => ['limit' => $row['cpu'], 'now' => $row['cpu_now'], 'peak' => $row['cpu_peak']],
            'suggestion' => $suggestion['memory'] === null && $suggestion['disk'] === null ? null : $suggestion,
        ];
    }

    /**
     * Seven-day peaks and the first time each server was seen.
     *
     * @param  list<int>  $ids
     * @return array<int, array{mem: ?int, disk: ?int, cpu: ?float, first: ?int}>
     */
    private function peaks(array $ids, int $now): array
    {
        if ($ids === []) {
            return [];
        }

        $out = [];
        $rows = DB::table(Tables::SERVER_ROLLUPS)
            ->where('resolution', Rollup::FIVE_MINUTES)
            ->where('bucket', '>=', $now - self::WEEK)
            ->whereIn('server_id', $ids)
            ->groupBy('server_id')
            ->selectRaw('server_id, MAX(mem_max) as mem_peak, MAX(disk_max) as disk_peak, MAX(cpu_max) as cpu_peak, MIN(bucket) as first_seen')
            ->get();
        foreach ($rows as $row) {
            $out[(int) $row->server_id] = [
                'mem' => self::int($row->mem_peak), 'disk' => self::int($row->disk_peak),
                'cpu' => $row->cpu_peak === null ? null : (float) $row->cpu_peak, 'first' => self::int($row->first_seen),
            ];
        }

        return $out;
    }

    /** @return array{days: ?float, growth_per_day: float}|null */
    private function forecast(int $nodeId, int $diskTotal, int $now): ?array
    {
        return Cache::remember('node-resources:forecast:'.$nodeId.':'.intdiv($now, 300), 300, function () use ($nodeId, $diskTotal, $now): ?array {
            $rows = DB::table(Tables::NODE_ROLLUPS)
                ->where('node_id', $nodeId)->where('resolution', Rollup::FIVE_MINUTES)->where('bucket', '>=', $now - self::WEEK)
                ->whereNotNull('disk_used_avg')->orderBy('bucket')
                ->get(['bucket', 'samples', 'disk_used_avg', 'disk_used_max']);

            // Hourly points are plenty for a line through a week.
            $hours = Rollup::regroup(array_map(fn (object $row): array => (array) $row, $rows->all()), Rollup::HOUR, ['disk_used']);
            $points = [];
            foreach ($hours as $hour) {
                $points[] = [(int) $hour['bucket'], (float) $hour['disk_used_avg']];
            }

            return Forecast::daysUntilFull($points, (float) $diskTotal, $now);
        });
    }

    private static function int(mixed $value): ?int
    {
        return $value === null || ! is_numeric($value) ? null : (int) $value;
    }
}
