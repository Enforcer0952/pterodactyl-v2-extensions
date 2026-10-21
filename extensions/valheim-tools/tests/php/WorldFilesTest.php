<?php

use PHPUnit\Framework\TestCase;
use ValheimTools\Support\WorldFiles;

class WorldFilesTest extends TestCase
{
    private function file(string $name, int $size = 100, string $modified = '2026-10-01T10:00:00Z'): array
    {
        return ['name' => $name, 'file' => true, 'size' => $size, 'modified' => $modified];
    }

    private function dir(string $name, string $modified = '2026-10-01T10:00:00Z'): array
    {
        return ['name' => $name, 'file' => false, 'size' => 4096, 'modified' => $modified];
    }

    public function test_groups_pairs_folders_and_backups(): void
    {
        $groups = WorldFiles::group([
            $this->file('Dedicated.db', 500), $this->file('Dedicated.fwl', 20),
            $this->dir('Midgard'),
            $this->file('Old.db'), // missing .fwl
            $this->file('Dedicated_backup_auto-20261001120000.db', 400, '2026-10-01T12:00:00Z'), $this->file('Dedicated_backup_auto-20261001120000.fwl', 20, '2026-10-01T12:00:00Z'),
            $this->dir('Midgard_backup_auto-20261002060000', '2026-10-02T06:00:00Z'),
            $this->dir('Midgard_backup_20261003-101500', '2026-10-03T10:15:00Z'),
            $this->file('Legacy.db.old', 70), $this->file('Legacy.fwl.old', 5),
            $this->file('notes.txt'),
        ]);

        $this->assertSame(['Dedicated', 'Midgard', 'Old'], array_column($groups['worlds'], 'name'));
        $this->assertSame(520, $groups['worlds'][0]['size']);
        $this->assertSame('files', $groups['worlds'][0]['format']);
        $this->assertTrue($groups['worlds'][0]['complete']);
        $this->assertSame('folder', $groups['worlds'][1]['format']);
        $this->assertFalse($groups['worlds'][2]['complete']);

        $this->assertSame(
            ['Midgard_backup_20261003-101500', 'Midgard_backup_auto-20261002060000', 'Dedicated_backup_auto-20261001120000'],
            array_column($groups['backups'], 'id'),
        );
        $this->assertSame('Legacy', $groups['originals'][0]['world']);
        $this->assertSame(75, $groups['originals'][0]['size']);
    }

    public function test_backup_fields(): void
    {
        $groups = WorldFiles::group([$this->dir('My World_backup_auto-20261002060000')]);
        $backup = $groups['backups'][0];
        $this->assertSame('My World', $backup['world']);
        $this->assertTrue($backup['auto']);
        $this->assertSame('folder', $backup['format']);
        $this->assertSame([], $groups['worlds']);
    }

    public function test_unsafe_names_are_dropped(): void
    {
        $groups = WorldFiles::group([$this->file('.hidden.db'), $this->file("a\nb.db"), $this->dir('..'), $this->file('x/y.db')]);
        $this->assertSame([], $groups['worlds']);
    }

    public function test_stamp_time(): void
    {
        $this->assertSame('2026-10-02 06:00:00', WorldFiles::stampTime('auto-20261002060000')->format('Y-m-d H:i:s'));
        $this->assertNull(WorldFiles::stampTime('20261342060000'));
        $this->assertNull(WorldFiles::stampTime('garbage'));
    }

    public function test_backup_folder_round_trip(): void
    {
        $at = new DateTimeImmutable('2026-10-14 18:30:05', new DateTimeZone('UTC'));
        $name = WorldFiles::backupFolder('My World', $at);
        $this->assertSame('My World-20261014-183005', $name);
        $this->assertSame(['world' => 'My World', 'kind' => '', 'stamp' => '20261014-183005'], WorldFiles::parseBackupFolder($name));

        $safety = WorldFiles::backupFolder('Dedicated', $at, 'before-restore');
        $this->assertSame(['world' => 'Dedicated', 'kind' => 'before-restore', 'stamp' => '20261014-183005'], WorldFiles::parseBackupFolder($safety));
        $this->assertNull(WorldFiles::parseBackupFolder('../x-20261014-183005'));
        $this->assertNull(WorldFiles::parseBackupFolder('nodate'));
    }

    public function test_expired_keeps_newest_per_world_and_kind(): void
    {
        $folders = [
            'A-20260101-000001', 'A-20260102-000001', 'A-20260103-000001',
            'B-20260101-000001',
            'A-before-restore-20260101-000001', 'A-before-restore-20260102-000001',
            'junk',
        ];
        $expired = WorldFiles::expired($folders, 2);
        $this->assertSame(['A-20260101-000001'], $expired);
        $this->assertSame([], WorldFiles::expired($folders, 5));
        // Keeping zero still keeps one.
        $this->assertCount(3, WorldFiles::expired($folders, 0));
    }

    public function test_expired_never_lists_backups_of_a_protected_world(): void
    {
        $folders = [
            'A-20260101-000001', 'A-20260102-000001', 'A-20260103-000001',
            'A-before-delete-20260101-000001', 'A-before-delete-20260102-000001',
            'B-20260101-000001', 'B-20260102-000001', 'B-20260103-000001',
        ];

        $this->assertSame(['B-20260101-000001'], WorldFiles::expired($folders, 2, ['A']));
        $this->assertSame([], WorldFiles::expired($folders, 1, ['A', 'B']));
        // A similar name is not the protected world.
        $this->assertSame(['A-20260101-000001'], WorldFiles::expired(['A-20260101-000001', 'A-20260102-000001', 'AB-20260101-000001'], 1, ['AB']));
    }
}
