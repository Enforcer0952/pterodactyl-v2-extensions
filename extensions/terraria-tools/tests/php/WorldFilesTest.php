<?php

namespace TerrariaTools\Tests;

use PHPUnit\Framework\TestCase;
use TerrariaTools\Support\WorldFiles;

final class WorldFilesTest extends TestCase
{
    public function test_world_names(): void
    {
        $this->assertSame('MyWorld', WorldFiles::worldName('MyWorld.wld'));
        $this->assertSame('My World (2)', WorldFiles::worldName('My World (2).wld'));
        $this->assertNull(WorldFiles::worldName('MyWorld.wld.bak'));
        $this->assertNull(WorldFiles::worldName('MyWorld.twld'));
        $this->assertNull(WorldFiles::worldName('.wld'));
        $this->assertNull(WorldFiles::worldName('../x.wld'));
        $this->assertNull(WorldFiles::worldName('a/b.wld'));
        $this->assertNull(WorldFiles::worldName('notes.txt'));
    }

    public function test_companion_files(): void
    {
        $this->assertSame(['W.wld', 'W.wld.bak', 'W.twld'], WorldFiles::companions('W'));
    }

    public function test_backup_names_round_trip(): void
    {
        $file = WorldFiles::backupFile('My World', new \DateTimeImmutable('2026-10-13 14:30:05', new \DateTimeZone('UTC')));

        $this->assertSame('My World-20261013-143005.wld', $file);
        $this->assertSame(['world' => 'My World', 'stamp' => '20261013-143005'], WorldFiles::parseBackup($file));
    }

    public function test_a_world_name_that_looks_like_a_backup_still_parses_by_its_last_stamp(): void
    {
        $this->assertSame(
            ['world' => 'W-20200101-000000', 'stamp' => '20261013-143005'],
            WorldFiles::parseBackup('W-20200101-000000-20261013-143005.wld'),
        );
    }

    public function test_rejects_files_that_are_not_backups(): void
    {
        $this->assertNull(WorldFiles::parseBackup('MyWorld.wld'));
        $this->assertNull(WorldFiles::parseBackup('MyWorld-2026.wld'));
        $this->assertNull(WorldFiles::parseBackup('../W-20261013-143005.wld'));
        $this->assertNull(WorldFiles::parseBackup('a/W-20261013-143005.wld'));
        $this->assertNull(WorldFiles::parseBackup('W-20261013-143005.wld.bak'));
        $this->assertNull(WorldFiles::parseBackup("W\n-20261013-143005.wld"));
    }

    public function test_backup_time(): void
    {
        $this->assertSame('2026-10-13 14:30:05', WorldFiles::backupTime('20261013-143005', new \DateTimeZone('UTC'))?->format('Y-m-d H:i:s'));
        $this->assertNull(WorldFiles::backupTime('garbage'));
    }

    public function test_keeps_the_newest_backups_of_each_world(): void
    {
        $files = [
            'A-20261001-100000.wld', 'A-20261002-100000.wld', 'A-20261003-100000.wld', 'A-20261004-100000.wld',
            'B-20261001-100000.wld',
            'notes.txt', 'A.wld',
        ];

        $this->assertSame(['A-20261002-100000.wld', 'A-20261001-100000.wld'], WorldFiles::expired($files, 2));
        $this->assertSame([], WorldFiles::expired($files, 5));
        // Never delete everything: at least one is kept.
        $this->assertSame(['A-20261003-100000.wld', 'A-20261002-100000.wld', 'A-20261001-100000.wld'], WorldFiles::expired($files, 0));
    }

    public function test_config_path_follows_the_existing_style(): void
    {
        $this->assertSame(
            '/home/container/saves/Worlds/Second.wld',
            WorldFiles::configPath('/home/container/saves/Worlds/MyWorld.wld', '/home/container/saves/Worlds', '/saves/Worlds', 'Second'),
        );
        $this->assertSame(
            'saves/Worlds/Second.wld',
            WorldFiles::configPath('saves/Worlds/MyWorld.wld', null, '/saves/Worlds', 'Second'),
        );
        $this->assertSame(
            '/home/container/saves/Worlds/Second.wld',
            WorldFiles::configPath(null, '/home/container/saves/Worlds/', '/saves/Worlds', 'Second'),
        );
        $this->assertSame(
            '/home/container/saves/Worlds/Second.wld',
            WorldFiles::configPath('', null, '/saves/Worlds', 'Second'),
        );
    }

    public function test_twld_follows_the_wld_name(): void
    {
        $this->assertSame('W.twld', WorldFiles::twld('W.wld'));
        $this->assertSame('W-20261013-143005.twld', WorldFiles::twld('W-20261013-143005.wld'));
    }

    public function test_a_twld_file_is_not_a_backup_or_a_world(): void
    {
        $this->assertNull(WorldFiles::parseBackup('W-20261013-143005.twld'));
        $this->assertNull(WorldFiles::worldName('W.twld'));
    }

    public function test_active_names_cover_the_path_the_name_and_the_variable(): void
    {
        $this->assertSame(['A', 'B', 'C'], WorldFiles::activeNames('/home/container/saves/Worlds/A.wld', 'B', 'C'));
        $this->assertSame(['A'], WorldFiles::activeNames('saves\\Worlds\\A.wld', 'A', ' A '));
        $this->assertSame(['Var'], WorldFiles::activeNames(null, '', 'Var'));
        $this->assertSame([], WorldFiles::activeNames('', null, null));
    }
}
