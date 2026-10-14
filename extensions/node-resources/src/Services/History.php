<?php

namespace NodeResources\Services;

use Illuminate\Support\Facades\DB;
use NodeResources\Support\Retention;
use NodeResources\Support\Rollup;
use NodeResources\Support\Tables;

/** The points for the graphs. */
class History
{
    /** Range name => seconds. */
    public const array RANGES = ['24h' => 86_400, '7d' => 604_800, '30d' => 2_592_000, '90d' => 7_776_000, '1y' => 31_536_000];

    /** About this many points per graph, whatever the range. */
    private const int POINTS = 300;

    /** @return list<string> the ranges the retention setting can fill */
    public function ranges(Retention $retention): array
    {
        return array_values(array_filter(
            array_keys(self::RANGES),
            fn (string $range): bool => $range === '24h' || self::RANGES[$range] <= $retention->maxRange()
        ));
    }

    /**
     * @return array{range: string, step: int, ranges: list<string>, points: list<array<string, int|float|null>>, mem_total: ?int, disk_total: ?int, cpus: ?int}
     */
    public function node(int $nodeId, string $range, Retention $retention, ?int $now = null): array
    {
        [$range, $seconds, $resolution] = $this->plan($range, $retention);
        $now ??= time();

        $rows = $this->rows(Tables::NODE_ROLLUPS, 'node_id', $nodeId, $resolution, $now - $seconds);
        $width = Rollup::width($seconds, self::POINTS, $resolution);
        $merged = Rollup::regroup($rows, $width, Rollup::NODE_METRICS, Rollup::NODE_LAST);

        $points = [];
        foreach ($merged as $row) {
            $points[] = [
                't' => $row['bucket'],
                'mem' => self::round($row['mem_used_avg']),
                'disk' => self::round($row['disk_used_avg']),
                'load' => self::round($row['load1_avg'], 2),
                'sv_mem' => self::round($row['sv_mem_avg']),
                'sv_disk' => self::round($row['sv_disk_avg']),
                'sv_cpu' => self::round($row['sv_cpu_avg'], 1),
            ];
        }

        $last = $merged === [] ? [] : $merged[count($merged) - 1];
        // The newest bucket may be missing a total (probe off for that minute): look back.
        $total = fn (string $key): ?int => ($value = self::latest($merged, $key)) === null ? null : (int) $value;

        return [
            'range' => $range,
            'step' => $width,
            'ranges' => $this->ranges($retention),
            'points' => $points,
            'mem_total' => $total('mem_total'),
            'disk_total' => $total('disk_total'),
            'cpus' => isset($last['cpus']) ? (int) $last['cpus'] : $total('cpus'),
        ];
    }

    /**
     * @return array{range: string, step: int, ranges: list<string>, points: list<array<string, int|float|null>>, peaks: array{cpu: ?float, mem: ?int, disk: ?int}}
     */
    public function server(int $serverId, string $range, Retention $retention, ?int $now = null): array
    {
        [$range, $seconds, $resolution] = $this->plan($range, $retention);
        $now ??= time();

        $rows = $this->rows(Tables::SERVER_ROLLUPS, 'server_id', $serverId, $resolution, $now - $seconds);
        $width = Rollup::width($seconds, self::POINTS, $resolution);
        $merged = Rollup::regroup($rows, $width, Rollup::SERVER_METRICS, Rollup::SERVER_LAST);

        $points = [];
        $peaks = ['cpu' => null, 'mem' => null, 'disk' => null];
        foreach ($merged as $row) {
            $points[] = [
                't' => $row['bucket'],
                'cpu' => self::round($row['cpu_avg'], 1),
                'mem' => self::round($row['mem_avg']),
                'disk' => self::round($row['disk_avg']),
            ];
            foreach (['cpu', 'mem', 'disk'] as $metric) {
                $peak = $row[$metric.'_max'];
                if ($peak !== null && ($peaks[$metric] === null || $peak > $peaks[$metric])) {
                    $peaks[$metric] = $metric === 'cpu' ? round((float) $peak, 1) : (int) $peak;
                }
            }
        }

        return ['range' => $range, 'step' => $width, 'ranges' => $this->ranges($retention), 'points' => $points, 'peaks' => $peaks];
    }

    /**
     * The range to read and which rows to read it from: 5-minute rows, or hourly rows for
     * ranges the 5-minute rows no longer cover.
     *
     * @return array{0: string, 1: int, 2: int} range, seconds, resolution
     */
    private function plan(string $range, Retention $retention): array
    {
        if (! array_key_exists($range, self::RANGES) || ($range !== '24h' && self::RANGES[$range] > $retention->maxRange())) {
            $range = '24h';
        }
        $seconds = self::RANGES[$range];
        $resolution = $seconds > $retention->fiveMinuteDays * 86400 && $retention->keepsHourly() ? Rollup::HOUR : Rollup::FIVE_MINUTES;

        return [$range, $seconds, $resolution];
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $table, string $key, int $id, int $resolution, int $since): array
    {
        return DB::table($table)
            ->where($key, $id)->where('resolution', $resolution)->where('bucket', '>=', $since)
            ->orderBy('bucket')
            ->get()
            ->map(fn (object $row): array => (array) $row)
            ->all();
    }

    private static function round(mixed $value, int $digits = 0): int|float|null
    {
        if ($value === null) {
            return null;
        }

        return $digits === 0 ? (int) round((float) $value) : round((float) $value, $digits);
    }

    /** @param list<array<string, mixed>> $rows */
    private static function latest(array $rows, string $key): mixed
    {
        foreach (array_reverse($rows) as $row) {
            if (($row[$key] ?? null) !== null) {
                return $row[$key];
            }
        }

        return null;
    }
}
