<?php

use PalworldTools\Support\BanList;
use PalworldTools\Support\Palworld;
use PalworldTools\Support\SaveFiles;
use PHPUnit\Framework\TestCase;

final class SaveFilesTest extends TestCase
{
    private const string WORLD = '0123456789ABCDEF0123456789ABCDEF';

    private function dir(string $name): array
    {
        return ['name' => $name, 'file' => false];
    }

    private function file(string $name): array
    {
        return ['name' => $name, 'file' => true];
    }

    public function testFindsWorldFolders(): void
    {
        $entries = [$this->dir(self::WORLD), $this->dir('ABC'), $this->file(str_repeat('A', 32)), $this->dir('../'.str_repeat('B', 29)), $this->dir(strtolower(self::WORLD)), $this->dir(self::WORLD.'0')];

        $this->assertSame([self::WORLD, strtolower(self::WORLD)], SaveFiles::worlds($entries));
        $this->assertSame([], SaveFiles::worlds([]));
    }

    public function testCountsPlayerFiles(): void
    {
        $id = str_repeat('A', 32);
        $entries = [$this->file($id.'.sav'), $this->file(str_repeat('B', 32).'.sav'), $this->file($id.'_dps.sav'), $this->dir(str_repeat('C', 32).'.sav'), $this->file('notes.txt')];

        $this->assertSame(2, SaveFiles::playerFiles($entries));
    }

    public function testGameBackupsAreListedNewestFirst(): void
    {
        $entries = [$this->dir('2026.10.12-08.30.00'), $this->dir('2026.10.13-21.05.09'), $this->dir('junk'), $this->file('2026.10.14-01.00.00'), $this->dir('2026.10.11-1.2.3')];

        $found = SaveFiles::gameBackups($entries);
        $this->assertSame(['2026.10.13-21.05.09', '2026.10.12-08.30.00'], array_column($found, 'name'));
        $this->assertSame('2026-10-13T21:05:09', $found[0]['at']);
        $this->assertTrue(SaveFiles::validGameBackup('2026.10.13-21.05.09'));
        foreach (['..', '../x', '2026.10.13-21.05.09/..', '', '2026.10.13-21.05.09 ', "2026.10.13-21.05.09\n"] as $bad) {
            $this->assertFalse(SaveFiles::validGameBackup($bad), $bad);
        }
    }

    public function testArchiveLeavesOutTheGameBackups(): void
    {
        $entries = [$this->file('Level.sav'), $this->file('LevelMeta.sav'), $this->dir('Players'), $this->dir('backup'), $this->file('old.tar.gz'), $this->file('.hidden')];

        $this->assertSame(['Level.sav', 'LevelMeta.sav', 'Players'], SaveFiles::archiveEntries($entries));
    }

    public function testBackupNames(): void
    {
        $at = new DateTimeImmutable('2026-10-14 09:08:07', new DateTimeZone('UTC'));
        $file = SaveFiles::backupFile(self::WORLD, $at);

        $this->assertSame(self::WORLD.'-20261014-090807.tar.gz', $file);
        $this->assertSame(['world' => self::WORLD, 'stamp' => '20261014-090807'], SaveFiles::parseBackup($file));
        foreach (['../'.$file, self::WORLD.'-20261014-090807.tar', 'x-20261014-090807.tar.gz', self::WORLD.'-2026-090807.tar.gz', $file."\n", 'palworld-backups/'.$file] as $bad) {
            $this->assertNull(SaveFiles::parseBackup($bad), $bad);
        }
    }

    public function testExpiredKeepsTheNewestOfEachWorld(): void
    {
        $other = str_repeat('F', 32);
        $files = [];
        foreach (['20261010-000000', '20261011-000000', '20261012-000000', '20261013-000000'] as $stamp) {
            $files[] = self::WORLD.'-'.$stamp.'.tar.gz';
        }
        $files[] = $other.'-20261001-000000.tar.gz';
        $files[] = 'readme.txt';

        $old = SaveFiles::expired($files, 2);
        sort($old);
        $this->assertSame([self::WORLD.'-20261010-000000.tar.gz', self::WORLD.'-20261011-000000.tar.gz'], $old);
        $this->assertCount(3, SaveFiles::expired($files, 1));
        $this->assertSame(3, count(SaveFiles::expired($files, 0)), 'at least one backup always stays');
    }

    public function testReplacedFolders(): void
    {
        $names = ['20261001-000000-'.self::WORLD, '20261003-000000-'.self::WORLD, '20261002-000000-'.self::WORLD, 'other', '..'];

        $this->assertSame(['20261001-000000-'.self::WORLD], SaveFiles::expiredReplaced($names, 2));
        $this->assertSame('20261014-090807-'.self::WORLD, SaveFiles::replacedName(self::WORLD, new DateTimeImmutable('2026-10-14 09:08:07')));
    }

    public function testActiveWorld(): void
    {
        $this->assertSame(self::WORLD, SaveFiles::activeWorld("[/Script/Pal.PocketWorldSettings]\nDedicatedServerName=".self::WORLD."\r\n"));
        $this->assertNull(SaveFiles::activeWorld("DedicatedServerName=../etc\n"));
        $this->assertNull(SaveFiles::activeWorld(null));
    }

    public function testBanList(): void
    {
        $text = "steam_00000000000000001\r\n# note\r\n\r\nsteam_00000000000000002\r\nsteam_00000000000000001\r\nbad id\r\n";

        $this->assertSame(['steam_00000000000000001', 'steam_00000000000000002'], BanList::ids($text));
        $this->assertSame("# note\r\n\r\nsteam_00000000000000002\r\nbad id\r\n", BanList::remove($text, 'steam_00000000000000001'));
        $this->assertSame("a\n", BanList::remove("a\nsteam_1", 'steam_1'));
        $this->assertSame('', BanList::remove('', 'steam_1'));
        $this->assertSame([], BanList::ids(''));
    }

    public function testPalworldDetectionAndValidators(): void
    {
        $this->assertTrue(Palworld::matches(['Palworld'], '', null));
        $this->assertTrue(Palworld::matches(['steamcmd'], './PalServer.sh -port=1', null));
        $this->assertTrue(Palworld::matches([], './Pal/Binaries/Linux/PalServer-Linux-Shipping Pal', null));
        $this->assertTrue(Palworld::matches([], 'x', '2394010'));
        $this->assertFalse(Palworld::matches(['ark', 'steamcmd'], './ShooterGameServer', '376030'));

        foreach (['steam_76561198000000000', 'xbox_123', 'mac_abc.def'] as $id) {
            $this->assertTrue(Palworld::validUserId($id), $id);
        }
        foreach (['', 'a b', "steam_1\n", 'steam_1;rm', '../x', 'a/b', str_repeat('a', 65), null] as $id) {
            $this->assertFalse(Palworld::validUserId($id), (string) $id);
        }
        $this->assertTrue(Palworld::validWorld(self::WORLD));
        foreach (['..', self::WORLD."\n", self::WORLD.'/x', 'abc', null] as $id) {
            $this->assertFalse(Palworld::validWorld($id), (string) $id);
        }
        $this->assertSame('a b', Palworld::text("a\r\n\tb"));
        $this->assertNull(Palworld::text("  \n "));
        $this->assertSame('abc', Palworld::text('abcdef', 3));
    }
}
