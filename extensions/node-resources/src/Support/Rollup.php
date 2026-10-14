<?php

namespace NodeResources\Support;

/**
 * Turns raw one-minute samples into buckets (5 minutes, or an hour) with an average and
 * a peak per metric, and merges buckets into wider ones for the graphs.
 *
 * Rows are plain arrays. Raw samples carry `taken_at`, buckets carry `bucket` (the start
 * time) and `samples` (how many raw samples went in). A metric that is null in every row
 * stays null.
 */
final class Rollup
{
    public const int FIVE_MINUTES = 300;

    public const int HOUR = 3600;

    /** Metrics kept per node: host values come from the probe, `sv_` values are the sum over its servers. */
    public const array NODE_METRICS = ['mem_used', 'disk_used', 'load1', 'sv_mem', 'sv_disk', 'sv_cpu'];

    /** Values that are only ever the latest: sizes that do not average. */
    public const array NODE_LAST = ['mem_total', 'disk_total', 'cpus'];

    public const array SERVER_METRICS = ['mem', 'cpu', 'disk'];

    /** Network counters only grow while the server runs, so the biggest value is the newest. */
    public const array SERVER_LAST = ['net_rx', 'net_tx'];

    public static function bucket(int $timestamp, int $resolution): int
    {
        return intdiv($timestamp, $resolution) * $resolution;
    }

    /**
     * @param  list<array<string, mixed>>  $rows  raw samples
     * @return array<int, list<array<string, mixed>>> bucket start => its samples, oldest first
     */
    public static function group(array $rows, int $resolution): array
    {
        usort($rows, fn (array $a, array $b): int => $a['taken_at'] <=> $b['taken_at']);

        $groups = [];
        foreach ($rows as $row) {
            $groups[self::bucket((int) $row['taken_at'], $resolution)][] = $row;
        }

        return $groups;
    }

    /**
     * @param  list<array<string, mixed>>  $rows  raw samples of one bucket, oldest first
     * @param  list<string>  $metrics
     * @param  list<string>  $last
     * @return array<string, int|float|null>
     */
    public static function aggregate(array $rows, array $metrics, array $last = []): array
    {
        $out = ['samples' => count($rows)];

        foreach ($metrics as $metric) {
            $values = [];
            foreach ($rows as $row) {
                if (isset($row[$metric]) && is_numeric($row[$metric])) {
                    $values[] = $row[$metric] + 0;
                }
            }
            $out[$metric.'_avg'] = $values === [] ? null : array_sum($values) / count($values);
            $out[$metric.'_max'] = $values === [] ? null : max($values);
        }

        foreach ($last as $metric) {
            $out[$metric] = null;
            foreach (array_reverse($rows) as $row) {
                if (isset($row[$metric]) && is_numeric($row[$metric])) {
                    $out[$metric] = $row[$metric] + 0;
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * Merge finished buckets into wider ones. The average is weighted by sample count.
     *
     * @param  list<array<string, mixed>>  $buckets  rows with `bucket`, `samples` and the aggregate keys
     * @param  list<string>  $metrics
     * @param  list<string>  $last
     * @return list<array<string, int|float|null>> oldest first, each with `bucket` and `samples`
     */
    public static function regroup(array $buckets, int $width, array $metrics, array $last = []): array
    {
        usort($buckets, fn (array $a, array $b): int => $a['bucket'] <=> $b['bucket']);

        $groups = [];
        foreach ($buckets as $row) {
            $groups[self::bucket((int) $row['bucket'], $width)][] = $row;
        }

        $out = [];
        foreach ($groups as $start => $rows) {
            $merged = ['bucket' => $start, 'samples' => array_sum(array_map(fn (array $row): int => (int) $row['samples'], $rows))];

            foreach ($metrics as $metric) {
                $weight = 0;
                $sum = 0.0;
                $max = null;
                foreach ($rows as $row) {
                    $avg = $row[$metric.'_avg'] ?? null;
                    if ($avg !== null) {
                        $w = max(1, (int) $row['samples']);
                        $sum += $avg * $w;
                        $weight += $w;
                    }
                    $peak = $row[$metric.'_max'] ?? null;
                    if ($peak !== null && ($max === null || $peak > $max)) {
                        $max = $peak;
                    }
                }
                $merged[$metric.'_avg'] = $weight === 0 ? null : $sum / $weight;
                $merged[$metric.'_max'] = $max;
            }

            foreach ($last as $metric) {
                $merged[$metric] = null;
                foreach (array_reverse($rows) as $row) {
                    if (($row[$metric] ?? null) !== null) {
                        $merged[$metric] = $row[$metric];
                        break;
                    }
                }
            }

            $out[] = $merged;
        }

        return $out;
    }

    /**
     * The width a graph should use so a range shows about $points points, kept to a
     * multiple of the stored resolution.
     */
    public static function width(int $seconds, int $points, int $resolution): int
    {
        $width = (int) ceil($seconds / max(1, $points) / $resolution) * $resolution;

        return max($resolution, $width);
    }
}
