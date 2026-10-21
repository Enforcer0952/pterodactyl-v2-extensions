<?php

namespace PalworldTools\Tests;

use PalworldTools\Support\ApplyPlan;
use PHPUnit\Framework\TestCase;

final class ApplyPlanTest extends TestCase
{
    public function testStartAfterMerges(): void
    {
        $this->assertTrue(ApplyPlan::startAfter(null, 'running'));
        $this->assertTrue(ApplyPlan::startAfter(true, 'stopping'));
        $this->assertFalse(ApplyPlan::startAfter(false, 'stopping'));
        $this->assertFalse(ApplyPlan::startAfter(null, 'stopping'));
    }

    public function testValidHash(): void
    {
        $this->assertTrue(ApplyPlan::validHash(sha1('a')));
        $this->assertFalse(ApplyPlan::validHash(''));
        $this->assertFalse(ApplyPlan::validHash('abc'));
        $this->assertFalse(ApplyPlan::validHash(sha1('a')."\n"));
    }

    public function testChangedWhileWaitingOnlyChecksWholeFileReplacements(): void
    {
        $this->assertTrue(ApplyPlan::changedWhileWaiting('text', 'a', 'b'));
        $this->assertFalse(ApplyPlan::changedWhileWaiting('text', 'a', 'a'));
        $this->assertFalse(ApplyPlan::changedWhileWaiting(null, 'a', 'b'));
        $this->assertFalse(ApplyPlan::changedWhileWaiting('text', null, 'b'));
    }

    public function testNormalizeEdit(): void
    {
        $this->assertSame(['key' => 'A', 'value' => ''], ApplyPlan::normalizeEdit(['key' => 'A', 'value' => null, 'clear' => true]));
        $this->assertSame(['key' => 'A', 'value' => null], ApplyPlan::normalizeEdit(['key' => 'A', 'value' => null]));
        $this->assertSame(['key' => 'A', 'value' => 'x'], ApplyPlan::normalizeEdit(['key' => 'A', 'value' => 'x', 'clear' => true]));
    }
}
