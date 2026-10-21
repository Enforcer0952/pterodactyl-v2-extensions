<?php

namespace McContent\Support;

/** A wall-clock allowance for a job made of several downloads. */
final class TimeBudget
{
    private readonly float $deadline;

    /** @param  \Closure():float|null  $clock  seconds, injectable for tests */
    public function __construct(private readonly int $seconds, private readonly ?\Closure $clock = null)
    {
        $this->deadline = $this->now() + $seconds;
    }

    public function total(): int
    {
        return $this->seconds;
    }

    public function remaining(): int
    {
        return max(0, (int) floor($this->deadline - $this->now()));
    }

    public function exhausted(): bool
    {
        return $this->remaining() < 1;
    }

    /** The timeout to give one download: what is left, but at most $max. */
    public function slice(int $max): int
    {
        return max(1, min($max, $this->remaining()));
    }

    private function now(): float
    {
        return $this->clock !== null ? ($this->clock)() : microtime(true);
    }
}
