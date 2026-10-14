<?php

namespace NodeResources\Tests;

use NodeResources\Support\Format;
use NodeResources\Support\Suggestions;
use NodeResources\Support\SystemInfo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SuggestionsTest extends TestCase
{
    private const int MIB = 1_048_576;

    /** @return iterable<string, array{int, int}> */
    public static function memory(): iterable
    {
        // [peak in MB, expected limit in MB]: peak + 25 %, rounded up to the step.
        yield 'tiny' => [10, 64];
        yield 'small' => [100, 128];
        yield 'exactly on a step' => [800, 1024];
        yield 'two gigabytes' => [2000, 2560];
        yield 'four gigabytes' => [4000, 5120];
        yield 'big' => [20000, 25600];
        yield 'huge' => [100000, 125952];
    }

    #[DataProvider('memory')]
    public function testMemoryAddsAQuarterAndRoundsUp(int $peakMb, int $expected): void
    {
        $this->assertSame($expected, Suggestions::memory($peakMb * self::MIB, 0));
    }

    public function testMemoryUsesTheHigherOfPeakAndNow(): void
    {
        $this->assertSame(Suggestions::memory(4000 * self::MIB, 0), Suggestions::memory(1000 * self::MIB, 4000 * self::MIB));
    }

    public function testNothingUsedMeansNoSuggestion(): void
    {
        $this->assertNull(Suggestions::memory(0, 0));
        $this->assertNull(Suggestions::memory(null, null));
        $this->assertNull(Suggestions::disk(0));
        $this->assertNull(Suggestions::disk(null));
    }

    /** @return iterable<string, array{int, int}> */
    public static function disk(): iterable
    {
        yield 'tiny' => [10, 128];
        yield 'half a gigabyte' => [500, 640];
        yield 'one gigabyte' => [1024, 1536];
        yield 'five gigabytes' => [5000, 6656];
        yield 'fifty gigabytes' => [50000, 63488];
        yield 'two hundred gigabytes' => [200000, 250880];
    }

    #[DataProvider('disk')]
    public function testDiskAddsAQuarterAndRoundsUp(int $usedMb, int $expected): void
    {
        $this->assertSame($expected, Suggestions::disk($usedMb * self::MIB));
    }

    public function testNeverBelowUsageNorLessThanAQuarterMore(): void
    {
        for ($mb = 1; $mb < 300_000; $mb = (int) ($mb * 1.07) + 1) {
            $bytes = $mb * self::MIB + 12345;

            $memory = Suggestions::memory($bytes, 0);
            $disk = Suggestions::disk($bytes);

            foreach ([$memory, $disk] as $limit) {
                $this->assertGreaterThanOrEqual($bytes, $limit * self::MIB, "usage {$mb} MB");
                $this->assertGreaterThanOrEqual($bytes * 1.25, $limit * self::MIB, "usage {$mb} MB");
            }
        }
    }

    public function testSuggestionsAreRoundNumbers(): void
    {
        foreach ([77, 530, 1999, 2500, 7777, 15000, 33333] as $mb) {
            $memory = Suggestions::memory($mb * self::MIB, 0);
            $this->assertSame(0, $memory % Suggestions::memoryStep($memory), "memory {$mb}");

            $disk = Suggestions::disk($mb * self::MIB);
            $this->assertSame(0, $disk % Suggestions::diskStep($disk), "disk {$mb}");
        }
    }

    public function testStepsGrowWithTheSize(): void
    {
        $this->assertSame(64, Suggestions::memoryStep(512));
        $this->assertSame(256, Suggestions::memoryStep(2048));
        $this->assertSame(512, Suggestions::memoryStep(8192));
        $this->assertSame(1024, Suggestions::memoryStep(32768));
        $this->assertSame(128, Suggestions::diskStep(512));
        $this->assertSame(512, Suggestions::diskStep(5000));
        $this->assertSame(1024, Suggestions::diskStep(50000));
        $this->assertSame(5120, Suggestions::diskStep(200000));
    }

    public function testSuggestionsNeedADayOfHistory(): void
    {
        $now = 1_760_000_000;

        $this->assertFalse(Suggestions::hasHistory(null, $now));
        $this->assertFalse(Suggestions::hasHistory($now - 3600, $now));
        $this->assertFalse(Suggestions::hasHistory($now - 86_399, $now));
        $this->assertTrue(Suggestions::hasHistory($now - 86_400, $now));
        $this->assertTrue(Suggestions::hasHistory($now - 10 * 86_400, $now));
    }

    public function testSystemInfoFromWings(): void
    {
        $info = SystemInfo::fromWings([
            'version' => '1.12.1',
            'docker' => ['version' => '27.0'],
            'system' => ['architecture' => 'amd64', 'cpu_threads' => 12, 'memory_bytes' => 33_500_000_000, 'os' => 'Debian GNU/Linux 12'],
        ]);

        $this->assertSame(['memory_total' => 33_500_000_000, 'cpu_threads' => 12, 'os' => 'Debian GNU/Linux 12', 'version' => '1.12.1'], $info);
    }

    public function testSystemInfoFromTheOldShapeOrNonsense(): void
    {
        // v1 answer: no "system" block.
        $old = SystemInfo::fromWings(['architecture' => 'amd64', 'cpu_count' => 4, 'version' => '1.11.0']);
        $this->assertNull($old['memory_total']);
        $this->assertNull($old['cpu_threads']);

        $bad = SystemInfo::fromWings(['system' => ['memory_bytes' => '16', 'cpu_threads' => -2, 'os' => 12], 'version' => []]);
        $this->assertSame(['memory_total' => null, 'cpu_threads' => null, 'os' => null, 'version' => null], $bad);

        $this->assertNull(SystemInfo::fromWings([])['memory_total']);
        $this->assertNull(SystemInfo::fromWings(['system' => 'text'])['cpu_threads']);
    }
}
