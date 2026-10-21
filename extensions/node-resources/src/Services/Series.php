<?php

namespace NodeResources\Services;

use Illuminate\Support\Facades\DB;
use NodeResources\Support\Sql;

/** Reads rollup rows already merged into wider buckets by the database. */
class Series
{
    /**
     * @param  list<string>  $metrics  metrics to average (and take the peak of)
     * @param  list<string>  $last  size columns of which the biggest value is kept
     * @return list<array<string, mixed>> oldest first, each with `bucket`, `samples` and the aggregate columns
     */
    public function merged(string $table, string $key, int $id, int $resolution, int $since, int $width, array $metrics, array $last = []): array
    {
        $bucket = Sql::bucket('bucket', $width, DB::connection()->getDriverName());

        return DB::table($table)
            ->where($key, $id)->where('resolution', $resolution)->where('bucket', '>=', $since)
            ->groupByRaw($bucket)
            ->orderByRaw($bucket)
            ->selectRaw($bucket.' AS bucket, '.implode(', ', Sql::fromBuckets($metrics, $last)))
            ->get()
            ->map(function (object $row): array {
                $out = (array) $row;
                $out['bucket'] = (int) $out['bucket'];
                $out['samples'] = (int) $out['samples'];

                return $out;
            })
            ->all();
    }
}
