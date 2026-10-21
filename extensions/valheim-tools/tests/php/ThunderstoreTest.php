<?php

use PHPUnit\Framework\TestCase;
use ValheimTools\Support\Thunderstore;

class ThunderstoreTest extends TestCase
{
    private function fixture(string $name): array
    {
        return json_decode(file_get_contents(__DIR__.'/fixtures/'.$name), true);
    }

    public function test_dependency_strings(): void
    {
        $this->assertSame(['namespace' => 'denikson', 'name' => 'BepInExPack_Valheim', 'version' => '5.4.2351'], Thunderstore::parseDependency('denikson-BepInExPack_Valheim-5.4.2351'));
        foreach (['', 'a-b', 'a-b-1.2', 'a-b-1.2.3-beta', '../a-b-1.2.3', 'a b-c-1.2.3'] as $bad) {
            $this->assertNull(Thunderstore::parseDependency($bad), json_encode($bad));
        }
    }

    public function test_references(): void
    {
        $this->assertSame(['namespace' => 'RandyKnapp', 'name' => 'EpicLoot', 'version' => null], Thunderstore::parseReference('RandyKnapp-EpicLoot'));
        $this->assertSame(['namespace' => 'RandyKnapp', 'name' => 'EpicLoot', 'version' => '0.14.13'], Thunderstore::parseReference('RandyKnapp-EpicLoot-0.14.13'));
        $this->assertSame(['namespace' => 'RandyKnapp', 'name' => 'EpicLoot', 'version' => null], Thunderstore::parseReference('https://thunderstore.io/c/valheim/p/RandyKnapp/EpicLoot/'));
        $this->assertSame(['namespace' => 'RandyKnapp', 'name' => 'EpicLoot', 'version' => '0.14.13'], Thunderstore::parseReference('https://thunderstore.io/package/RandyKnapp/EpicLoot/0.14.13/'));
        foreach (['', 'EpicLoot', 'http://evil.example/c/valheim/p/A/B/', 'https://evil.example/package/A/B/', 'https://thunderstore.io.evil.example/package/A/B/', 'A/B', "A-B\nC-D", str_repeat('a', 400)] as $bad) {
            $this->assertNull(Thunderstore::parseReference($bad), substr(json_encode($bad), 0, 40));
        }
    }

    public function test_compare_versions(): void
    {
        $this->assertSame(0, Thunderstore::compareVersions('1.2.3', '1.2.3'));
        $this->assertSame(1, Thunderstore::compareVersions('1.10.0', '1.9.9'));
        $this->assertSame(-1, Thunderstore::compareVersions('0.14.13', '0.14.14'));
        $this->assertSame(1, Thunderstore::compareVersions('5.4.2351', '5.4.2333'));
    }

    public function test_bepinex_is_recognised(): void
    {
        $this->assertTrue(Thunderstore::isBepInEx('denikson', 'BepInExPack_Valheim'));
        $this->assertTrue(Thunderstore::isBepInEx('Denikson', 'bepinexpack_valheim'));
        $this->assertFalse(Thunderstore::isBepInEx('someone', 'BepInExPack_Valheim'));
    }

    public function test_package(): void
    {
        $package = Thunderstore::package($this->fixture('thunderstore-package.json'));
        $this->assertSame('RandyKnapp', $package['namespace']);
        $this->assertSame('EpicLoot', $package['name']);
        $this->assertSame('0.14.13', $package['version']);
        $this->assertCount(3, $package['dependencies']);
        $this->assertSame('Jotunn', $package['dependencies'][2]['name']);
        $this->assertSame('https://thunderstore.io/package/download/RandyKnapp/EpicLoot/0.14.13/', $package['url']);
    }

    public function test_download_url_is_never_taken_from_the_answer(): void
    {
        $json = $this->fixture('thunderstore-package.json');
        $json['latest']['download_url'] = 'https://evil.example/x.zip';
        $this->assertStringStartsWith('https://thunderstore.io/package/download/', Thunderstore::package($json)['url']);
    }

    public function test_package_rejects_bad_names_and_versions(): void
    {
        $json = $this->fixture('thunderstore-package.json');
        $bad = $json;
        $bad['latest']['name'] = '../x';
        $this->assertNull(Thunderstore::package($bad));
        $bad = $json;
        $bad['latest']['version_number'] = '1.0';
        $this->assertNull(Thunderstore::package($bad));
        $this->assertNull(Thunderstore::package([]));
    }

    public function test_package_skips_garbage_dependencies(): void
    {
        $json = $this->fixture('thunderstore-package.json');
        $json['latest']['dependencies'] = ['a-b-1.0.0', '../../x', 5, null];
        $this->assertCount(1, Thunderstore::package($json)['dependencies']);
    }

