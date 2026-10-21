<?php

namespace NodeResources\Support;

/**
 * The shape of the rollups: buckets of 5 minutes or an hour, an average and a peak per
 * metric. The database does the summing (see Sql and Services\Series); this class only
 * says which columns exist and where the buckets start.
 */
final class Rollup
{
    public const int FIVE_MINUTES = 300;

    public const int HOUR = 3600;

    /** Metrics kept per node: host values come from the probe, `sv_` values are the sum over its servers. */
    public const array NODE_METRICS = ['mem_used', 'disk_used', 'load1', 'sv_mem', 'sv_disk', 'sv_cpu'];

    /**
     * Node metrics that are sums over its servers. They are left out of a minute in which not
     * every server could be read, because that sum is too low.
     */
    public const array NODE_PARTIAL = ['sv_mem', 'sv_disk', 'sv_cpu'];

    /** Values that are sizes and do not average: the biggest one in a bucket is kept. */
    public const array NODE_LAST = ['mem_total', 'disk_total', 'cpus'];

    public const array SERVER_METRICS = ['mem', 'cpu', 'disk'];

    /** Network counters only grow while the server runs, so the biggest value is the newest. */
    public const array SERVER_LAST = ['net_rx', 'net_tx'];

    public static function bucket(int $timestamp, int $resolution): int
    {
        return intdiv($timestamp, $resolution) * $resolution;
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
