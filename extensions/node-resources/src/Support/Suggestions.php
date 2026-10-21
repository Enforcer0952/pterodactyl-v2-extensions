<?php

namespace NodeResources\Support;

/**
 * Limits to suggest for servers that have none (0 = unlimited): what they really used plus
 * a quarter of memory or half again of disk, rounded up to a size people would type, never
 * below what they use and never below a floor (512 MB memory, 1 GB disk).
 */
final class Suggestions
{
    /** Extra room on top of memory use. */
    public const float HEADROOM = 1.25;

    /** Extra room on top of disk use: disks fill up and cannot be fixed with a restart. */
    public const float DISK_HEADROOM = 1.5;

    /** Smallest limits ever suggested, in MB. */
    public const int MEMORY_FLOOR = 512;

    public const int DISK_FLOOR = 1024;

    /** A suggestion needs three days of history behind it... */
    public const int MIN_HISTORY = 259_200;

    /** ...of which the server must have been running for at least six hours (one-minute samples). */
    public const int MIN_RUNNING_SAMPLES = 360;

    /** Memory limit in MB from the 7-day peak (and what is used right now). Null when nothing was ever used. */
    public static function memory(?int $peakBytes, ?int $nowBytes): ?int
    {
        $need = max((int) $peakBytes, (int) $nowBytes);

        return $need <= 0 ? null : max(self::MEMORY_FLOOR, self::round((int) ceil($need * self::HEADROOM / Format::MIB), self::memoryStep(...)));
    }

    /** Disk limit in MB from the current use. Null when the server uses nothing. */
    public static function disk(?int $nowBytes): ?int
    {
        $need = (int) $nowBytes;

        return $need <= 0 ? null : max(self::DISK_FLOOR, self::round((int) ceil($need * self::DISK_HEADROOM / Format::MIB), self::diskStep(...)));
    }

    public static function memoryStep(int $mb): int
    {
        return match (true) {
            $mb < 1024 => 64,
            $mb < 4096 => 256,
            $mb < 16384 => 512,
            default => 1024,
        };
    }

    public static function diskStep(int $mb): int
    {
        return match (true) {
            $mb < 1024 => 128,
            $mb < 10240 => 512,
            $mb < 102400 => 1024,
            default => 5120,
        };
    }

    /**
     * Whether there is enough to go on: the first data point at least three days ago and at
     * least six hours of it with the server running.
     */
    public static function hasHistory(?int $firstSeen, int $runningSamples, int $now): bool
    {
        return $firstSeen !== null
            && $now - $firstSeen >= self::MIN_HISTORY
            && $runningSamples >= self::MIN_RUNNING_SAMPLES;
    }

    /**
     * Whether a limit the admin confirmed (in bytes, as the screen showed it) is exactly the
     * suggestion worked out now. A suggestion that moved since the screen was drawn is not applied.
     */
    public static function confirms(?int $suggestedMb, mixed $confirmedBytes): bool
    {
        return $suggestedMb !== null && is_int($confirmedBytes) && $confirmedBytes === $suggestedMb * Format::MIB;
    }

    /** @param callable(int): int $step */
    private static function round(int $mb, callable $step): int
    {
        $size = max(1, $step($mb));

        return (int) (ceil($mb / $size) * $size);
    }
}
