<?php

use McWorld\Support\MinecraftServer;
use McWorld\Support\Names;
use PHPUnit\Framework\TestCase;

final class NamesTest extends TestCase
{
    public function testAcceptsOrdinaryWorldNames(): void
    {
        foreach (['world', 'Hardcore 3', 'survival_2026', 'my-world.v2', 'wereld één', 'world.old-20261013-120000', '7days'] as $name) {
            $this->assertTrue(Names::world($name), $name);
        }
    }

    public function testRejectsPathsAndOddNames(): void
    {
        $bad = ['', '.', '..', '../world', 'a/b', 'a\\b', '/etc', 'world ', 'world.', ' world', '.hidden', 'a..b', "a\nb", "a\0b", 'a;b', 'a$(x)', 'a`b', 'a"b', "a'b", 'a*b', 'a|b', 'a&b', 'plugins', 'LOGS', 'world-backups', str_repeat('a', 101)];
        foreach ($bad as $name) {
            $this->assertFalse(Names::world($name), json_encode($name));
        }
    }

    public function testBackupFileNamesRoundTrip(): void
    {
        $file = Names::backupFile('Hardcore 3', '20261013-120000');
        $this->assertSame('Hardcore 3-20261013-120000.tar.gz', $file);
        $this->assertSame(['world' => 'Hardcore 3', 'stamp' => '20261013-120000'], Names::parseBackup($file));
        $this->assertSame(['world' => 'a-b', 'stamp' => '20261013-120000'], Names::parseBackup('a-b-20261013-120000.tar.gz'));
    }

    public function testRejectsForeignAndMaliciousBackupNames(): void
    {
        foreach (['archive-2026-10-13T120000Z.tar.gz', 'world-20261013-120000.zip', '../world-20261013-120000.tar.gz', 'world-20261013-120000.tar.gz/../x', "world-20261013-120000.tar.gz\n", 'plugins-20261013-120000.tar.gz', '-20261013-120000.tar.gz', 'world-2026-120000.tar.gz'] as $file) {
            $this->assertNull(Names::parseBackup($file), json_encode($file));
        }
    }

    public function testDimensionFolders(): void
    {
        $this->assertSame('world', Names::dimensionOf('world_nether'));
        $this->assertSame('a b', Names::dimensionOf('a b_the_end'));
        $this->assertNull(Names::dimensionOf('world'));
        $this->assertNull(Names::dimensionOf('_nether'));
        $this->assertSame('world.old-20261013-120000', Names::old('world', '20261013-120000'));
    }

    public function testSeedIsOneCleanLine(): void
    {
        $this->assertNull(Names::seed(null));
        $this->assertNull(Names::seed('   '));
        $this->assertSame('-4172144997902289642', Names::seed(' -4172144997902289642 '));
        $this->assertSame('glacier', Names::seed('glacier'));
        foreach (["a\nop-level=4", "a\0b", "a\u{2028}b", str_repeat('1', 101)] as $bad) {
            try {
                Names::seed($bad);
                $this->fail('accepted '.json_encode($bad));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testEggGating(): void
    {
        $this->assertTrue(MinecraftServer::matches(['minecraft'], []));
        $this->assertTrue(MinecraftServer::matches([], ['eula']));
        $this->assertTrue(MinecraftServer::matches(['Minecraft', 'Paper'], ['EULA'], 'Paper'));
        $this->assertFalse(MinecraftServer::matches(['minecraft', 'bedrock'], ['eula']));
        $this->assertFalse(MinecraftServer::matches(['minecraft', 'proxy'], ['eula']));
        $this->assertFalse(MinecraftServer::matches([], ['eula'], 'Velocity'));
        $this->assertFalse(MinecraftServer::matches([], ['eula'], 'Bungeecord'));
        $this->assertFalse(MinecraftServer::matches(['ark'], []));
        $this->assertFalse(MinecraftServer::matches([], []));
    }
}
