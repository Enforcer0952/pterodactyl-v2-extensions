<?php

namespace NodeResources\Tests;

use NodeResources\Support\Forecast;
use PHPUnit\Framework\TestCase;

final class ForecastTest extends TestCase
{
    private const int NOW = 1_760_000_000;

    private const int GB = 1_073_741_824;

    /** @return list<array{0: int, 1: float}> one point an hour for $days days, ending now */
    private static function growing(int $days, float $startBytes, float $bytesPerDay, float $noise = 0.0): array
    {
        $points = [];
        $hours = $days * 24;
        for ($i = $hours; $i >= 0; $i--) {
            $time = self::NOW - $i * 3600;
            $used = $startBytes + $bytesPerDay * (($hours - $i) / 24);
            // A repeatable wobble.
            $used += $noise * sin($i * 1.7);
            $points[] = [$time, $used];
        }

        return $points;
    }

    public function testFitFindsTheLine(): void
    {
        $line = Forecast::fit([[100, 10], [200, 20], [300, 30]]);

        $this->assertEqualsWithDelta(0.1, $line['slope'], 1e-9);
        $this->assertEqualsWithDelta(10.0, $line['base'], 1e-9);
        $this->assertEqualsWithDelta(100.0, $line['origin'], 1e-9);
    }

    public function testFitNeedsTwoDifferentTimes(): void
    {
        $this->assertNull(Forecast::fit([]));
        $this->assertNull(Forecast::fit([[1, 1]]));
        $this->assertNull(Forecast::fit([[5, 1], [5, 2]]));
    }

    public function testSteadyGrowthGivesDaysUntilFull(): void
    {
        // 100 GB used and growing 5 GB a day on a 200 GB disk: 20 days left.
        $points = self::growing(7, 65 * self::GB, 5 * self::GB);

        $result = Forecast::daysUntilFull($points, 200 * self::GB, self::NOW);

        $this->assertEqualsWithDelta(20.0, $result['days'], 0.1);
        $this->assertEqualsWithDelta(5 * self::GB, $result['growth_per_day'], self::GB * 0.01);
    }

    public function testNoiseDoesNotThrowItOff(): void
    {
        $points = self::growing(7, 65 * self::GB, 5 * self::GB, 0.5 * self::GB);

        $result = Forecast::daysUntilFull($points, 200 * self::GB, self::NOW);

        $this->assertEqualsWithDelta(20.0, $result['days'], 2.0);
    }

    public function testAFlatOrShrinkingDiskNeverFills(): void
    {
        $flat = Forecast::daysUntilFull(self::growing(7, 100 * self::GB, 0), 200 * self::GB, self::NOW);
        $this->assertNull($flat['days']);

        $shrinking = Forecast::daysUntilFull(self::growing(7, 100 * self::GB, -3 * self::GB), 200 * self::GB, self::NOW);
        $this->assertNull($shrinking['days']);
        $this->assertSame(0.0, $shrinking['growth_per_day']);
    }

    public function testVerySlowGrowthIsNotAForecast(): void
    {
        $result = Forecast::daysUntilFull(self::growing(7, 100 * self::GB, 0.1 * self::GB), 200 * self::GB, self::NOW);

        // 100 GB at 0.1 GB a day is 1000 days.
        $this->assertNull($result['days']);
    }

    public function testAFullDiskIsZeroDays(): void
    {
        $result = Forecast::daysUntilFull(self::growing(7, 190 * self::GB, 3 * self::GB), 200 * self::GB, self::NOW);

        $this->assertSame(0.0, $result['days']);
    }

    public function testTooLittleHistoryGivesNothing(): void
    {
        // 6 hours of points.
        $short = array_slice(self::growing(1, 10 * self::GB, 5 * self::GB), -7);
        $this->assertNull(Forecast::daysUntilFull($short, 200 * self::GB, self::NOW));

        // Plenty of time but only a few points.
        $sparse = [[self::NOW - 3 * 86400, 10 * self::GB], [self::NOW, 20 * self::GB]];
        $this->assertNull(Forecast::daysUntilFull($sparse, 200 * self::GB, self::NOW));

        $this->assertNull(Forecast::daysUntilFull([], 200 * self::GB, self::NOW));
    }

    public function testNoTotalGivesNothing(): void
    {
        $this->assertNull(Forecast::daysUntilFull(self::growing(7, 10 * self::GB, self::GB), 0, self::NOW));
    }
}
