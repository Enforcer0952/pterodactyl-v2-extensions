<?php

namespace NodeResources\Tests;

use NodeResources\Support\Capacity;
use NodeResources\Support\Format;
use NodeResources\Support\Warnings;
use PHPUnit\Framework\TestCase;

final class CapacityTest extends TestCase
{
    private const int MIB = 1_048_576;

    private const int GIB = 1_073_741_824;

    private static function server(int $memory, int $disk, int $cpu, array $extra = []): array
    {
        return array_merge([
            'memory' => $memory, 'disk' => $disk, 'cpu' => $cpu,
            'mem_now' => null, 'disk_now' => null, 'cpu_now' => null,
            'mem_peak' => null, 'disk_peak' => null, 'cpu_peak' => null,
        ], $extra);
    }

    private static function host(array $extra = []): array
    {
        return array_merge([
            'mem_total' => 16 * self::GIB, 'mem_available' => 6 * self::GIB,
            'disk_total' => 500 * self::GIB, 'disk_free' => 150 * self::GIB,
            'cpu_threads' => 8, 'load1' => 1.5,
        ], $extra);
    }

    private static function node(array $extra = []): array
    {
        return array_merge(['memory' => 16384, 'memory_overallocate' => 0, 'disk' => 512000, 'disk_overallocate' => 0], $extra);
    }

    public function testSumsLimitsAndUse(): void
    {
        $servers = [
            self::server(4096, 100000, 200, ['mem_now' => 3 * self::GIB, 'disk_now' => 50 * self::GIB, 'cpu_now' => 120.5]),
            self::server(2048, 50000, 100, ['mem_now' => 1 * self::GIB, 'disk_now' => 20 * self::GIB, 'cpu_now' => 10.0]),
        ];

        $result = Capacity::summarize(self::node(), $servers, self::host());

        $this->assertSame(6144 * self::MIB, $result['ram']['allocated']);
        $this->assertSame(4 * self::GIB, $result['ram']['servers_used']);
        $this->assertSame(10 * self::GIB, $result['ram']['host_used']);
        $this->assertSame(6 * self::GIB, $result['ram']['host_free']);
        $this->assertSame(0, $result['ram']['unlimited']);
        $this->assertSame(150000 * self::MIB, $result['disk']['allocated']);
        $this->assertSame(350 * self::GIB, $result['disk']['host_used']);
        $this->assertSame(300, $result['cpu']['allocated']);
        $this->assertSame(130.5, $result['cpu']['servers_used']);
        $this->assertSame(800, $result['cpu']['capacity']);
    }

    public function testWhatIsLeftToAllocateFollowsTheNodeSettings(): void
    {
        $servers = [self::server(8192, 0, 0)];

        $none = Capacity::summarize(self::node(), $servers, self::host());
        $this->assertSame(16384 * self::MIB, $none['ram']['panel_limit']);
        $this->assertSame(8192 * self::MIB, $none['ram']['unallocated']);

        $over = Capacity::summarize(self::node(['memory_overallocate' => 50]), $servers, self::host());
        $this->assertSame(24576 * self::MIB, $over['ram']['panel_limit']);
        $this->assertSame(16384 * self::MIB, $over['ram']['unallocated']);
        $this->assertSame(50, $over['ram']['overallocate']);

        // Never negative.
        $full = Capacity::summarize(self::node(), [self::server(20000, 0, 0)], self::host());
        $this->assertSame(0, $full['ram']['unallocated']);
    }

    public function testUnlimitedServersCountByTheirPeak(): void
    {
        $servers = [
            self::server(4096, 1000, 100),
            // Unlimited: peaked at 6 GB this week, uses 2 GB now.
            self::server(0, 0, 0, ['mem_now' => 2 * self::GIB, 'mem_peak' => 6 * self::GIB]),
        ];

        $ram = Capacity::summarize(self::node(), $servers, self::host())['ram'];

        $this->assertSame(4096 * self::MIB, $ram['allocated']);
        $this->assertSame(1, $ram['unlimited']);
        $this->assertSame(4 * self::GIB + 6 * self::GIB, $ram['committed']);
        $this->assertFalse($ram['overcommitted']);
    }

    public function testUnlimitedServerUsingMoreNowThanItsPeakCountsByNow(): void
    {
        $servers = [self::server(0, 0, 0, ['mem_now' => 9 * self::GIB, 'mem_peak' => 6 * self::GIB])];

        $this->assertSame(9 * self::GIB, Capacity::summarize(self::node(), $servers, self::host())['ram']['committed']);
    }

    public function testOvercommitOnlyWhenPromisesPassTheHostsRam(): void
    {
        $fits = [self::server(8192, 0, 0), self::server(8192, 0, 0)];
        $this->assertFalse(Capacity::summarize(self::node(), $fits, self::host())['ram']['overcommitted']);

        $over = [self::server(8192, 0, 0), self::server(8192, 0, 0), self::server(1, 0, 0)];
        $this->assertTrue(Capacity::summarize(self::node(), $over, self::host())['ram']['overcommitted']);

        // An unlimited server that has peaked high tips it over as well.
        $unlimited = [self::server(8192, 0, 0), self::server(0, 0, 0, ['mem_peak' => 9 * self::GIB])];
        $this->assertTrue(Capacity::summarize(self::node(), $unlimited, self::host())['ram']['overcommitted']);
    }

