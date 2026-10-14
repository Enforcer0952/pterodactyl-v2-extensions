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

    public function testGroupSortsAndSplitsIntoBuckets(): void
    {
        $rows = [
            ['taken_at' => 1_760_000_460, 'x' => 3],
            ['taken_at' => 1_760_000_100, 'x' => 1],
            ['taken_at' => 1_760_000_160, 'x' => 2],
        ];

        $groups = Rollup::group($rows, 300);

        $this->assertSame([1_760_000_100, 1_760_000_400], array_keys($groups));
        $this->assertSame([1, 2], array_column($groups[1_760_000_100], 'x'));
        $this->assertSame([3], array_column($groups[1_760_000_400], 'x'));
    }

    public function testAggregateAveragesAndKeepsPeaks(): void
    {
        $rows = [
            ['taken_at' => 1, 'mem' => 100, 'cpu' => 10.0, 'net_rx' => 5],
            ['taken_at' => 2, 'mem' => 300, 'cpu' => 30.0, 'net_rx' => 9],
            ['taken_at' => 3, 'mem' => 200, 'cpu' => null, 'net_rx' => null],
        ];

        $out = Rollup::aggregate($rows, ['mem', 'cpu'], ['net_rx']);

        $this->assertSame(3, $out['samples']);
        $this->assertEquals(200, $out['mem_avg']);
        $this->assertSame(300, $out['mem_max']);
        $this->assertEquals(20, $out['cpu_avg']);
        $this->assertEquals(30, $out['cpu_max']);
        $this->assertSame(9, $out['net_rx']);
    }

    public function testAMetricThatIsNullEverywhereStaysNull(): void
    {
        $out = Rollup::aggregate([['taken_at' => 1, 'disk_used' => null], ['taken_at' => 2]], ['disk_used'], ['cpus']);

        $this->assertNull($out['disk_used_avg']);
        $this->assertNull($out['disk_used_max']);
        $this->assertNull($out['cpus']);
        $this->assertSame(2, $out['samples']);
    }

    public function testZeroIsAValue(): void
    {
        $out = Rollup::aggregate([['taken_at' => 1, 'mem' => 0], ['taken_at' => 2, 'mem' => 0]], ['mem']);

        $this->assertEquals(0, $out['mem_avg']);
        $this->assertSame(0, $out['mem_max']);
    }

    public function testRegroupWeightsByHowManySamplesWentIn(): void
    {
        $five = [
            ['bucket' => 3600, 'samples' => 5, 'mem_avg' => 100.0, 'mem_max' => 150, 'mem_total' => 8],
            ['bucket' => 3900, 'samples' => 1, 'mem_avg' => 400.0, 'mem_max' => 400, 'mem_total' => 9],
            ['bucket' => 7200, 'samples' => 5, 'mem_avg' => 50.0, 'mem_max' => 60, 'mem_total' => 9],
        ];

        $hours = Rollup::regroup($five, 3600, ['mem'], ['mem_total']);

        $this->assertCount(2, $hours);
        $this->assertSame(3600, $hours[0]['bucket']);
        $this->assertSame(6, $hours[0]['samples']);
        $this->assertEqualsWithDelta((100 * 5 + 400) / 6, $hours[0]['mem_avg'], 0.0001);
        $this->assertSame(400, $hours[0]['mem_max']);
        $this->assertSame(9, $hours[0]['mem_total']);
        $this->assertSame(7200, $hours[1]['bucket']);
    }

    public function testRegroupSkipsBucketsWithoutAValue(): void
    {
        $out = Rollup::regroup([
            ['bucket' => 0, 'samples' => 5, 'load_avg' => null, 'load_max' => null],
            ['bucket' => 300, 'samples' => 5, 'load_avg' => 2.0, 'load_max' => 3.0],
        ], 3600, ['load']);

        $this->assertEquals(2.0, $out[0]['load_avg']);
        $this->assertEquals(3.0, $out[0]['load_max']);
        $this->assertSame(10, $out[0]['samples']);
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
