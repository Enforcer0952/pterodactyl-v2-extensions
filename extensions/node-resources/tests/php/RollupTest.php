<?php

namespace NodeResources\Tests;

use NodeResources\Support\Retention;
use NodeResources\Support\Rollup;
use PHPUnit\Framework\TestCase;

final class RollupTest extends TestCase
{
    public function testBucketsStartOnTheResolution(): void
    {
        $this->assertSame(1_760_000_100, Rollup::bucket(1_760_000_100, 300));
        $this->assertSame(1_760_000_100, Rollup::bucket(1_760_000_399, 300));
        $this->assertSame(1_760_000_400, Rollup::bucket(1_760_000_400, 300));
        $this->assertSame(1_760_000_400, Rollup::bucket(1_760_000_999, 3600));
    }

    public function testWidthKeepsToTheStoredResolution(): void
    {
        $this->assertSame(300, Rollup::width(86_400, 288, 300));
        $this->assertSame(2100, Rollup::width(7 * 86_400, 300, 300));
        $this->assertSame(300, Rollup::width(3600, 300, 300));
        // 7 days in at most 400 points: 1512 s rounds up to 1800.
        $this->assertSame(1800, Rollup::width(7 * 86_400, 400, 300));
    }

    public function testRetentionOptions(): void
    {
        $this->assertSame('30d', Retention::from(null)->key);
        $this->assertSame('30d', Retention::from('nonsense')->key);
        $this->assertFalse(Retention::from('30d')->keepsHourly());
        $this->assertSame(90, Retention::from('90d')->fiveMinuteDays);
        $year = Retention::from('1y');
        $this->assertTrue($year->keepsHourly());
        $this->assertSame(30, $year->fiveMinuteDays);
        $this->assertSame(365 * 86400, $year->maxRange());
    }

    public function testPruneCutoffs(): void
    {
        $now = 1_760_000_000;

        $thirty = Retention::from('30d')->cutoffs($now);
        $this->assertSame($now - 172_800, $thirty['raw']);
        $this->assertSame($now - 30 * 86400, $thirty['five_minutes']);
        // No hourly rows are kept: everything up to now goes.
        $this->assertGreaterThan($now, $thirty['hourly']);

        $ninety = Retention::from('90d')->cutoffs($now);
        $this->assertSame($now - 90 * 86400, $ninety['five_minutes']);

        $year = Retention::from('1y')->cutoffs($now);
        $this->assertSame($now - 30 * 86400, $year['five_minutes']);
        $this->assertSame($now - 365 * 86400, $year['hourly']);
    }
}
