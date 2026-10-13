<?php

namespace McVersion\Tests;

use McVersion\Support\Versions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VersionsTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function ids(): iterable
    {
        yield 'release' => ['1.21.4', true];
        yield 'two part' => ['1.21', true];
        yield 'yearly' => ['26.1.2', true];
        yield 'release candidate' => ['1.21.11-rc3', true];
        yield 'snapshot' => ['26.4-snapshot-4', true];
        yield 'pre release' => ['1.21.11-pre5', true];
        yield 'weekly snapshot' => ['24w14a', true];
        yield 'path traversal' => ['../1.21', false];
        yield 'slash' => ['1.21/4', false];
        yield 'shell' => ['1.21;rm', false];
        yield 'space' => ['1.21 4', false];
        yield 'joke version' => ['3D Shareware v1.34', false];
        yield 'latest' => ['latest', false];
        yield 'empty' => ['', false];
        yield 'newline end' => ["1.21\n", false];
        yield 'too long' => [str_repeat('1', 41), false];
    }

    #[DataProvider('ids')]
    public function test_valid_ids(string $id, bool $valid): void
    {
        $this->assertSame($valid, Versions::isValid($id));
    }

    public function test_releases_are_told_from_pre_releases(): void
    {
        $this->assertTrue(Versions::isRelease('1.21.4'));
        $this->assertTrue(Versions::isRelease('26.1'));
        $this->assertFalse(Versions::isRelease('1.21.11-rc3'));
        $this->assertFalse(Versions::isRelease('26.4-snapshot-4'));
        $this->assertFalse(Versions::isRelease('24w14a'));
    }

    /** @return iterable<string, array{string, string, ?int}> */
    public static function comparisons(): iterable
    {
        yield 'older minor' => ['1.20.6', '1.21', -1];
        yield 'newer patch' => ['1.21.4', '1.21.1', 1];
        yield 'equal' => ['1.21.4', '1.21.4', 0];
        yield 'missing patch is zero' => ['1.21', '1.21.0', 0];
        yield 'numeric not text' => ['1.9.4', '1.10', -1];
        yield 'two digit patch' => ['1.21.11', '1.21.9', 1];
        yield 'yearly is newer' => ['26.1', '1.21.11', 1];
        yield 'candidate before release' => ['1.21.11-rc3', '1.21.11', -1];
        yield 'candidate after previous release' => ['1.21.11-rc1', '1.21.10', 1];
        yield 'pre before candidate' => ['1.21.11-pre5', '1.21.11-rc1', -1];
        yield 'snapshot before pre' => ['26.4-snapshot-4', '26.4-pre-1', -1];
        yield 'snapshots by number' => ['26.4-snapshot-10', '26.4-snapshot-9', 1];
        yield 'snapshot after previous release' => ['26.4-snapshot-1', '26.3', 1];
        yield 'weekly by week' => ['24w14a', '24w09b', 1];
        yield 'weekly by letter' => ['24w14a', '24w14b', -1];
        yield 'weekly against numbered' => ['24w14a', '1.20.5', null];
        yield 'garbage' => ['latest', '1.21', null];
    }

    #[DataProvider('comparisons')]
    public function test_compare(string $a, string $b, ?int $expected): void
    {
        $this->assertSame($expected, Versions::compare($a, $b));
        if ($expected !== null) {
            $this->assertSame(-$expected, Versions::compare($b, $a));
        }
    }

    public function test_downgrade_detection(): void
    {
        $this->assertTrue(Versions::isDowngrade('1.21.4', '1.20.6'));
        $this->assertTrue(Versions::isDowngrade('26.1', '1.21.11'));
        $this->assertTrue(Versions::isDowngrade('1.21.11', '1.21.11-rc3'));
        $this->assertFalse(Versions::isDowngrade('1.16.5', '1.21.4'));
        $this->assertFalse(Versions::isDowngrade('1.21.4', '1.21.4'));
        $this->assertFalse(Versions::isDowngrade(null, '1.21.4'));
        $this->assertFalse(Versions::isDowngrade('24w14a', '1.20.4'), 'unknown order is not a downgrade');
    }

    public function test_base_of_a_pre_release(): void
    {
        $this->assertSame('1.21.11', Versions::base('1.21.11-rc3'));
        $this->assertSame('26.4.0', Versions::base('26.4-snapshot-4'));
        $this->assertNull(Versions::base('24w14a'));
    }

    public function test_sorting_newest_first(): void
    {
        $sorted = Versions::sortNewestFirst(['1.16.5', '26.1.2', '1.21.11-rc1', '1.21.11', '1.9.4', '1.21.10', '24w14a', '1.10']);
        $this->assertSame(['26.1.2', '1.21.11', '1.21.11-rc1', '1.21.10', '1.16.5', '1.10', '1.9.4', '24w14a'], $sorted);
    }

    /** @return iterable<string, array{string, list<int>, int}> */
    public static function java(): iterable
    {
        yield '1.8.8' => ['1.8.8', [11, 8], 8];
        yield '1.12.2' => ['1.12.2', [11, 8], 8];
        yield '1.16.5' => ['1.16.5', [11, 8], 8];
        yield '1.17' => ['1.17', [16], 16];
        yield '1.17.1' => ['1.17.1', [16], 16];
        yield '1.18' => ['1.18', [17], 17];
        yield '1.19.4' => ['1.19.4', [17], 17];
        yield '1.20' => ['1.20', [17], 17];
        yield '1.20.4' => ['1.20.4', [17], 17];
        yield '1.20.5' => ['1.20.5', [21], 21];
        yield '1.20.6' => ['1.20.6', [21], 21];
        yield '1.21' => ['1.21', [21], 21];
        yield '1.21.11' => ['1.21.11', [21], 21];
        yield '1.21.11 rc' => ['1.21.11-rc3', [21], 21];
        yield '1.20.5 pre' => ['1.20.5-pre1', [21], 21];
        yield '26.1' => ['26.1', [25], 25];
        yield '26.3 snapshot' => ['26.4-snapshot-4', [25], 25];
        yield '27.1' => ['27.1', [25], 25];
        yield 'weekly before 1.17' => ['20w45a', [11, 8], 8];
        yield 'weekly 1.17' => ['21w19a', [16], 16];
        yield 'weekly 1.18' => ['21w44a', [17], 17];
        yield 'weekly 1.20.5' => ['24w14a', [21], 21];
        yield 'weekly 1.21' => ['25w41a', [21], 21];
    }

    /**
     * @param  list<int>  $preferred
     */
    #[DataProvider('java')]
    public function test_java_requirement(string $version, array $preferred, int $minimum): void
    {
        $this->assertSame(['preferred' => $preferred, 'minimum' => $minimum], Versions::javaRequirement($version));
    }

    public function test_java_requirement_of_garbage_is_unknown(): void
    {
        $this->assertNull(Versions::javaRequirement('latest'));
        $this->assertNull(Versions::javaRequirement(''));
        $this->assertNull(Versions::javaRequirement('2.0'));
    }
}
