<?php

namespace NodeResources\Support;

/**
 * How long history is kept. Raw one-minute samples always go after 48 hours.
 *
 *   30d: 5-minute points for 30 days
 *   90d: 5-minute points for 90 days
 *   1y:  5-minute points for 30 days, hourly points for a year
 */
final class Retention
{
    public const int RAW_SECONDS = 172_800;

    public const string DEFAULT = '30d';

    public const array OPTIONS = ['30d', '90d', '1y'];

    private function __construct(
        public readonly string $key,
        public readonly int $fiveMinuteDays,
        public readonly int $hourlyDays,
    ) {}

    public static function from(mixed $setting): self
    {
        return match ($setting) {
            '90d' => new self('90d', 90, 0),
            '1y' => new self('1y', 30, 365),
            default => new self('30d', 30, 0),
        };
    }

    public function keepsHourly(): bool
    {
        return $this->hourlyDays > 0;
    }

    /** @return array{raw: int, five_minutes: int, hourly: int} rows older than these times are deleted; hourly is the current time when none are kept */
    public function cutoffs(int $now): array
    {
        return [
            'raw' => $now - self::RAW_SECONDS,
            'five_minutes' => $now - $this->fiveMinuteDays * 86400,
            'hourly' => $this->keepsHourly() ? $now - $this->hourlyDays * 86400 : $now + 1,
        ];
    }

    /** The longest range the graphs can show, in seconds. */
    public function maxRange(): int
    {
        return max($this->fiveMinuteDays, $this->hourlyDays) * 86400;
    }
}
