<?php

use McWorld\Support\ResetPlan;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ResetPlanTest extends TestCase
{
    private const FOLDERS = ['world', 'world_nether', 'world_the_end'];

    public function testFullPlanRunsBackupThenDeleteThenSeedThenStart(): void
    {
        $steps = ResetPlan::build('world', self::FOLDERS, '12345', true, true, '20261013-120000');
        $this->assertSame(['backup', 'delete', 'seed', 'start'], array_column($steps, 'type'));
        $this->assertSame('world-backups/world-20261013-120000.tar.gz', $steps[0]['archive']);
        $this->assertSame(self::FOLDERS, $steps[0]['folders']);
        $this->assertSame(self::FOLDERS, $steps[1]['folders']);
        $this->assertSame('12345', $steps[2]['value']);
    }

    public function testNoBackupAndNoStartAreLeftOut(): void
    {
        $steps = ResetPlan::build('world', ['world'], null, false, false, '20261013-120000');
        $this->assertSame(['delete', 'seed'], array_column($steps, 'type'));
        $this->assertSame('', $steps[1]['value']);
    }

    public function testBackupIsNeverAfterDelete(): void
    {
        foreach ([true, false] as $backup) {
            foreach ([true, false] as $start) {
                $types = array_column(ResetPlan::build('world', self::FOLDERS, null, $backup, $start, '20261013-120000'), 'type');
                if ($backup) {
                    $this->assertLessThan(array_search('delete', $types, true), array_search('backup', $types, true));
                }
                $this->assertLessThan(array_search('seed', $types, true), array_search('delete', $types, true));
                if ($start) {
                    $this->assertSame('start', end($types));
                } else {
                    $this->assertNotContains('start', $types);
                }
            }
        }
    }

    #[DataProvider('badInput')]
    public function testRefusesFoldersThatAreNotThisWorlds(string $world, array $folders, string $stamp): void
    {
        $this->expectException(InvalidArgumentException::class);
        ResetPlan::build($world, $folders, null, true, false, $stamp);
    }

    public static function badInput(): array
    {
        return [
            'other folder' => ['world', ['world', 'plugins'], '20261013-120000'],
            'parent path' => ['world', ['world', '../world_nether'], '20261013-120000'],
            'world not first' => ['world', ['world_nether', 'world'], '20261013-120000'],
            'duplicates' => ['world', ['world', 'world'], '20261013-120000'],
            'empty' => ['world', [], '20261013-120000'],
            'traversal name' => ['../etc', ['../etc'], '20261013-120000'],
            'protected name' => ['plugins', ['plugins'], '20261013-120000'],
            'bad stamp' => ['world', ['world'], '2026; rm'],
            'another worlds nether' => ['world', ['world', 'other_nether'], '20261013-120000'],
        ];
    }
}
