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
        yield 'tiny (floor)' => [10, 512];
        yield 'small (floor)' => [100, 512];
        yield 'just over the floor' => [420, 576];
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
        yield 'tiny (floor)' => [10, 1024];
        yield 'half a gigabyte (floor)' => [500, 1024];
        yield 'one gigabyte' => [1024, 1536];
        yield 'five gigabytes' => [5000, 7680];
        yield 'fifty gigabytes' => [50000, 75776];
        yield 'two hundred gigabytes' => [200000, 302080];
    }

    #[DataProvider('disk')]
    public function testDiskAddsHalfAndRoundsUp(int $usedMb, int $expected): void
    {
        $this->assertSame($expected, Suggestions::disk($usedMb * self::MIB));
    }

    public function testNeverBelowUsageNorLessThanTheHeadroom(): void
    {
        for ($mb = 1; $mb < 300_000; $mb = (int) ($mb * 1.07) + 1) {
            $bytes = $mb * self::MIB + 12345;

            $memory = Suggestions::memory($bytes, 0);
            $disk = Suggestions::disk($bytes);

            $this->assertGreaterThanOrEqual($bytes * 1.25, $memory * self::MIB, "memory {$mb} MB");
            $this->assertGreaterThanOrEqual(512, $memory);
            $this->assertGreaterThanOrEqual($bytes * 1.5, $disk * self::MIB, "disk {$mb} MB");
            $this->assertGreaterThanOrEqual(1024, $disk);
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

    public function testSuggestionsNeedThreeDaysAndSixRunningHours(): void
    {
        $now = 1_760_000_000;
        $day = 86_400;

        $this->assertFalse(Suggestions::hasHistory(null, 10_000, $now));
        $this->assertFalse(Suggestions::hasHistory($now - $day, 10_000, $now), 'one day is not enough');
        $this->assertFalse(Suggestions::hasHistory($now - 3 * $day + 1, 10_000, $now));
        $this->assertTrue(Suggestions::hasHistory($now - 3 * $day, 360, $now));
        $this->assertFalse(Suggestions::hasHistory($now - 7 * $day, 359, $now), 'a server that was off most of the time');
        $this->assertTrue(Suggestions::hasHistory($now - 7 * $day, 10_000, $now));
    }

    public function testOnlyTheExactSuggestionIsConfirmed(): void
    {
        $this->assertTrue(Suggestions::confirms(1024, 1024 * self::MIB));
        $this->assertFalse(Suggestions::confirms(1024, 1024 * self::MIB + 1), 'a byte off');
        $this->assertFalse(Suggestions::confirms(1024, 2048 * self::MIB), 'a different value');
        $this->assertFalse(Suggestions::confirms(null, 1024 * self::MIB), 'no suggestion any more');
        $this->assertFalse(Suggestions::confirms(1024, '1073741824'), 'not a number');
        $this->assertFalse(Suggestions::confirms(1024, null));
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
