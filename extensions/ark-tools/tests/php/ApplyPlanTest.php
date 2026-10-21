<?php

namespace ArkTools\Tests;

use ArkTools\Support\ApplyPlan;
use PHPUnit\Framework\TestCase;

final class ApplyPlanTest extends TestCase
{
    public function testStartAfterMerges(): void
    {
        $this->assertTrue(ApplyPlan::startAfter(null, 'running'));
        $this->assertTrue(ApplyPlan::startAfter(true, 'stopping'));
        $this->assertTrue(ApplyPlan::startAfter(true, 'running'));
        $this->assertFalse(ApplyPlan::startAfter(false, 'stopping'));
        $this->assertFalse(ApplyPlan::startAfter(null, 'stopping'));
    }

    public function testValidHashes(): void
    {
        $hash = sha1('a');
        $this->assertTrue(ApplyPlan::validHashes(['Game.ini' => $hash]));
        $this->assertFalse(ApplyPlan::validHashes([]));
        $this->assertFalse(ApplyPlan::validHashes(['Game.ini' => '']));
        $this->assertFalse(ApplyPlan::validHashes(['Game.ini' => 'xyz']));
        $this->assertFalse(ApplyPlan::validHashes(['Game.ini' => $hash."\n"]));
        $this->assertFalse(ApplyPlan::validHashes([$hash]));
    }

    public function testChangedFiles(): void
    {
        $a = sha1('a');
        $b = sha1('b');
        $this->assertSame([], ApplyPlan::changedFiles(['Game.ini' => $a], ['Game.ini' => $a]));
        $this->assertSame(['Game.ini'], ApplyPlan::changedFiles(['Game.ini' => $a], ['Game.ini' => $b]));
        $this->assertSame([], ApplyPlan::changedFiles([], ['Game.ini' => $b]));
        $this->assertSame([], ApplyPlan::changedFiles(['Other.ini' => $a], ['Game.ini' => $b]));
    }

    public function testGuardedKeepsReplacedFilesOnly(): void
    {
        $hashes = ['Game.ini' => 'a', 'GameUserSettings.ini' => 'b'];
        $this->assertSame([], ApplyPlan::guarded($hashes, [], ['GameUserSettings.ini']));
        $this->assertSame(['Game.ini' => 'a'], ApplyPlan::guarded($hashes, ['Game.ini', 'GameUserSettings.ini'], ['GameUserSettings.ini']));
    }
}
