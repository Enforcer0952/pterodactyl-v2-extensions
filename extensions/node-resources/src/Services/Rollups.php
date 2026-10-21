<?php

namespace NodeResources\Services;

use Illuminate\Support\Facades\DB;
use NodeResources\Support\Retention;
use NodeResources\Support\Rollup;
use NodeResources\Support\Sql;
use NodeResources\Support\Tables;

/**
 * Keeps the 5-minute (and, when asked for, hourly) rows up to date. Every run rebuilds the
 * current and the previous bucket from the raw samples, so a late or missed run mends
 * itself and running twice does no harm.
 */
class Rollups
{
    private const int CHUNK = 200;

    public function refresh(int $now, Retention $retention): void
    {
        $start = Rollup::bucket($now, Rollup::FIVE_MINUTES) - Rollup::FIVE_MINUTES;

        $this->fromSamples(Tables::NODE_SAMPLES, Tables::NODE_ROLLUPS, 'node_id', $start, Rollup::NODE_METRICS, Rollup::NODE_LAST, Rollup::NODE_PARTIAL, false);
        $this->fromSamples(Tables::SERVER_SAMPLES, Tables::SERVER_ROLLUPS, 'server_id', $start, Rollup::SERVER_METRICS, Rollup::SERVER_LAST, [], true);

        if ($retention->keepsHourly()) {
            $hour = Rollup::bucket($now, Rollup::HOUR) - Rollup::HOUR;
            $this->hourly(Tables::NODE_ROLLUPS, 'node_id', $hour, Rollup::NODE_METRICS, Rollup::NODE_LAST, false);
            $this->hourly(Tables::SERVER_ROLLUPS, 'server_id', $hour, Rollup::SERVER_METRICS, Rollup::SERVER_LAST, true);
        }
    }

    /**
     * @param  list<string>  $metrics
     * @param  list<string>  $last
     * @param  list<string>  $guarded  metrics left out of incomplete minutes
     */
    private function fromSamples(string $samples, string $rollups, string $key, int $start, array $metrics, array $last, array $guarded, bool $running): void
    {
        $bucket = Sql::bucket('taken_at', Rollup::FIVE_MINUTES, DB::connection()->getDriverName());

        $rows = DB::table($samples)
            ->where('taken_at', '>=', $start)
            ->groupBy($key)->groupByRaw($bucket)
            ->selectRaw("{$key} AS owner_id, {$bucket} AS bucket, ".implode(', ', Sql::fromSamples($metrics, $last, $guarded, $running)))
            ->get();

        $this->write($rollups, $key, $this->rows($rows, $key, Rollup::FIVE_MINUTES));
    }

    /**
     * @param  list<string>  $metrics
     * @param  list<string>  $last
     */
    private function hourly(string $rollups, string $key, int $start, array $metrics, array $last, bool $running): void
    {
        $bucket = Sql::bucket('bucket', Rollup::HOUR, DB::connection()->getDriverName());

        $rows = DB::table($rollups)
            ->where('resolution', Rollup::FIVE_MINUTES)->where('bucket', '>=', $start)
            ->groupBy($key)->groupByRaw($bucket)
            ->selectRaw("{$key} AS owner_id, {$bucket} AS bucket, ".implode(', ', Sql::fromBuckets($metrics, $last, $running)))
            ->get();

        $this->write($rollups, $key, $this->rows($rows, $key, Rollup::HOUR));
    }

    /**
     * @param  iterable<object>  $rows  grouped rows with owner_id and bucket
     * @return list<array<string, mixed>>
     */
    private function rows(iterable $rows, string $key, int $resolution): array
    {
        $out = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $owner = (int) $row['owner_id'];
            unset($row['owner_id']);
            $row['bucket'] = (int) $row['bucket'];
            $row['samples'] = (int) $row['samples'];
            if (isset($row['running'])) {
                $row['running'] = (int) $row['running'];
            }
            $out[] = [$key => $owner, 'resolution' => $resolution, ...$row];
        }

        return $out;
    }

    /** @param list<array<string, mixed>> $rows */
    private function write(string $table, string $key, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $update = array_values(array_diff(array_keys($rows[0]), [$key, 'resolution', 'bucket']));

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table($table)->upsert($chunk, [$key, 'resolution', 'bucket'], $update);
        }
    }
}
