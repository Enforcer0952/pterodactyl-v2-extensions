<?php

namespace CrashGuard\Support;

/**
 * "No more than N crashes in M minutes."
 */
final class CrashWindow
{
    /**
     * How many of these crashes (unix seconds) count right now: the ones that happened
     * less than $minutes ago and after the last time the counter was cleared.
     *
     * @param  list<int>  $crashTimes
     */
    public static function count(array $crashTimes, int $now, int $minutes, ?int $clearedAt = null): int
    {
        $count = 0;
        foreach ($crashTimes as $time) {
            if ($now - $time < $minutes * 60 && $time <= $now && ($clearedAt === null || $time > $clearedAt)) {
                $count++;
            }
        }

        return $count;
    }

    /** @param  list<int>  $crashTimes */
    public static function tripped(array $crashTimes, int $now, int $max, int $minutes, ?int $clearedAt = null): bool
    {
        return self::count($crashTimes, $now, $minutes, $clearedAt) >= $max;
    }
}
