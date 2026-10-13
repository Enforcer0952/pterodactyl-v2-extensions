<?php

namespace McVersion\Tests;

use McVersion\Support\Sources;
use PHPUnit\Framework\TestCase;

final class SourcesTest extends TestCase
{
    /** @return array<mixed> */
    private static function json(string $name): array
    {
        return json_decode((string) file_get_contents(__DIR__.'/fixtures/'.$name), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_paper_versions_are_flattened_and_marked(): void
    {
        $versions = Sources::paperVersions(self::json('paper-project.json'));
        $ids = array_column($versions, 'id');

        $this->assertSame('26.3', $ids[0]);
        $this->assertContains('1.16.5', $ids);
        $this->assertContains('26.3-rc-3', $ids);

        $byId = array_column($versions, 'stable', 'id');
        $this->assertTrue($byId['26.3']);
        $this->assertTrue($byId['1.16.5']);
        $this->assertFalse($byId['26.3-rc-3']);
        $this->assertFalse($byId['1.21.11-rc3'] ?? false);
    }

    public function test_paper_picks_the_newest_stable_build(): void
    {
        $build = Sources::paperBuild(self::json('paper-builds.json'), false);

        $this->assertNotNull($build);
        $this->assertSame('180', $build['build']);
        $this->assertSame('STABLE', $build['channel']);
        $this->assertTrue($build['stable']);
        $this->assertSame('paper-26.3-180.jar', $build['filename']);
        $this->assertSame(['algo' => 'sha256', 'value' => 'd6a5589a720fbe74b15fd9be6679bff3c55f737801e149fab8337cd0b0a1a989'], $build['hash']);
        $this->assertSame(55143262, $build['size']);
        $this->assertStringStartsWith('https://fill-data.papermc.io/v1/objects/', $build['url']);
    }

    public function test_paper_builds_given_out_of_order(): void
    {
        $builds = array_reverse(self::json('paper-builds.json'));

        $this->assertSame('180', Sources::paperBuild($builds, false)['build'] ?? null);
    }

    public function test_paper_without_stable_builds(): void
    {
        $builds = self::json('paper-builds-unstable.json');

        $this->assertNull(Sources::paperBuild($builds, false));

        $experimental = Sources::paperBuild($builds, true);
        $this->assertSame('ALPHA', $experimental['channel'] ?? null);
        $this->assertFalse($experimental['stable'] ?? true);
    }

    public function test_paper_prefers_stable_even_when_experimental_is_allowed(): void
    {
        $this->assertSame('STABLE', Sources::paperBuild(self::json('paper-builds.json'), true)['channel'] ?? null);
    }

    public function test_paper_refuses_downloads_from_other_hosts(): void
    {
        $builds = self::json('paper-builds.json');
        foreach ($builds as &$build) {
            $build['downloads']['server:default']['url'] = 'https://evil.example.com/paper.jar';
        }
        unset($build);

        $this->assertNull(Sources::paperBuild($builds, true));
    }

    public function test_paper_ignores_a_bad_checksum(): void
    {
        $builds = self::json('paper-builds.json');
        $builds[0]['downloads']['server:default']['checksums']['sha256'] = 'xyz';

        $build = Sources::paperBuild($builds, false);

        $this->assertNotNull($build);
        $this->assertNull($build['hash'], 'the build is usable, it just cannot be verified');
    }

    public function test_purpur_versions_newest_first(): void
    {
        $versions = Sources::purpurVersions(self::json('purpur-root.json'));
        $ids = array_column($versions, 'id');

        $this->assertSame('26.3', $ids[0]);
        $this->assertSame('1.14.1', end($ids));
        $this->assertSame(array_search('1.21.1', $ids) < array_search('1.20.6', $ids), true);
        $this->assertNotContains('', $ids);
        $this->assertSame([], array_filter($versions, fn (array $entry): bool => ! $entry['stable']));
    }

    public function test_purpur_build_ids_and_details(): void
    {
        $ids = Sources::purpurBuildIds(self::json('purpur-version.json'));
        $this->assertSame('2329', $ids[0]);
        $this->assertSame(['2329', '2328', '2327'], array_slice($ids, 0, 3));

        $build = Sources::purpurBuild(self::json('purpur-build.json'), '1.21.1');
        $this->assertNotNull($build);
        $this->assertSame('2329', $build['build']);
        $this->assertSame('https://api.purpurmc.org/v2/purpur/1.21.1/2329/download', $build['url']);
        $this->assertSame('md5', $build['hash']['algo'] ?? null);
        $this->assertTrue(Sources::isAllowedDownload($build['url']));
    }

    public function test_purpur_failed_build_is_skipped(): void
    {
        $failed = ['build' => '2330', 'result' => 'FAILURE', 'md5' => str_repeat('a', 32)];

        $this->assertNull(Sources::purpurBuild($failed, '1.21.1'));
        $this->assertNull(Sources::purpurBuild(['build' => '../1', 'result' => 'SUCCESS'], '1.21.1'));
        $this->assertNull(Sources::purpurBuild(['build' => '1', 'result' => 'SUCCESS'], '../../x'));
    }

    public function test_fabric_versions_keep_real_versions_only(): void
    {
        $versions = Sources::fabricVersions(self::json('fabric-game.json'));
        $byId = array_column($versions, 'stable', 'id');

        $this->assertArrayHasKey('26.3', $byId);
        $this->assertTrue($byId['26.3']);
        $this->assertFalse($byId['26.4-snapshot-4']);
        $this->assertTrue($byId['1.21.1']);
        $this->assertArrayHasKey('24w14a', $byId);
        $this->assertArrayNotHasKey('3D Shareware v1.34', $byId);
        $this->assertArrayNotHasKey('1.RV-Pre1', $byId);
        $this->assertSame(count($versions), count(array_unique(array_column($versions, 'id'))), 'no duplicates');
    }

    public function test_fabric_picks_loader_and_installer(): void
    {
        $loaders = self::json('fabric-loader.json');
        $this->assertSame('0.19.5', Sources::fabricLoader($loaders, false));
        $this->assertSame('1.1.2', Sources::fabricInstaller(self::json('fabric-installer.json')));

        // A game version with no stable loader needs the experimental switch.
        foreach ($loaders as &$entry) {
            $entry['loader']['stable'] = false;
        }
        unset($entry);
        $this->assertNull(Sources::fabricLoader($loaders, false));
        $this->assertSame('0.19.5', Sources::fabricLoader($loaders, true));
    }

    public function test_fabric_download_url_and_hostile_values(): void
    {
        $build = Sources::fabricBuild('1.21.1', '0.19.5', '1.1.2', true);

        $this->assertSame('https://meta.fabricmc.net/v2/versions/loader/1.21.1/0.19.5/1.1.2/server/jar', $build['url']);
        $this->assertNull($build['hash']);
        $this->assertTrue(Sources::isAllowedDownload($build['url']));

        $this->assertNull(Sources::fabricLoader([['loader' => ['version' => '../x', 'stable' => true]]], true));
        $this->assertNull(Sources::fabricInstaller([['version' => "1\n2", 'stable' => true]]));
    }

    public function test_mojang_versions_skip_old_builds(): void
    {
        $versions = Sources::mojangVersions(self::json('mojang-manifest.json'));
        $byId = array_column($versions, 'stable', 'id');

        $this->assertSame(['26.4-snapshot-4', '26.3', '1.21.1', '24w14a', '1.20.4', '1.16.5', '1.2.5'], array_keys($byId));
        $this->assertFalse($byId['26.4-snapshot-4']);
        $this->assertFalse($byId['24w14a']);
        $this->assertTrue($byId['1.21.1']);
        $this->assertArrayNotHasKey('b1.7.3', $byId);
        $this->assertArrayNotHasKey('a1.0.4', $byId);
    }

    public function test_mojang_manifest_urls_must_be_mojang(): void
    {
        $manifest = ['versions' => [['id' => '1.21.1', 'type' => 'release', 'url' => 'https://evil.example.com/1.21.1.json']]];

        $this->assertSame([], Sources::mojangVersions($manifest));
    }

    public function test_mojang_server_download(): void
    {
        $server = Sources::mojangServer(self::json('mojang-version.json'));

        $this->assertNotNull($server);
        $this->assertSame('59353fb40c36d304f2035d51e7d6e6baa98dc05c', $server['hash']['value'] ?? null);
        $this->assertSame('sha1', $server['hash']['algo'] ?? null);
        $this->assertSame(51627615, $server['size']);
        $this->assertSame('server.jar', $server['filename']);
        $this->assertTrue($server['stable']);
    }

    public function test_mojang_version_without_server_jar(): void
    {
        $this->assertNull(Sources::mojangServer(['downloads' => ['client' => ['url' => 'https://piston-data.mojang.com/x/client.jar']]]));
        $this->assertNull(Sources::mojangServer(['downloads' => ['server' => ['url' => 'http://piston-data.mojang.com/x/server.jar', 'sha1' => str_repeat('a', 40)]]]));
    }

    public function test_download_url_checks(): void
    {
        $this->assertTrue(Sources::isAllowedDownload('https://fill-data.papermc.io/v1/objects/abc/paper.jar'));
        $this->assertFalse(Sources::isAllowedDownload('http://fill-data.papermc.io/x.jar'));
        $this->assertFalse(Sources::isAllowedDownload('https://fill-data.papermc.io.evil.com/x.jar'));
        $this->assertFalse(Sources::isAllowedDownload('https://user@fill-data.papermc.io/x.jar'));
        $this->assertFalse(Sources::isAllowedDownload('https://fill-data.papermc.io:8443/x.jar'));
        $this->assertFalse(Sources::isAllowedDownload('file:///etc/passwd'));
        $this->assertFalse(Sources::isAllowedDownload(''));
    }
}