    public function testNoUnlimitedGuessWithoutData(): void
    {
        $servers = [self::server(0, 0, 0)];

        $ram = Capacity::summarize(self::node(), $servers, self::host())['ram'];

        $this->assertSame(0, $ram['committed']);
        $this->assertSame(1, $ram['unlimited']);
    }

    public function testWithoutHostNumbersNothingIsInvented(): void
    {
        $host = ['mem_total' => null, 'mem_available' => null, 'disk_total' => null, 'disk_free' => null, 'cpu_threads' => null, 'load1' => null];
        $result = Capacity::summarize(self::node(), [self::server(1024, 1024, 50)], $host);

        $this->assertNull($result['ram']['host_total']);
        $this->assertNull($result['ram']['host_used']);
        $this->assertNull($result['ram']['host_free']);
        $this->assertNull($result['ram']['overcommitted']);
        $this->assertNull($result['disk']['host_free']);
        $this->assertNull($result['cpu']['capacity']);
        // What the panel knows is still there.
        $this->assertSame(1024 * self::MIB, $result['ram']['allocated']);
    }

    public function testCpuUnlimitedServersCountByPeak(): void
    {
        $servers = [self::server(0, 0, 100), self::server(0, 0, 0, ['cpu_now' => 40.2, 'cpu_peak' => 210.4])];

        $cpu = Capacity::summarize(self::node(), $servers, self::host())['cpu'];

        $this->assertSame(100, $cpu['allocated']);
        $this->assertSame(1, $cpu['unlimited']);
        $this->assertSame(100 + 211, $cpu['committed']);
    }

    public function testWarnings(): void
    {
        $servers = [self::server(8192, 0, 0), self::server(8192, 0, 0), self::server(1024, 0, 0)];
        $host = self::host(['disk_free' => 40 * self::GIB]);
        $capacity = Capacity::summarize(self::node(), $servers, $host);

        $warnings = Warnings::forNode($capacity, ['days' => 3.2, 'growth_per_day' => 5.0 * self::GIB]);
        $ids = array_column($warnings, 'id');

        $this->assertContains('disk-high', $ids);
        $this->assertContains('disk-forecast', $ids);
        $this->assertContains('ram-overcommitted', $ids);
        $texts = implode(' ', array_column($warnings, 'text'));
        $this->assertStringContainsString('92% full', $texts);
        $this->assertStringContainsString('40 GB left', $texts);
        $this->assertStringContainsString('about 3 days', $texts);
        $this->assertStringContainsString('17 GB', $texts);
    }

    public function testNoWarningsOnAHealthyNode(): void
    {
        $capacity = Capacity::summarize(self::node(), [self::server(4096, 0, 0)], self::host());

        $this->assertSame([], Warnings::forNode($capacity, null));
        $this->assertSame([], Warnings::forNode($capacity, ['days' => null, 'growth_per_day' => 0.0]));
        // Forecast further away than a week.
        $this->assertSame([], Warnings::forNode($capacity, ['days' => 8.0, 'growth_per_day' => 1.0]));
    }

    public function testWarningThresholdsAreSettings(): void
    {
        $capacity = Capacity::summarize(self::node(), [], self::host(['disk_free' => 100 * self::GIB]));

        // 80 % full.
        $this->assertSame([], Warnings::forNode($capacity, null));
        $this->assertSame(['disk-high'], array_column(Warnings::forNode($capacity, null, 75), 'id'));
        $this->assertSame(['disk-forecast'], array_column(Warnings::forNode($capacity, ['days' => 20.0, 'growth_per_day' => 1.0], 85, 30), 'id'));
    }

    public function testSeverity(): void
    {
        $nearlyFull = Capacity::summarize(self::node(), [], self::host(['disk_free' => 10 * self::GIB]));

        $this->assertSame('danger', Warnings::forNode($nearlyFull, null)[0]['level']);
        $this->assertSame('danger', Warnings::forNode(Capacity::summarize(self::node(), [], self::host()), ['days' => 1.5, 'growth_per_day' => 1.0])[0]['level']);
        $this->assertSame('warning', Warnings::forNode(Capacity::summarize(self::node(), [], self::host()), ['days' => 5.0, 'growth_per_day' => 1.0])[0]['level']);
    }

    public function testFormat(): void
    {
        $this->assertSame('0 B', Format::bytes(0));
        $this->assertSame('512 B', Format::bytes(512));
        $this->assertSame('1.5 KB', Format::bytes(1536));
        $this->assertSame('64 MB', Format::bytes(64 * self::MIB));
        $this->assertSame('15.3 GB', Format::bytes(16_400_000_000));
        $this->assertSame('1.8 TB', Format::bytes(2_000_000_000_000));
        $this->assertSame('0 B', Format::bytes(-5));
        $this->assertSame('less than a day', Format::days(0.4));
        $this->assertSame('1 day', Format::days(1.2));
        $this->assertSame('13 days', Format::days(12.6));
    }
}
