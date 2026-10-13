<?php

namespace McVersion\Support;

/**
 * Works out what a Minecraft server runs from the files it leaves behind, without
 * trusting its egg or startup variables. The log of the last start goes first, then
 * Paper's version_history.json, then the newest folder under versions/.
 */
final class Detection
{
    private const string VERSION = '\d+\.\d+(?:\.\d+)?';

    /**
     * @param  array{
     *     root?: list<string>,
     *     libraries?: list<string>,
     *     config?: list<string>,
     *     versions?: list<array{name: string, modified: int}>,
     *     log?: ?string,
     *     history?: ?string,
     * }  $facts  names in the server root, in libraries/net and in config, entries of
     *            versions/ with their modification time, the text of logs/latest.log and
     *            of version_history.json (null when absent)
     * @return array{software: ?string, version: ?string, build: ?string, source: ?string}
     */
    public static function analyse(array $facts): array
    {
        $root = $facts['root'] ?? [];
        $log = $facts['log'] ?? null;
        $fromLog = $log === null ? [] : self::fromLog($log);
        $fromHistory = self::fromHistory($facts['history'] ?? null);
        $fromFiles = self::fromFiles($root, $facts['libraries'] ?? [], $facts['config'] ?? []);

        // The log names the software that really ran; the files can hold leftovers of
        // a previous one (a .fabric folder stays behind after a switch to Paper).
        $software = $fromLog['software'] ?? null;
        if ($software === null) {
            $software = $fromHistory['software'] ?? $fromFiles;
            // A log that names a version but no software comes from a vanilla jar or a
            // mod loader that logs differently. Paper, Purpur and Fabric always say so.
            if (($fromLog['version'] ?? null) !== null && ! ($fromLog['named'] ?? false) && ! in_array($fromFiles, ['forge', 'neoforge'], true) && self::looksVanilla($root)) {
                $software = 'vanilla';
            }
        }

        $version = null;
        $source = null;
        foreach ([
            ['log', $fromLog['version'] ?? null],
            ['history', $fromHistory['version'] ?? null],
            ['folder', self::fromFolder($facts['versions'] ?? [])],
        ] as [$name, $found]) {
            if ($found !== null) {
                $version = $found;
                $source = $name;
                break;
            }
        }

        if ($software === null && $version !== null && self::looksVanilla($root)) {
            $software = 'vanilla';
        }

        return [
            'software' => $software,
            'version' => $version,
            'build' => $fromLog['build'] ?? $fromHistory['build'] ?? null,
            'source' => $source,
        ];
    }

    /**
     * @return array{software?: string, version?: string, build?: string, named?: bool}
     */
    public static function fromLog(string $log): array
    {
        $found = [];

        // Paper and its forks: "This server is running Paper version git-Paper-794 (MC: 1.16.5) ..."
        // or, on newer builds, "... version 1.21.4-232-main@2d3f4c8 (2025-01-05T...)".
        if (preg_match('/This server is running (\w+) version (\S+)(?: \(MC: ('.self::VERSION.')\))?/', $log, $match) === 1) {
            $found['named'] = true;
            $software = Software::fromName($match[1]);
            if ($software !== null) {
                $found['software'] = $software;
            }
            if (($match[3] ?? '') !== '') {
                $found['version'] = $match[3];
            }
            $build = self::buildFrom($match[2]);
            if ($build !== null) {
                $found['build'] = $build;
            }
        }

        // Fabric and Quilt: "Loading Minecraft 1.21.1 with Fabric Loader 0.16.5".
        if (preg_match('/Loading Minecraft ('.self::VERSION.') with (Fabric|Quilt) Loader/', $log, $match) === 1) {
            $found['named'] = true;
            $found['software'] ??= strtolower($match[2]);
            $found['version'] ??= $match[1];
        }

        // Every Java server logs "Starting minecraft server version 1.21.1".
        if (! isset($found['version']) && preg_match('/Starting minecraft server version ('.self::VERSION.')/i', $log, $match) === 1) {
            $found['version'] = $match[1];
        }

        return $found;
    }

    /**
     * Paper and its forks record "git-Paper-794 (MC: 1.16.5)" in version_history.json.
     *
     * @return array{software?: string, version?: string, build?: string}
     */
    public static function fromHistory(?string $json): array
    {
        $data = $json === null ? null : json_decode($json, true);
        $current = is_array($data) ? ($data['currentVersion'] ?? null) : null;
        if (! is_string($current)) {
            return [];
        }

        $found = [];
        if (preg_match('/\(MC: ('.self::VERSION.')\)/', $current, $match) === 1) {
            $found['version'] = $match[1];
        }
        if (preg_match('/^git-(\w+)-/', $current, $match) === 1 && Software::fromName($match[1]) !== null) {
            $found['software'] = Software::fromName($match[1]);
        }
        $build = self::buildFrom($current);
        if ($build !== null) {
            $found['build'] = $build;
        }

        return $found;
    }

    /**
     * The newest-written folder under versions/ that is named after a version. A server
     * switched from 1.21.10 back to 1.21.1 keeps both folders; the one written last is
     * the one in use. Ties go to the higher version.
     *
     * @param  list<array{name: string, modified: int}>  $entries
     */
    public static function fromFolder(array $entries): ?string
    {
        $versions = [];
        foreach ($entries as $entry) {
            if (preg_match('/^('.self::VERSION.')(?:$|[-_.])/', $entry['name'], $match) === 1) {
                $versions[] = ['version' => $match[1], 'modified' => $entry['modified']];
            }
        }

        if ($versions === []) {
            return null;
        }

        usort($versions, fn (array $a, array $b): int => $a['modified'] <=> $b['modified'] ?: (Versions::compare($a['version'], $b['version']) ?? 0));

        return end($versions)['version'];
    }

    /**
     * @param  list<string>  $root
     * @param  list<string>  $libraries
     * @param  list<string>  $config
     */
    public static function fromFiles(array $root, array $libraries = [], array $config = []): ?string
    {
        $root = array_map('strtolower', $root);
        $libraries = array_map('strtolower', $libraries);
        $config = array_map('strtolower', $config);
        $has = fn (string ...$names): bool => array_intersect($names, $root) !== [];

        return match (true) {
            $has('.quilt', 'quilt-server-launch.jar') => 'quilt',
            $has('.fabric', 'fabric-server-launch.jar', 'fabric-server-launcher.properties') => 'fabric',
            in_array('neoforged', $libraries, true) => 'neoforge',
            in_array('minecraftforge', $libraries, true) => 'forge',
            $has('purpur.yml') => 'purpur',
            $has('pufferfish.yml') => 'pufferfish',
            in_array('paper-global.yml', $config, true), $has('paper.yml', 'version_history.json') => 'paper',
            $has('spigot.yml') => 'spigot',
            $has('bukkit.yml') => 'bukkit',
            default => null,
        };
    }

    /** A server with settings and an EULA but none of the files plugin servers write. */
    private static function looksVanilla(array $root): bool
    {
        $root = array_map('strtolower', $root);

        return in_array('server.properties', $root, true) && in_array('eula.txt', $root, true);
    }

    /** The build number inside "git-Paper-794 (MC: 1.16.5)" or "1.21.4-232-main@2d3f4c8". */
    private static function buildFrom(string $text): ?string
    {
        if (preg_match('/git-\w+-(\d+)/', $text, $match) === 1 || preg_match('/^'.self::VERSION.'-(\d+)(?:-|\b)/', $text, $match) === 1) {
            return $match[1];
        }

        return null;
    }
}
