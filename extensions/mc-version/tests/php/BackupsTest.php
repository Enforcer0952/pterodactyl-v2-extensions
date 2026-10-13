<?php

namespace McVersion\Tests;

use McVersion\Support\Backups;
use PHPUnit\Framework\TestCase;

final class BackupsTest extends TestCase
{
    public function test_names_round_trip(): void
    {
        $name = Backups::name('server.jar', '20261013153000');

        $this->assertSame('server.jar.bak-20261013153000', $name);
        $this->assertSame('20261013153000', Backups::stamp('server.jar', $name));
        $this->assertSame('server.jar.bak-20261013153000.json', Backups::metaName($name));
    }

    public function test_only_exact_copies_of_this_jar_count(): void
    {
        $this->assertNull(Backups::stamp('server.jar', 'server.jar.bak-20261013153000.json'));
        $this->assertNull(Backups::stamp('server.jar', 'other.jar.bak-20261013153000'));
        $this->assertNull(Backups::stamp('server.jar', 'server.jar.bak-2026'));
        $this->assertNull(Backups::stamp('server.jar', 'server.jar.bak-20261013153000x'));
        $this->assertNull(Backups::stamp('server.jar', "server.jar.bak-20261013153000\n"));
        $this->assertNull(Backups::stamp('server.jar', 'server.jar'));
    }

    public function test_copies_are_listed_newest_first(): void
    {
        $found = Backups::find('server.jar', [
            'server.jar', 'server.jar.bak-20261001000000', 'world', 'server.jar.bak-20261013153000', 'server.jar.bak-20261013153000.json', 'server.jar.bak-20260901000000',
        ]);

        $this->assertSame(['server.jar.bak-20261013153000', 'server.jar.bak-20261001000000', 'server.jar.bak-20260901000000'], array_column($found, 'name'));
    }

    public function test_prune_keeps_two_and_their_json(): void
    {
        $names = [
            'server.jar',
            'server.jar.bak-20261013153000', 'server.jar.bak-20261013153000.json',
            'server.jar.bak-20261001000000', 'server.jar.bak-20261001000000.json',
            'server.jar.bak-20260901000000', 'server.jar.bak-20260901000000.json',
            'server.jar.bak-20260801000000',
            'server.jar.bak-20260101000000.json', // copy gone, json left behind
            'notes.json',
        ];

        $delete = Backups::prune('server.jar', $names);
        sort($delete);

        $this->assertSame([
            'server.jar.bak-20260101000000.json',
            'server.jar.bak-20260801000000',
            'server.jar.bak-20260901000000',
            'server.jar.bak-20260901000000.json',
        ], $delete);
    }

    public function test_prune_with_few_copies_deletes_nothing(): void
    {
        $this->assertSame([], Backups::prune('server.jar', ['server.jar', 'server.jar.bak-20261013153000', 'server.jar.bak-20261013153000.json']));
    }

    public function test_fresh_stamp_avoids_a_taken_second(): void
    {
        $now = gmmktime(15, 30, 0, 10, 13, 2026);

        $this->assertSame('20261013153000', Backups::freshStamp('server.jar', [], $now));
        $this->assertSame('20261013153001', Backups::freshStamp('server.jar', ['server.jar.bak-20261013153000'], $now));
        $this->assertSame('20261013153002', Backups::freshStamp('server.jar', ['server.jar.bak-20261013153000', 'server.jar.bak-20261013153001'], $now));
    }

    public function test_stamp_to_time(): void
    {
        $this->assertSame('2026-10-13T15:30:00+00:00', Backups::time('20261013153000'));
        $this->assertNull(Backups::time('nonsense'));
    }

    public function test_meta_round_trip(): void
    {
        $text = Backups::writeMeta(
            ['software' => 'paper', 'version' => '1.21.4', 'variables' => ['MINECRAFT_VERSION' => '1.21.4', 'BUILD_NUMBER' => 'latest'], 'image' => 'ghcr.io/pterodactyl/yolks:java_21'],
            ['software' => 'fabric', 'version' => '1.21.1', 'build' => '0.19.5'],
            1792000000,
        );

        $this->assertJson($text);
        $meta = Backups::readMeta($text);
        $this->assertSame('paper', $meta['software']);
        $this->assertSame('1.21.4', $meta['version']);
        $this->assertSame(['MINECRAFT_VERSION' => '1.21.4', 'BUILD_NUMBER' => 'latest'], $meta['variables']);
        $this->assertSame('ghcr.io/pterodactyl/yolks:java_21', $meta['image']);
        $this->assertSame(['software' => 'fabric', 'version' => '1.21.1', 'build' => '0.19.5'], $meta['to']);
        $this->assertSame(1792000000, $meta['at']);
    }

    public function test_meta_without_variables_is_still_an_object(): void
    {
        $text = Backups::writeMeta(['software' => null, 'version' => null, 'variables' => [], 'image' => null], ['software' => 'paper', 'version' => '1.21.4', 'build' => null], 5);

        $this->assertStringContainsString('"variables": {}', $text);
    }

    public function test_meta_is_read_defensively(): void
    {
        $meta = Backups::readMeta(json_encode([
            'software' => 'paper',
            'version' => '1.21.4',
            'variables' => ['MINECRAFT_VERSION' => '1.21.4', 'BUILD_NUMBER' => 'latest', 'bad key' => 'x', 'LONG' => str_repeat('a', 500), 'NUM' => 5],
            'to' => ['software' => 'PURPUR', 'version' => '1.21.5', 'build' => '../2'],
            'at' => '5',
        ], JSON_THROW_ON_ERROR));

        $this->assertSame(['MINECRAFT_VERSION' => '1.21.4', 'BUILD_NUMBER' => 'latest'], $meta['variables']);
        $this->assertSame(['software' => null, 'version' => '1.21.5', 'build' => null], $meta['to']);
        $this->assertNull($meta['at']);
    }

    public function test_meta_survives_garbage(): void
    {
        $empty = ['software' => null, 'version' => null, 'variables' => [], 'image' => null, 'to' => ['software' => null, 'version' => null, 'build' => null], 'at' => null];

        $this->assertSame($empty, Backups::readMeta(null));
        $this->assertSame($empty, Backups::readMeta('not json'));
        $this->assertSame($empty, Backups::readMeta('[1,2]'));
        $this->assertSame($empty, Backups::readMeta('{"software":"../x","version":"../1","image":5,"variables":"x","to":7,"at":-1}'));
    }
}
