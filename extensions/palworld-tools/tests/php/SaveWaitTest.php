<?php

namespace PalworldTools\Tests;

use PalworldTools\Support\SaveWait;
use PHPUnit\Framework\TestCase;

final class SaveWaitTest extends TestCase
{
    public function testReturnsWhenTheTimeChanges(): void
    {
        $times = ['t1', 't1', 't2'];
        $pauses = 0;
        $result = SaveWait::changed('t1', function () use (&$times) { return array_shift($times); }, function () use (&$pauses) { $pauses++; }, 10);

        $this->assertTrue($result);
        $this->assertSame(3, $pauses);
    }

    public function testGivesUpAfterTheLastTry(): void
    {
        $pauses = 0;
        $result = SaveWait::changed('t1', fn () => 't1', function () use (&$pauses) { $pauses++; }, 4);

        $this->assertFalse($result);
        $this->assertSame(4, $pauses);
    }

    public function testMissingFileCountsAsNoChange(): void
    {
        $this->assertFalse(SaveWait::changed(null, fn () => null, fn () => null, 2));
        $this->assertTrue(SaveWait::changed(null, fn () => 't1', fn () => null, 2));
    }
}
