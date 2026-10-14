<?php

namespace NodeResources\Support;

/**
 * Limits to suggest for servers that have none (0 = unlimited): what they really used plus
 * a quarter, rounded up to a size people would type, and never below what they use.
 */
final class Suggestions
{
    /** Extra room on top of use. */
    public const float HEADROOM = 1.25;

    /** A suggestion needs a day of history behind it. */
    public const int MIN_HISTORY = 86_400;

    /** Memory limit in MB from the 7-day peak (and what is used right now). Null when nothing was ever used. */
    public static function memory(?int $peakBytes, ?int $nowBytes): ?int
    {
        $need = max((int) $peakBytes, (int) $nowBytes);

        return $need <= 0 ? null : self::round((int) ceil($need * self::HEADROOM / Format::MIB), self::memoryStep(...));
    }

    /** Disk limit in MB from the current use. Null when the server uses nothing. */
    public static function disk(?int $nowBytes): ?int
    {
        $need = (int) $nowBytes;

        return $need <= 0 ? null : self::round((int) ceil($need * self::HEADROOM / Format::MIB), self::diskStep(...));
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

    /** Whether the server has been watched for long enough: first data point at least a day ago. */
    public static function hasHistory(?int $firstSeen, int $now): bool
    {
        return $firstSeen !== null && $now - $firstSeen >= self::MIN_HISTORY;
    }

    /** @param callable(int): int $step */
    private static function round(int $mb, callable $step): int
    {
        $size = max(1, $step($mb));

        return (int) (ceil($mb / $size) * $size);
    }
}