    public function test_search_results(): void
    {
        $result = Thunderstore::searchResults($this->fixture('thunderstore-listing.json'));
        $this->assertSame(126, $result['total']);
        $this->assertTrue($result['has_more']);
        $this->assertCount(2, $result['results']);
        $first = $result['results'][0];
        $this->assertSame('RandyKnapp', $first['namespace']);
        $this->assertTrue($first['server_side']);
        $this->assertTrue($first['client_side']);
        $this->assertSame('https://ccdn.thunderstore.io/live/repository/icons/RandyKnapp-EpicLoot-0.14.13.png', $first['icon']);
    }

    public function test_search_results_drop_bad_entries_and_icons(): void
    {
        $result = Thunderstore::searchResults(['count' => 2, 'results' => [
            ['namespace' => '../x', 'name' => 'Y'],
            'junk',
            ['namespace' => 'A', 'name' => 'B', 'icon_url' => 'https://evil.example/i.png', 'description' => str_repeat('x', 500)],
        ]]);
        $this->assertCount(1, $result['results']);
        $this->assertNull($result['results'][0]['icon']);
        $this->assertSame(300, mb_strlen($result['results'][0]['description']));
        $this->assertFalse($result['has_more']);
        $this->assertSame([], Thunderstore::searchResults([])['results']);
    }

    public function test_safe_icon(): void
    {
        $this->assertNotNull(Thunderstore::safeIcon('https://gcdn.thunderstore.io/a.png'));
        foreach (['http://ccdn.thunderstore.io/a.png', 'https://ccdn.thunderstore.io.evil.example/a.png', 'javascript:alert(1)', null, 5] as $bad) {
            $this->assertNull(Thunderstore::safeIcon($bad));
        }
    }

    public function test_manifest(): void
    {
        $manifest = Thunderstore::manifest(file_get_contents(__DIR__.'/fixtures/manifest-doorbell.json'));
        $this->assertSame('Doorbell', $manifest['name']);
        $this->assertSame('1.0.1', $manifest['version']);
        $this->assertNull($manifest['website']);

        $this->assertSame('X', Thunderstore::manifest("\xEF\xBB\xBF".'{"name":"X","version_number":"1.0.0","website_url":"javascript:alert(1)"}')['name']);
        $this->assertNull(Thunderstore::manifest("\xEF\xBB\xBF".'{"name":"X","version_number":"1.0.0","website_url":"javascript:alert(1)"}')['website']);
        $this->assertSame('', Thunderstore::manifest('{"name":"X","version_number":"banana"}')['version']);
        $this->assertNull(Thunderstore::manifest('not json'));
        $this->assertNull(Thunderstore::manifest('{"version_number":"1.0.0"}'));
    }

    public function test_folder_names(): void
    {
        $this->assertSame('A-B', Thunderstore::folderName('A', 'B'));
        $this->assertSame(['namespace' => 'A', 'name' => 'B_c'], Thunderstore::parseFolder('A-B_c'));
        foreach (['A', 'A-B-C', '../A-B', 'A-B/', ''] as $bad) {
            $this->assertNull(Thunderstore::parseFolder($bad));
        }
    }

    public function test_download_size_limit(): void
    {
        $this->assertFalse(Thunderstore::tooLarge(null));
        $this->assertFalse(Thunderstore::tooLarge(0));
        $this->assertFalse(Thunderstore::tooLarge(Thunderstore::MAX_DOWNLOAD));
        $this->assertTrue(Thunderstore::tooLarge(Thunderstore::MAX_DOWNLOAD + 1));
        $this->assertSame(200 * 1024 * 1024, Thunderstore::MAX_DOWNLOAD);
    }

    public function test_sizes_from_answers_and_headers(): void
    {
        $this->assertSame(1234, Thunderstore::size(1234));
        $this->assertSame(1234, Thunderstore::size('1234'));
        $this->assertSame(0, Thunderstore::size(0));
        $this->assertNull(Thunderstore::size(-1));
        $this->assertNull(Thunderstore::size('12a'));
        $this->assertNull(Thunderstore::size('-5'));
        $this->assertNull(Thunderstore::size(null));
        $this->assertNull(Thunderstore::size(1.5));
        $this->assertNull(Thunderstore::size(['x']));
    }

    public function test_package_carries_a_declared_size_when_there_is_one(): void
    {
        $json = $this->fixture('thunderstore-package.json');
        $this->assertNull(Thunderstore::package($json)['size']);

        $json['latest']['file_size'] = 5242880;
        $this->assertSame(5242880, Thunderstore::package($json)['size']);
    }
}
