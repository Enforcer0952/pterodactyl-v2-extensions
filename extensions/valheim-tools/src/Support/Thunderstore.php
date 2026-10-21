<?php

namespace ValheimTools\Support;

/**
 * Parsing for Thunderstore data (https://thunderstore.io/c/valheim/).
 *
 * - Package details: GET /api/experimental/package/{namespace}/{name}/ (documented):
 *   "latest" holds version_number, dependencies ("Owner-Name-1.2.3"), download_url.
 * - Search: GET /api/cyberstorm/listing/valheim/?q=&ordering=&page= (the endpoint the
 *   Thunderstore website itself uses; not in the API docs). The full v1 package list is
 *   over 150 MB, so it is not used.
 * - manifest.json inside every package: name, version_number, dependencies.
 */
final class Thunderstore
{
    public const string NAME = '/^[A-Za-z0-9_]{1,128}$/D';

    public const string VERSION = '/^\d{1,9}\.\d{1,9}\.\d{1,9}$/D';

    /** The BepInEx pack the eggs install themselves. Never installed or changed here. */
    public const string BEPINEX_NAMESPACE = 'denikson';

    public const string BEPINEX_NAME = 'BepInExPack_Valheim';

    /** The biggest package this extension downloads (200 MB). */
    public const int MAX_DOWNLOAD = 200 * 1024 * 1024;

    /** The only host download links and icons may point to. */
    public const array ICON_HOSTS = ['ccdn.thunderstore.io', 'gcdn.thunderstore.io'];

    public static function validName(string $value): bool
    {
        return preg_match(self::NAME, $value) === 1;
    }

    public static function validVersion(string $value): bool
    {
        return preg_match(self::VERSION, $value) === 1;
    }

    /** Whether a download of this many bytes is over the limit. An unknown size (null) is not. */
    public static function tooLarge(?int $bytes): bool
    {
        return $bytes !== null && $bytes > self::MAX_DOWNLOAD;
    }

    /** A size in bytes from an API answer or a Content-Length header: a whole number, else null. */
    public static function size(mixed $value): ?int
    {
        if (is_string($value) && preg_match('/^\d{1,15}$/D', $value) === 1) {
            return (int) $value;
        }

        return is_int($value) && $value >= 0 ? $value : null;
    }

    public static function isBepInEx(string $namespace, string $name): bool
    {
        return strcasecmp($namespace, self::BEPINEX_NAMESPACE) === 0 && strcasecmp($name, self::BEPINEX_NAME) === 0;
    }

    /**
     * "Owner-Name-1.2.3" (a dependency string).
     *
     * @return array{namespace: string, name: string, version: string}|null
     */
    public static function parseDependency(string $value): ?array
    {
        if (preg_match('/^([A-Za-z0-9_]+)-([A-Za-z0-9_]+)-(\d+\.\d+\.\d+)$/D', trim($value), $m) !== 1) {
            return null;
        }

        return ['namespace' => $m[1], 'name' => $m[2], 'version' => $m[3]];
    }

    /**
     * Whatever a person pastes: "Owner-Name", "Owner-Name-1.2.3" or a Thunderstore link.
     *
     * @return array{namespace: string, name: string, version: ?string}|null
     */
    public static function parseReference(string $input): ?array
    {
        $text = trim($input);
        if ($text === '' || strlen($text) > 300) {
            return null;
        }

        if (preg_match('#^https://(?:www\.)?thunderstore\.io/(?:c/[A-Za-z0-9_\-]+/p|package)/([A-Za-z0-9_]+)/([A-Za-z0-9_]+)(?:/(?:v/)?(\d+\.\d+\.\d+))?/?(?:[?\#].*)?$#D', $text, $m) === 1) {
            return ['namespace' => $m[1], 'name' => $m[2], 'version' => $m[3] ?? null];
        }

        $dependency = self::parseDependency($text);
        if ($dependency !== null) {
            return $dependency;
        }

        if (preg_match('/^([A-Za-z0-9_]+)-([A-Za-z0-9_]+)$/D', $text, $m) === 1) {
            return ['namespace' => $m[1], 'name' => $m[2], 'version' => null];
        }

        return null;
    }

    /** -1, 0 or 1 comparing two x.y.z versions. */
    public static function compareVersions(string $a, string $b): int
    {
        $x = array_map('intval', explode('.', $a));
        $y = array_map('intval', explode('.', $b));
        for ($i = 0; $i < 3; $i++) {
            $cmp = ($x[$i] ?? 0) <=> ($y[$i] ?? 0);
            if ($cmp !== 0) {
                return $cmp;
            }
        }

        return 0;
    }

    /** The folder under BepInEx/plugins for a package: "Owner-Name", as r2modman and Thunderstore's manager name it. */
    public static function folderName(string $namespace, string $name): string
    {
        return $namespace.'-'.$name;
    }

