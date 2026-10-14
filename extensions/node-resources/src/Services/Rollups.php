<?php

namespace NodeResources\Services;

use Illuminate\Support\Facades\DB;
use NodeResources\Support\Retention;
use NodeResources\Support\Rollup;
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

        $this->fromSamples(Tables::NODE_SAMPLES, Tables::NODE_ROLLUPS, 'node_id', $start, Rollup::NODE_METRICS, Rollup::NODE_LAST);
        $this->fromSamples(Tables::SERVER_SAMPLES, Tables::SERVER_ROLLUPS, 'server_id', $start, Rollup::SERVER_METRICS, Rollup::SERVER_LAST);

        if ($retention->keepsHourly()) {
            $hour = Rollup::bucket($now, Rollup::HOUR) - Rollup::HOUR;
            $this->hourly(Tables::NODE_ROLLUPS, 'node_id', $hour, Rollup::NODE_METRICS, Rollup::NODE_LAST);
            $this->hourly(Tables::SERVER_ROLLUPS, 'server_id', $hour, Rollup::SERVER_METRICS, Rollup::SERVER_LAST);
        }
    }

    /**
     * @param  list<string>  $metrics
     * @param  list<string>  $last
     */
    private function fromSamples(string $samples, string $rollups, string $key, int $start, array $metrics, array $last): void
    {
        $byOwner = [];
        foreach (DB::table($samples)->where('taken_at', '>=', $start)->get() as $row) {
            $byOwner[$row->{$key}][] = (array) $row;
        }

        $out = [];
        foreach ($byOwner as $owner => $rows) {
            foreach (Rollup::group($rows, Rollup::FIVE_MINUTES) as $bucket => $group) {
                $out[] = [$key => $owner, 'resolution' => Rollup::FIVE_MINUTES, 'bucket' => $bucket, ...Rollup::aggregate($group, $metrics, $last)];
            }
        }

        $this->write($rollups, $key, $out);
    }

    /**
     * @param  list<string>  $metrics
     * @param  list<string>  $last
     */
    private function hourly(string $rollups, string $key, int $start, array $metrics, array $last): void
    {
        $byOwner = [];
        $rows = DB::table($rollups)->where('resolution', Rollup::FIVE_MINUTES)->where('bucket', '>=', $start)->get();
        foreach ($rows as $row) {
            $byOwner[$row->{$key}][] = (array) $row;
        }

        $out = [];
        foreach ($byOwner as $owner => $buckets) {
            foreach (Rollup::regroup($buckets, Rollup::HOUR, $metrics, $last) as $merged) {
                $out[] = [$key => $owner, 'resolution' => Rollup::HOUR, ...$merged];
            }
        }

        $this->write($rollups, $key, $out);
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
