<?php

namespace McCrash\Support;

/** Reads one plugin or mod jar: its descriptor and the packages its classes live in. */
final class JarInspector
{
    /** Zip entries looked at for packages; a jar bigger than this is read for its descriptor only. */
    public const MAX_ENTRIES = 40000;

    /** More packages than this are cut down to their common prefixes. */
    public const MAX_PACKAGES = 300;

    /**
     * @param  string  $zipPath  a local copy of the jar
     * @param  string  $default  "plugin" or "mod": the folder the jar was found in
     * @return array<string, mixed>|null null when the file is not a zip
     */
    public static function inspect(string $zipPath, string $file, int $size, string $default): ?array
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::RDONLY) !== true) {
            return null;
        }

        try {
            $info = [
                'file' => $file,
                'size' => $size,
                'kind' => $default,
                'loader' => 'unknown',
                'ids' => [],
                'names' => [],
                'version' => null,
                'mains' => [],
                'api' => null,
                'depends' => [],
                'packages' => [],
                'coarse' => false,
            ];

            $first = true;
            foreach (array_keys(JarMeta::ENTRIES) as $entry) {
                $stat = $zip->statName($entry);
                if ($stat === false || $stat['size'] > JarMeta::MAX_BYTES) {
                    continue;
                }
                $content = $zip->getFromName($entry);
                $meta = $content === false ? null : JarMeta::parse($entry, $content);
                if ($meta === null) {
                    continue;
                }

                if ($first) {
                    $info['loader'] = $meta['loader'];
                    $info['version'] = $meta['version'];
                    $info['api'] = $meta['api'];
                    $first = false;
                }
                foreach (['ids', 'names', 'mains', 'depends'] as $key) {
                    $info[$key] = array_values(array_unique([...$info[$key], ...$meta[$key]]));
                }
            }

            if (in_array($info['loader'], ['fabric', 'quilt', 'forge', 'neoforge'], true)) {
                $info['kind'] = 'mod';
            } elseif ($info['loader'] !== 'unknown') {
                $info['kind'] = 'plugin';
            }

            if ($zip->numFiles <= self::MAX_ENTRIES) {
                $names = [];
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $name = $zip->getNameIndex($i);
                    if ($name !== false) {
                        $names[] = $name;
                    }
                }
                [$info['packages'], $info['coarse']] = self::packages($names);
            }

            return $info;
        } finally {
            $zip->close();
        }
    }

    /**
     * Packages that hold classes, from the entry names of a jar. Java and bundled library packages
     * are left out, because they would make every jar look alike.
     *
     * @param  list<string>  $entries
     * @return array{0: list<string>, 1: bool} the packages and whether they were cut down to prefixes
     */
    public static function packages(array $entries): array
    {
        $packages = [];
        foreach ($entries as $entry) {
            if (! str_ends_with($entry, '.class') || str_starts_with($entry, 'META-INF/') || ! str_contains($entry, '/')) {
                continue;
            }
            $package = str_replace('/', '.', substr($entry, 0, (int) strrpos($entry, '/')));
            if (Frames::isLibrary($package.'.') || isset($packages[$package])) {
                continue;
            }
            $packages[$package] = true;
        }

        $list = array_keys($packages);
        sort($list);
        if (count($list) <= self::MAX_PACKAGES) {
            return [$list, false];
        }

        for ($depth = 6; $depth >= 2; $depth--) {
            $short = [];
            foreach ($list as $package) {
                $short[implode('.', array_slice(explode('.', $package), 0, $depth))] = true;
            }
            if (count($short) <= self::MAX_PACKAGES || $depth === 2) {
                $short = array_keys($short);
                sort($short);

                return [array_slice($short, 0, self::MAX_PACKAGES), true];
            }
        }

        return [[], true];
    }
}