    /**
     * @return array{namespace: string, name: string}|null
     */
    public static function parseFolder(string $folder): ?array
    {
        if (preg_match('/^([A-Za-z0-9_]+)-([A-Za-z0-9_]+)$/D', $folder, $m) !== 1) {
            return null;
        }

        return ['namespace' => $m[1], 'name' => $m[2]];
    }

    /**
     * One package from the experimental API: the latest version, or the version asked for.
     *
     * @param  array<string, mixed>  $json
     * @return array{namespace: string, name: string, version: string, description: string, dependencies: list<array{namespace: string, name: string, version: string}>, deprecated: bool, url: string, size: ?int}|null
     */
    public static function package(array $json): ?array
    {
        $version = isset($json['latest']) && is_array($json['latest']) ? $json['latest'] : $json;
        $namespace = (string) ($version['namespace'] ?? $json['namespace'] ?? '');
        $name = (string) ($version['name'] ?? $json['name'] ?? '');
        $number = (string) ($version['version_number'] ?? '');
        if (! self::validName($namespace) || ! self::validName($name) || ! self::validVersion($number)) {
            return null;
        }

        $dependencies = [];
        foreach ((array) ($version['dependencies'] ?? []) as $dependency) {
            $parsed = is_string($dependency) ? self::parseDependency($dependency) : null;
            if ($parsed !== null) {
                $dependencies[] = $parsed;
            }
        }

        return [
            'namespace' => $namespace,
            'name' => $name,
            'version' => $number,
            'description' => mb_substr((string) ($version['description'] ?? ''), 0, 300),
            'dependencies' => $dependencies,
            'deprecated' => (bool) ($json['is_deprecated'] ?? false),
            'url' => self::downloadUrl($namespace, $name, $number),
            // Only given by some answers; the real size is checked again before the download.
            'size' => self::size($version['file_size'] ?? $json['file_size'] ?? null),
        ];
    }

    /** The download link, built here from validated parts and never taken from the API answer. */
    public static function downloadUrl(string $namespace, string $name, string $version): string
    {
        return 'https://thunderstore.io/package/download/'.$namespace.'/'.$name.'/'.$version.'/';
    }

    /**
     * Search results from the listing endpoint, reduced to what the page shows.
     *
     * @param  array<string, mixed>  $json
     * @return array{results: list<array<string, mixed>>, total: int, has_more: bool}
     */
    public static function searchResults(array $json): array
    {
        $results = [];
        foreach ((array) ($json['results'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $namespace = (string) ($item['namespace'] ?? '');
            $name = (string) ($item['name'] ?? '');
            if (! self::validName($namespace) || ! self::validName($name)) {
                continue;
            }
            $categories = [];
            foreach ((array) ($item['categories'] ?? []) as $category) {
                if (is_array($category) && is_string($category['name'] ?? null)) {
                    $categories[] = $category['name'];
                }
            }
            $results[] = [
                'namespace' => $namespace,
                'name' => $name,
                'description' => mb_substr((string) ($item['description'] ?? ''), 0, 300),
                'downloads' => (int) ($item['download_count'] ?? 0),
                'rating' => (int) ($item['rating_count'] ?? 0),
                'size' => (int) ($item['size'] ?? 0),
                'icon' => self::safeIcon($item['icon_url'] ?? null),
                'updated' => is_string($item['last_updated'] ?? null) ? $item['last_updated'] : null,
                'categories' => $categories,
                'server_side' => in_array('Server-side', $categories, true),
                'client_side' => in_array('Client-side', $categories, true),
                'deprecated' => (bool) ($item['is_deprecated'] ?? false),
            ];
        }

        return [
            'results' => $results,
            'total' => (int) ($json['count'] ?? count($results)),
            'has_more' => is_string($json['next'] ?? null) && $json['next'] !== '',
        ];
    }

    public static function safeIcon(mixed $url): ?string
    {
        if (! is_string($url)) {
            return null;
        }
        $parts = parse_url($url);

        return ($parts['scheme'] ?? '') === 'https' && in_array($parts['host'] ?? '', self::ICON_HOSTS, true) ? $url : null;
    }

    /**
     * A manifest.json from a plugin folder.
     *
     * @return array{name: string, version: string, description: string, website: ?string}|null
     */
    public static function manifest(string $contents): ?array
    {
        $json = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents, true);
        if (! is_array($json) || ! is_string($json['name'] ?? null)) {
            return null;
        }
        $version = is_string($json['version_number'] ?? null) && self::validVersion($json['version_number']) ? $json['version_number'] : '';
        $website = is_string($json['website_url'] ?? null) && preg_match('#^https?://#i', $json['website_url']) === 1 ? mb_substr($json['website_url'], 0, 300) : null;

        return [
            'name' => mb_substr(Valheim::text($json['name'], 100) ?? '', 0, 100),
            'version' => $version,
            'description' => mb_substr((string) Valheim::text(is_string($json['description'] ?? null) ? $json['description'] : '', 300), 0, 300),
            'website' => $website,
        ];
    }
}
