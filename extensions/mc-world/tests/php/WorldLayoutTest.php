<?php

use McWorld\Support\WorldLayout;
use PHPUnit\Framework\TestCase;

final class WorldLayoutTest extends TestCase
{
    private static function dir(string $name, bool $symlink = false): array
    {
        return ['name' => $name, 'file' => false, 'symlink' => $symlink, 'size' => 4096, 'modified' => '2026-10-13T08:00:00Z'];
    }

    private static function file(string $name): array
    {
        return ['name' => $name, 'file' => true, 'symlink' => false, 'size' => 120, 'modified' => '2026-10-13T08:00:00Z'];
    }

    /** A Paper server: three sibling folders. */
    private function paperRoot(): array
    {
        return [
            self::dir('plugins'), self::dir('logs'), self::dir('cache'), self::dir('world'), self::dir('world_nether'), self::dir('world_the_end'),
            self::dir('world-backups'), self::dir('.paper-remap'), self::file('server.properties'), self::file('paper.jar'),
        ];
    }

    public function testPaperLayoutGroupsTheThreeFoldersIntoOneWorld(): void
    {
        $root = $this->paperRoot();
        $candidates = WorldLayout::candidates($root, 'world');
        $this->assertSame(['world'], $candidates);

        $worlds = WorldLayout::detect($root, ['world' => '2026-10-13T09:00:00Z'], 'world');
        $this->assertCount(1, $worlds);
        $this->assertSame('world', $worlds[0]['name']);
        $this->assertTrue($worlds[0]['active']);
        $this->assertSame(['world', 'world_nether', 'world_the_end'], $worlds[0]['folders']);
        $this->assertSame('split', $worlds[0]['layout']);
        $this->assertSame('2026-10-13T09:00:00Z', $worlds[0]['modified']);
    }

    public function testDimensionFoldersWithTheirOwnLevelDatAreNotListedAsWorlds(): void
    {
        $worlds = WorldLayout::detect($this->paperRoot(), ['world' => null, 'world_nether' => null, 'world_the_end' => null], 'world');
        $this->assertSame(['world'], array_column($worlds, 'name'));
    }

    public function testVanillaLayoutKeepsDimensionsInsideTheWorld(): void
    {
        $root = [self::dir('world'), self::dir('libraries'), self::dir('versions'), self::dir('logs'), self::file('server.properties'), self::file('server.jar')];
        $this->assertSame(['world'], WorldLayout::candidates($root, 'world'));
        $worlds = WorldLayout::detect($root, ['world' => '2026-10-13T09:00:00Z'], 'world');
        $this->assertSame(['world'], $worlds[0]['folders']);
        $this->assertSame('single', $worlds[0]['layout']);
    }

    public function testOnlyFoldersWithALevelDatCount(): void
    {
        $root = [self::dir('world'), self::dir('creative'), self::dir('stuff')];
        $worlds = WorldLayout::detect($root, ['world' => null, 'creative' => '2026-10-01T00:00:00Z'], 'creative');
        $this->assertSame(['creative', 'world'], array_column($worlds, 'name'));
        $this->assertTrue($worlds[0]['active']);
        $this->assertFalse($worlds[1]['active']);
    }

    public function testSecondWorldWithItsOwnNetherAndOldCopies(): void
    {
        $root = [self::dir('world'), self::dir('world_nether'), self::dir('hardcore'), self::dir('hardcore_nether'), self::dir('hardcore_the_end'), self::dir('world.old-20261001-120000')];
        $worlds = WorldLayout::detect($root, ['world' => null, 'hardcore' => null, 'world.old-20261001-120000' => null], 'hardcore');
        $this->assertSame(['hardcore', 'world', 'world.old-20261001-120000'], array_column($worlds, 'name'));
        $this->assertSame(['hardcore', 'hardcore_nether', 'hardcore_the_end'], $worlds[0]['folders']);
        $this->assertSame(['world', 'world_nether'], $worlds[1]['folders']);
    }

    public function testActiveWorldIsProbedFirstAndProbesAreCapped(): void
    {
        $root = [];
        for ($i = 0; $i < 60; $i++) {
            $root[] = self::dir(sprintf('folder%02d', $i));
        }
        $root[] = self::dir('zzz-active');
        $candidates = WorldLayout::candidates($root, 'zzz-active');
        $this->assertCount(WorldLayout::MAX_PROBES, $candidates);
        $this->assertSame('zzz-active', $candidates[0]);
    }

    public function testSymlinksProtectedAndHiddenFoldersAreSkipped(): void
    {
        $root = [self::dir('linked', true), self::dir('plugins'), self::dir('.hidden'), self::dir('world-backups'), self::dir('Plugins'), self::file('world')];
        $this->assertSame([], WorldLayout::candidates($root, 'world'));
        $this->assertSame([], WorldLayout::detect($root, ['linked' => null, 'plugins' => null, '.hidden' => null, 'world' => null], 'world'));
    }

    public function testFoldersWithBadNamesNeverBecomWorlds(): void
    {
        $root = [self::dir('a b'), self::dir('x;rm'), self::dir('..'), self::dir('a..b')];
        $worlds = WorldLayout::detect($root, ['a b' => null, 'x;rm' => null, '..' => null, 'a..b' => null], 'a b');
        $this->assertSame(['a b'], array_column($worlds, 'name'));
    }
}
