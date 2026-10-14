<?php

namespace NodeResources\Support;

/** A straight line through recent disk use, to say when the disk would be full. */
final class Forecast
{
    /** Needs this much history before it says anything. */
    public const int MIN_SPAN = 86_400;

    public const int MIN_POINTS = 12;

    /** A date further away than this is "not filling up" for practical purposes. */
    public const int MAX_DAYS = 365;

    /**
     * Least squares line through the points: bytes = base + slope * (time - origin).
     *
     * @param  list<array{0: int|float, 1: int|float}>  $points  [time in seconds, bytes used]
     * @return array{slope: float, origin: float, base: float}|null slope in bytes per second
     */
    public static function fit(array $points): ?array
    {
        $n = count($points);
        if ($n < 2) {
            return null;
        }

        // Work from the first point so the numbers stay small.
        $origin = (float) $points[0][0];
        $sumX = $sumY = $sumXY = $sumXX = 0.0;
        foreach ($points as [$time, $value]) {
            $x = (float) $time - $origin;
            $y = (float) $value;
            $sumX += $x;
            $sumY += $y;
            $sumXY += $x * $y;
            $sumXX += $x * $x;
        }

        $denominator = $n * $sumXX - $sumX * $sumX;
        if (abs($denominator) < 1e-9) {
            return null;
        }

        $slope = ($n * $sumXY - $sumX * $sumY) / $denominator;

        return ['slope' => $slope, 'origin' => $origin, 'base' => ($sumY - $slope * $sumX) / $n];
    }

    /**
     * @param  list<array{0: int|float, 1: int|float}>  $points  [time in seconds, bytes used], oldest first
     * @return array{days: ?float, growth_per_day: float}|null days until the disk is full; null when
     *                                                         it is not filling or inside MAX_DAYS. Null result
     *                                                         when there is too little history.
     */
    public static function daysUntilFull(array $points, float $total, int $now): ?array
    {
        if (count($points) < self::MIN_POINTS || $total <= 0) {
            return null;
        }
        $span = $points[count($points) - 1][0] - $points[0][0];
        if ($span < self::MIN_SPAN) {
            return null;
        }

        $line = self::fit($points);
        if ($line === null) {
            return null;
        }

        $growth = $line['slope'] * 86400;
        $usedNow = $line['base'] + $line['slope'] * ($now - $line['origin']);

        // Growth of under 1 MB a day is noise.
        if ($growth < 1_048_576) {
            return ['days' => null, 'growth_per_day' => max(0.0, $growth)];
        }
        if ($usedNow >= $total) {
            return ['days' => 0.0, 'growth_per_day' => $growth];
        }

        $days = ($total - $usedNow) / $growth;

        return ['days' => $days > self::MAX_DAYS ? null : $days, 'growth_per_day' => $growth];
    }
}
