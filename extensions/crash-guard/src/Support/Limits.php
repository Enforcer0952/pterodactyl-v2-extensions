<?php

namespace CrashGuard\Support;

/**
 * The allowed range for the two numbers a server is guarded with.
 */
final class Limits
{
    public const int MIN_CRASHES = 2;

    public const int MAX_CRASHES = 20;

    public const int MIN_MINUTES = 1;

    public const int MAX_MINUTES = 120;

    public const int DEFAULT_CRASHES = 3;

    public const int DEFAULT_MINUTES = 10;

    public static function crashes(mixed $value): int
    {
        return self::clamp($value, self::MIN_CRASHES, self::MAX_CRASHES, self::DEFAULT_CRASHES);
    }

    public static function minutes(mixed $value): int
    {
        return self::clamp($value, self::MIN_MINUTES, self::MAX_MINUTES, self::DEFAULT_MINUTES);
    }

    private static function clamp(mixed $value, int $min, int $max, int $default): int
    {
        if (is_string($value) && preg_match('/\A\d{1,9}\z/D', $value) === 1) {
            $value = (int) $value;
        }
        if (is_float($value) && is_finite($value)) {
            $value = (int) $value;
        }
        if (! is_int($value)) {
            return $default;
        }

        return max($min, min($max, $value));
    }
}
