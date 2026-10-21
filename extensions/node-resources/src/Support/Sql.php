<?php

namespace NodeResources\Support;

/** Small pieces of SQL that differ between the databases the panel can run on. */
final class Sql
{
    /**
     * The start of the bucket a timestamp column falls in. MySQL and MariaDB divide to a
     * decimal, so they round down; the others divide whole numbers.
     */
    public static function bucket(string $column, int $width, string $driver): string
    {
        $width = max(1, $width);

        return in_array($driver, ['mysql', 'mariadb'], true)
            ? "(FLOOR({$column} / {$width}) * {$width})"
            : "(({$column} / {$width}) * {$width})";
    }

    /**
     * Average of an average column, weighted by how many samples stood behind each row.
     * Rows without a value do not count towards the weight.
     */
    public static function weighted(string $metric, string $weight = 'samples'): string
    {
        return "SUM({$metric} * {$weight}) / NULLIF(SUM(CASE WHEN {$metric} IS NOT NULL THEN {$weight} END), 0)";
    }

    /**
     * Select list that raises raw samples to buckets: a count, an average and a peak per
     * metric, the biggest value of the columns that are sizes, and a count of rows where the
     * server was running. Metrics in $guarded only count samples that were complete.
     *
     * @param  list<string>  $metrics
     * @param  list<string>  $last
     * @param  list<string>  $guarded
     * @return list<string>
     */
    public static function fromSamples(array $metrics, array $last, array $guarded = [], bool $running = false): array
    {
        $out = ['COUNT(*) AS samples'];
        if ($running) {
            $out[] = "SUM(CASE WHEN state = 'running' THEN 1 ELSE 0 END) AS running";
        }
        foreach ($metrics as $metric) {
            $column = in_array($metric, $guarded, true) ? "CASE WHEN partial = 0 THEN {$metric} END" : $metric;
            $out[] = "AVG({$column}) AS {$metric}_avg";
            $out[] = "MAX({$column}) AS {$metric}_max";
        }
        foreach ($last as $column) {
            $out[] = "MAX({$column}) AS {$column}";
        }

        return $out;
    }

    /**
     * Select list that merges finished buckets into wider ones: the average is weighted by
     * sample count, the peak is the biggest peak.
     *
     * @param  list<string>  $metrics
     * @param  list<string>  $last
     * @return list<string>
     */
    public static function fromBuckets(array $metrics, array $last, bool $running = false): array
    {
        $out = ['SUM(samples) AS samples'];
        if ($running) {
            $out[] = 'SUM(running) AS running';
        }
        foreach ($metrics as $metric) {
            $out[] = self::weighted("{$metric}_avg")." AS {$metric}_avg";
            $out[] = "MAX({$metric}_max) AS {$metric}_max";
        }
        foreach ($last as $column) {
            $out[] = "MAX({$column}) AS {$column}";
        }

        return $out;
    }
}
