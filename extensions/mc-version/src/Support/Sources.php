<?php

namespace McVersion\Support;

/**
 * Reads the answers of the four download APIs into one shape. No network here: the
 * Upstream service fetches and caches, these only pick values out of decoded JSON
 * and refuse anything that does not look right.
 */
final class Sources
{
    /** Hosts a server jar may be downloaded from. */
    public const array DOWNLOAD_HOSTS = [
        'fill-data.papermc.io',
        'fill.papermc.io',
        'api.purpurmc.org',
        'meta.fabricmc.net',
        'piston-data.mojang.com',
        'launcher.mojang.com',
    ];

    public const string PAPER_FILL = 'https://fill.papermc.io/v3/projects/paper';

    public const string PURPUR = 'https://api.purpurmc.org/v2/purpur';

    public const string FABRIC = 'https://meta.fabricmc.net/v2';

    public const string MOJANG_MANIFEST = 'https://piston-meta.mojang.com/mc/game/version_manifest_v2.json';

    /** Only these hosts are fetched for version metadata. */
    public const array METADATA_HOSTS = ['piston-meta.mojang.com'];

    public static function isAllowedDownload(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && in_array($parts['host'] ?? '', self::DOWNLOAD_HOSTS, true)
            && ! isset($parts['user']) && ! isset($parts['port']);
    }

