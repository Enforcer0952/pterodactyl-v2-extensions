<?php

namespace McVersion\Tests;

use McVersion\Support\Detection;
use PHPUnit\Framework\TestCase;

final class DetectionTest extends TestCase
{
    private static function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/fixtures/'.$name);
    }

    public function test_old_paper_from_its_log(): void
    {
        $result = Detection::analyse([
            'root' => ['server.jar', 'server.properties', 'eula.txt', 'paper.yml', 'version_history.json'],
            'log' => self::fixture('logs/paper-1.16.5.log'),
        ]);

        $this->assertSame(['software' => 'paper', 'version' => '1.16.5', 'build' => '794', 'source' => 'log'], $result);
    }

    public function test_new_paper_log_without_mc_marker(): void
    {
        $result = Detection::analyse(['root' => ['server.properties', 'eula.txt'], 'log' => self::fixture('logs/paper-1.21.4.log')]);

        $this->assertSame('paper', $result['software']);
        $this->assertSame('1.21.4', $result['version']);
        $this->assertSame('232', $result['build']);
    }

    public function test_purpur_log(): void
    {
        $result = Detection::analyse(['root' => ['purpur.yml'], 'log' => self::fixture('logs/purpur-1.21.1.log')]);

        $this->assertSame('purpur', $result['software']);
        $this->assertSame('1.21.1', $result['version']);
    }

    public function test_fabric_log_wins_over_leftover_paper_files(): void
    {
        $result = Detection::analyse([
            'root' => ['.fabric', 'version_history.json', 'paper.yml', 'server.properties', 'eula.txt'],
            'log' => self::fixture('logs/fabric-1.21.1.log'),
            'history' => self::fixture('version_history-paper.json'),
        ]);

        $this->assertSame('fabric', $result['software']);
        $this->assertSame('1.21.1', $result['version'], 'the log is read before version_history.json');
        $this->assertSame('log', $result['source']);
    }

    public function test_paper_log_wins_over_leftover_fabric_folder(): void
    {
        $result = Detection::analyse([
            'root' => ['.fabric', 'mods', 'server.properties', 'eula.txt'],
            'log' => self::fixture('logs/paper-1.21.4.log'),
        ]);

        $this->assertSame('paper', $result['software']);
    }

    public function test_vanilla_jar_after_a_paper_server_is_vanilla(): void
    {
        $result = Detection::analyse([
            'root' => ['version_history.json', 'paper.yml', 'spigot.yml', 'server.properties', 'eula.txt'],
            'log' => self::fixture('logs/vanilla-1.20.4.log'),
            'history' => self::fixture('version_history-paper.json'),
        ]);

        $this->assertSame('vanilla', $result['software']);
        $this->assertSame('1.20.4', $result['version']);
    }

    public function test_unknown_fork_falls_back_to_the_files(): void
    {
        $result = Detection::analyse(['root' => ['paper-global.yml', 'server.properties', 'eula.txt'], 'config' => ['paper-global.yml'], 'log' => self::fixture('logs/unknown-fork.log')]);

        $this->assertSame('paper', $result['software']);
        $this->assertSame('1.21.4', $result['version']);
    }

    public function test_history_when_there_is_no_log(): void
    {
        $result = Detection::analyse(['root' => ['version_history.json'], 'history' => self::fixture('version_history-paper.json')]);

        $this->assertSame(['software' => 'paper', 'version' => '1.16.5', 'build' => '794', 'source' => 'history'], $result);
    }

    public function test_newest_modified_version_folder_when_nothing_else_tells(): void
    {
        $result = Detection::analyse([
            'root' => ['server.properties', 'eula.txt', 'versions', 'libraries'],
            'versions' => [['name' => '1.21.10', 'modified' => 1000], ['name' => '1.21.1', 'modified' => 2000]],
        ]);

        $this->assertSame('1.21.1', $result['version'], 'written last, not highest');
        $this->assertSame('folder', $result['source']);
        $this->assertSame('vanilla', $result['software']);
    }

    public function test_version_folder_ties_go_to_the_higher_version(): void
    {
        $this->assertSame('1.21.10', Detection::fromFolder([['name' => '1.21.1', 'modified' => 5], ['name' => '1.21.10', 'modified' => 5]]));
    }

    public function test_version_folder_ignores_other_names(): void
    {
        $this->assertNull(Detection::fromFolder([['name' => 'cache', 'modified' => 5], ['name' => 'README', 'modified' => 9]]));
        $this->assertSame('1.20.6', Detection::fromFolder([['name' => '1.20.6-R0.1-SNAPSHOT', 'modified' => 5]]));
    }

    public function test_nothing_known(): void
    {
        $this->assertSame(['software' => null, 'version' => null, 'build' => null, 'source' => null], Detection::analyse([]));
        $this->assertSame('vanilla', Detection::analyse(['root' => ['server.properties', 'eula.txt'], 'log' => self::fixture('logs/vanilla-1.20.4.log')])['software']);
    }

    /** @return iterable<string, array{list<string>, list<string>, list<string>, ?string}> */
    public static function files(): iterable
    {
        yield 'fabric' => [['.fabric', 'mods'], [], [], 'fabric'];
        yield 'fabric launcher' => [['fabric-server-launch.jar'], [], [], 'fabric'];
        yield 'quilt' => [['.quilt'], [], [], 'quilt'];
        yield 'neoforge' => [['libraries'], ['neoforged'], [], 'neoforge'];
        yield 'forge' => [['libraries'], ['minecraftforge'], [], 'forge'];
        yield 'purpur beats paper' => [['purpur.yml', 'paper.yml'], [], [], 'purpur'];
        yield 'paper config' => [['config'], [], ['paper-global.yml'], 'paper'];
        yield 'paper history' => [['version_history.json'], [], [], 'paper'];
        yield 'spigot' => [['spigot.yml', 'bukkit.yml'], [], [], 'spigot'];
        yield 'bukkit' => [['bukkit.yml'], [], [], 'bukkit'];
        yield 'case' => [['PURPUR.YML'], [], [], 'purpur'];
        yield 'nothing' => [['server.properties'], [], [], null];
    }

    /**
     * @param  list<string>  $root
     * @param  list<string>  $libraries
     * @param  list<string>  $config
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('files')]
    public function test_software_from_files(array $root, array $libraries, array $config, ?string $expected): void
    {
        $this->assertSame($expected, Detection::fromFiles($root, $libraries, $config));
    }

    public function test_history_in_odd_shapes(): void
    {
        $this->assertSame([], Detection::fromHistory(null));
        $this->assertSame([], Detection::fromHistory('not json'));
        $this->assertSame([], Detection::fromHistory('{"currentVersion":5}'));
        $this->assertSame(['version' => '1.21.1', 'software' => 'purpur', 'build' => '2329'], Detection::fromHistory('{"currentVersion":"git-Purpur-2329 (MC: 1.21.1)"}'));
    }

    public function test_log_in_odd_shapes(): void
    {
        $this->assertSame([], Detection::fromLog(''));
        $this->assertSame([], Detection::fromLog("garbage\n\x00\xff"));
        $this->assertSame(['version' => '1.7.10'], Detection::fromLog('Starting minecraft server version 1.7.10'));
    }
}