    public static function isAllowedMetadata(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts) && ($parts['scheme'] ?? null) === 'https' && in_array($parts['host'] ?? '', self::METADATA_HOSTS, true);
    }

    /* Paper (Fill v3) */

    /**
     * @param  array<mixed>  $project  GET /v3/projects/paper
     * @return list<array{id: string, stable: bool}>
     */
    public static function paperVersions(array $project): array
    {
        $ids = [];
        foreach ((array) ($project['versions'] ?? []) as $group) {
            foreach ((array) $group as $id) {
                if (is_string($id)) {
                    $ids[] = $id;
                }
            }
        }

        return self::entries($ids, fn (string $id): bool => Versions::isRelease($id));
    }

    /**
     * The build to install: the newest on the STABLE channel; when there is none and
     * experimental builds are allowed, the newest of any channel.
     *
     * @param  array<mixed>  $builds  GET /v3/projects/paper/versions/{version}/builds
     * @return array{build: string, channel: string, stable: bool, url: string, filename: string, hash: ?array{algo: string, value: string}, size: ?int}|null
     */
    public static function paperBuild(array $builds, bool $experimental): ?array
    {
        $candidates = [];
        foreach ($builds as $build) {
            if (! is_array($build) || ! is_int($build['id'] ?? null)) {
                continue;
            }
            $download = $build['downloads']['server:default'] ?? null;
            if (! is_array($download) || ! is_string($download['url'] ?? null) || ! self::isAllowedDownload($download['url'])) {
                continue;
            }
            $candidates[] = [$build, $download];
        }

        usort($candidates, fn (array $a, array $b): int => $b[0]['id'] <=> $a[0]['id']);

        foreach ([true, false] as $stableOnly) {
            if (! $stableOnly && ! $experimental) {
                return null;
            }
            foreach ($candidates as [$build, $download]) {
                $channel = strtoupper((string) ($build['channel'] ?? ''));
                if ($stableOnly && $channel !== 'STABLE') {
                    continue;
                }

                $sha256 = $download['checksums']['sha256'] ?? null;

                return [
                    'build' => (string) $build['id'],
                    'channel' => $channel,
                    'stable' => $channel === 'STABLE',
                    'url' => $download['url'],
                    'filename' => is_string($download['name'] ?? null) ? $download['name'] : 'paper-'.$build['id'].'.jar',
                    'hash' => is_string($sha256) && preg_match('/^[a-f0-9]{64}$/D', $sha256) === 1 ? ['algo' => 'sha256', 'value' => $sha256] : null,
                    'size' => is_int($download['size'] ?? null) && $download['size'] > 0 ? $download['size'] : null,
                ];
            }
        }

        return null;
    }

    /* Purpur */

    /**
     * @param  array<mixed>  $root  GET /v2/purpur
     * @return list<array{id: string, stable: bool}>
     */
    public static function purpurVersions(array $root): array
    {
        $ids = array_values(array_filter((array) ($root['versions'] ?? []), 'is_string'));

        return self::entries(Versions::sortNewestFirst($ids), fn (string $id): bool => Versions::isRelease($id));
    }

    /**
     * Build numbers of one version, newest first, as the API lists them.
     *
     * @param  array<mixed>  $version  GET /v2/purpur/{version}
     * @return list<string>
     */
    public static function purpurBuildIds(array $version): array
    {
        $all = array_values(array_filter(
            array_map('strval', (array) ($version['builds']['all'] ?? [])),
            fn (string $id): bool => preg_match('/^\d{1,6}$/D', $id) === 1,
        ));
        usort($all, fn (string $a, string $b): int => (int) $b <=> (int) $a);

        $latest = (string) ($version['builds']['latest'] ?? '');
        if (preg_match('/^\d{1,6}$/D', $latest) === 1) {
            $all = array_values(array_unique([$latest, ...$all]));
        }

        return $all;
    }

    /**
     * @param  array<mixed>  $build  GET /v2/purpur/{version}/{build}
     * @return array{build: string, channel: string, stable: bool, url: string, filename: string, hash: ?array{algo: string, value: string}, size: ?int}|null null when that build failed
     */
    public static function purpurBuild(array $build, string $version): ?array
    {
        $id = (string) ($build['build'] ?? '');
        if (strtoupper((string) ($build['result'] ?? '')) !== 'SUCCESS' || preg_match('/^\d{1,6}$/D', $id) !== 1 || ! Versions::isValid($version)) {
            return null;
        }

        $md5 = $build['md5'] ?? null;

        return [
            'build' => $id,
            'channel' => 'STABLE',
            'stable' => true,
            'url' => self::PURPUR.'/'.$version.'/'.$id.'/download',
            'filename' => 'purpur-'.$version.'-'.$id.'.jar',
            'hash' => is_string($md5) && preg_match('/^[a-f0-9]{32}$/D', $md5) === 1 ? ['algo' => 'md5', 'value' => $md5] : null,
            'size' => null,
        ];
    }

    /* Fabric */

    /**
     * @param  array<mixed>  $games  GET /v2/versions/game
     * @return list<array{id: string, stable: bool}>
     */
    public static function fabricVersions(array $games): array
    {
        $entries = [];
        foreach ($games as $game) {
            if (is_array($game) && is_string($game['version'] ?? null)) {
                $entries[$game['version']] = ['id' => $game['version'], 'stable' => ($game['stable'] ?? false) === true && Versions::isRelease($game['version'])];
            }
        }

        return array_values(array_filter($entries, fn (array $entry): bool => Versions::isValid($entry['id'])));
    }

    /**
     * The newest loader that supports the game version, stable when there is one.
     *
     * @param  array<mixed>  $loaders  GET /v2/versions/loader/{game}
     */
    public static function fabricLoader(array $loaders, bool $experimental): ?string
    {
        $stable = null;
        $any = null;
        foreach ($loaders as $entry) {
            $version = is_array($entry) ? ($entry['loader']['version'] ?? null) : null;
            if (! is_string($version) || preg_match('/^[0-9A-Za-z.+\-]{1,40}$/D', $version) !== 1) {
                continue;
            }
            $any ??= $version;
            if (($entry['loader']['stable'] ?? false) === true) {
                $stable ??= $version;
            }
        }

        return $stable ?? ($experimental ? $any : null);
    }

    /**
     * @param  array<mixed>  $installers  GET /v2/versions/installer
     */
    public static function fabricInstaller(array $installers): ?string
    {
        $any = null;
        foreach ($installers as $entry) {
            $version = is_array($entry) ? ($entry['version'] ?? null) : null;
            if (! is_string($version) || preg_match('/^[0-9A-Za-z.+\-]{1,40}$/D', $version) !== 1) {
                continue;
            }
            $any ??= $version;
            if (($entry['stable'] ?? false) === true) {
                return $version;
            }
        }

        return $any;
    }

    /**
     * @return array{build: string, channel: string, stable: bool, url: string, filename: string, hash: null, size: null}
     */
    public static function fabricBuild(string $game, string $loader, string $installer, bool $stable): array
    {
        return [
            'build' => $loader,
            'channel' => $stable ? 'STABLE' : 'EXPERIMENTAL',
            'stable' => $stable,
            'url' => self::FABRIC.'/versions/loader/'.rawurlencode($game).'/'.rawurlencode($loader).'/'.rawurlencode($installer).'/server/jar',
            'filename' => 'fabric-server-mc.'.$game.'-loader.'.$loader.'-launcher.'.$installer.'.jar',
            'hash' => null,
            'size' => null,
        ];
    }

    /* Vanilla (Mojang) */

    /**
     * Releases and snapshots that have a server jar. Alpha and beta builds and
     * releases before 1.2.5 never had one.
     *
     * @param  array<mixed>  $manifest  version_manifest_v2.json
     * @return list<array{id: string, stable: bool, url: string}>
     */
    public static function mojangVersions(array $manifest): array
    {
        $list = [];
        foreach ((array) ($manifest['versions'] ?? []) as $entry) {
            if (! is_array($entry) || ! is_string($entry['id'] ?? null) || ! is_string($entry['url'] ?? null)) {
                continue;
            }
            $type = $entry['type'] ?? null;
            if (! in_array($type, ['release', 'snapshot'], true) || ! Versions::isValid($entry['id']) || ! self::isAllowedMetadata($entry['url'])) {
                continue;
            }
            // 1.2.5 is the first release with a downloadable server.
            if (Versions::parse($entry['id']) !== null && (Versions::compare($entry['id'], '1.2.5') ?? 0) < 0) {
                continue;
            }
            if (preg_match('/^(\d{2})w\d{2}[a-z]$/D', $entry['id'], $weekly) === 1 && (int) $weekly[1] < 14) {
                continue;
            }

            $list[] = ['id' => $entry['id'], 'stable' => $type === 'release', 'url' => $entry['url']];
        }

        return $list;
    }

    /**
     * @param  array<mixed>  $version  one version's JSON, from its manifest url
     * @return array{build: null, channel: string, stable: bool, url: string, filename: string, hash: ?array{algo: string, value: string}, size: ?int}|null null when it has no server jar
     */
    public static function mojangServer(array $version): ?array
    {
        $server = $version['downloads']['server'] ?? null;
        if (! is_array($server) || ! is_string($server['url'] ?? null) || ! self::isAllowedDownload($server['url'])) {
            return null;
        }

        $sha1 = $server['sha1'] ?? null;

        return [
            'build' => null,
            'channel' => ($version['type'] ?? 'release') === 'release' ? 'STABLE' : 'SNAPSHOT',
            'stable' => ($version['type'] ?? 'release') === 'release',
            'url' => $server['url'],
            'filename' => 'server.jar',
            'hash' => is_string($sha1) && preg_match('/^[a-f0-9]{40}$/D', $sha1) === 1 ? ['algo' => 'sha1', 'value' => $sha1] : null,
            'size' => is_int($server['size'] ?? null) && $server['size'] > 0 ? $server['size'] : null,
        ];
    }

    /**
     * @param  list<string>  $ids
     * @param  \Closure(string): bool  $isStable
     * @return list<array{id: string, stable: bool}>
     */
    private static function entries(array $ids, \Closure $isStable): array
    {
        $seen = [];
        $list = [];
        foreach ($ids as $id) {
            if (isset($seen[$id]) || ! Versions::isValid($id)) {
                continue;
            }
            $seen[$id] = true;
            $list[] = ['id' => $id, 'stable' => $isStable($id)];
        }

        return $list;
    }
}
