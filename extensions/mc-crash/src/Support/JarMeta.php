<?php

namespace McCrash\Support;

/**
 * Reads the descriptor of a plugin or mod: the small text file every loader requires inside the jar.
 * No YAML or TOML parser is needed, only the few top-level keys that name the plugin and its main class.
 *
 * Result: {loader, ids, names, version, mains, api, depends}, or null when the text is not a usable descriptor.
 */
final class JarMeta
{
    /** Descriptor file names inside a jar, in the order they are tried. */
    public const ENTRIES = [
        'paper-plugin.yml' => 'paper',
        'plugin.yml' => 'bukkit',
        'bungee.yml' => 'bungee',
        'velocity-plugin.json' => 'velocity',
        'fabric.mod.json' => 'fabric',
        'quilt.mod.json' => 'quilt',
        'META-INF/neoforge.mods.toml' => 'neoforge',
        'META-INF/mods.toml' => 'forge',
        'META-INF/sponge_plugins.json' => 'sponge',
        'mcmod.info' => 'forge',
    ];

    /** A descriptor bigger than this is not a real one. */
    public const MAX_BYTES = 512 * 1024;

    /**
     * @return array<string, mixed>|null
     */
    public static function parse(string $entry, string $content): ?array
    {
        $loader = self::ENTRIES[$entry] ?? null;
        if ($loader === null || strlen($content) > self::MAX_BYTES) {
            return null;
        }
        $content = Text::clean($content);

        $meta = match ($loader) {
            'paper', 'bukkit', 'bungee' => self::yaml($content, $loader),
            'velocity' => self::velocity($content),
            'fabric' => self::fabric($content),
            'quilt' => self::quilt($content),
            'neoforge' => self::toml($content, 'neoforge'),
            'sponge' => self::sponge($content),
            default => $entry === 'mcmod.info' ? self::mcmod($content) : self::toml($content, 'forge'),
        };

        if ($meta === null || ($meta['ids'] === [] && $meta['names'] === [] && $meta['mains'] === [])) {
            return null;
        }

        return $meta;
    }

    /** @return array<string, mixed> */
    private static function blank(string $loader): array
    {
        return ['loader' => $loader, 'ids' => [], 'names' => [], 'version' => null, 'mains' => [], 'api' => null, 'depends' => []];
    }

    /** @return array<string, mixed> */
    private static function yaml(string $content, string $loader): array
    {
        $meta = self::blank($loader);
        $scalar = fn (string $key): ?string => preg_match('/^'.preg_quote($key, '/').':[ \t]*(?:"([^"\n]*)"|\'([^\'\n]*)\'|([^\s#][^\n#]*?))[ \t]*(?:#.*)?$/m', $content, $m) === 1
            ? trim(($m[1] ?? '') !== '' ? $m[1] : (($m[2] ?? '') !== '' ? $m[2] : ($m[3] ?? '')))
            : null;

        $name = $scalar('name');
        if ($name !== null && $name !== '') {
            $meta['names'][] = $name;
            $meta['ids'][] = strtolower($name);
        }
        $main = $scalar('main');
        if ($main !== null && preg_match('/^[\w$]+(\.[\w$]+)*$/', $main) === 1) {
            $meta['mains'][] = $main;
        }
        $meta['version'] = $scalar('version');
        $meta['api'] = $scalar('api-version');
        $meta['depends'] = self::yamlList($content);

        return $meta;
    }

    /**
     * Plugin names in a top-level "depend" list. Paper's nested "dependencies:" blocks are left out.
     *
     * @return list<string>
     */
    private static function yamlList(string $content): array
    {
        $names = [];
        if (preg_match('/^depend:[ \t]*\[([^\]\n]*)\]/m', $content, $m) === 1) {
            foreach (explode(',', $m[1]) as $item) {
                $item = trim($item, " \t'\"");
                if ($item !== '') {
                    $names[] = $item;
                }
            }
        } elseif (preg_match('/^depend:[ \t]*\n((?:[ \t]*-[ \t]*[^\n]*\n?)+)/m', $content, $m) === 1) {
            foreach (explode("\n", $m[1]) as $item) {
                $item = trim(ltrim(trim($item), '-'), " \t'\"");
                if ($item !== '') {
                    $names[] = $item;
                }
            }
        }

        return array_slice(array_values(array_unique($names)), 0, 50);
    }

    /** @return array<string, mixed>|null */
    private static function fabric(string $content): ?array
    {
        $data = json_decode($content, true);
        if (! is_array($data)) {
            return null;
        }

        $meta = self::blank('fabric');
        self::addId($meta, $data['id'] ?? null, $data['name'] ?? null);
        $meta['version'] = is_string($data['version'] ?? null) ? $data['version'] : null;
        $entrypoints = is_array($data['entrypoints'] ?? null) ? $data['entrypoints'] : [];
        foreach ($entrypoints as $list) {
            foreach (is_array($list) ? $list : [] as $entry) {
                $value = is_array($entry) ? ($entry['value'] ?? null) : $entry;
                if (is_string($value)) {
                    $value = explode('::', $value, 2)[0];
                    if (preg_match('/^[\w$]+(\.[\w$]+)*$/', $value) === 1) {
                        $meta['mains'][] = $value;
                    }
                }
            }
        }
        $meta['mains'] = array_slice(array_values(array_unique($meta['mains'])), 0, 20);
        $meta['depends'] = is_array($data['depends'] ?? null) ? array_slice(array_values(array_filter(array_keys($data['depends']), 'is_string')), 0, 50) : [];

        return $meta;
    }

    /** @return array<string, mixed>|null */
    private static function quilt(string $content): ?array
    {
        $data = json_decode($content, true);
        $loader = is_array($data) && is_array($data['quilt_loader'] ?? null) ? $data['quilt_loader'] : null;
        if ($loader === null) {
            return null;
        }

        $meta = self::blank('quilt');
        $name = is_array($loader['metadata'] ?? null) ? ($loader['metadata']['name'] ?? null) : null;
        self::addId($meta, $loader['id'] ?? null, $name);
        $meta['version'] = is_string($loader['version'] ?? null) ? $loader['version'] : null;
        foreach (is_array($loader['entrypoints'] ?? null) ? $loader['entrypoints'] : [] as $list) {
            foreach (is_array($list) ? (array_is_list($list) ? $list : [$list]) : [$list] as $entry) {
                $value = is_array($entry) ? ($entry['value'] ?? null) : $entry;
                if (is_string($value) && preg_match('/^[\w$]+(\.[\w$]+)*$/', $value) === 1) {
                    $meta['mains'][] = $value;
                }
            }
        }
        $meta['mains'] = array_slice(array_values(array_unique($meta['mains'])), 0, 20);

        return $meta;
    }

    /** @return array<string, mixed>|null */
    private static function velocity(string $content): ?array
    {
        $data = json_decode($content, true);
        if (! is_array($data)) {
            return null;
        }

        $meta = self::blank('velocity');
        self::addId($meta, $data['id'] ?? null, $data['name'] ?? null);
        $meta['version'] = is_string($data['version'] ?? null) ? $data['version'] : null;
        if (is_string($data['main'] ?? null) && preg_match('/^[\w$]+(\.[\w$]+)*$/', $data['main']) === 1) {
            $meta['mains'][] = $data['main'];
        }

        return $meta;
    }

    /** @return array<string, mixed>|null */
    private static function sponge(string $content): ?array
    {
        $data = json_decode($content, true);
        if (! is_array($data) || ! is_array($data['plugins'] ?? null)) {
            return null;
        }

        $meta = self::blank('sponge');
        foreach (array_slice($data['plugins'], 0, 20) as $plugin) {
            if (! is_array($plugin)) {
                continue;
            }
            self::addId($meta, $plugin['id'] ?? null, $plugin['name'] ?? null);
            if (is_string($plugin['entrypoint'] ?? null) && preg_match('/^[\w$]+(\.[\w$]+)*$/', $plugin['entrypoint']) === 1) {
                $meta['mains'][] = $plugin['entrypoint'];
            }
            $meta['version'] ??= is_string($plugin['version'] ?? null) ? $plugin['version'] : null;
        }

        return $meta;
    }

    /** @return array<string, mixed>|null */
    private static function mcmod(string $content): ?array
    {
        $data = json_decode($content, true);
        if (! is_array($data)) {
            return null;
        }
        $list = array_is_list($data) ? $data : (is_array($data['modList'] ?? null) ? $data['modList'] : []);

        $meta = self::blank('forge');
        foreach (array_slice($list, 0, 20) as $mod) {
            if (is_array($mod)) {
                self::addId($meta, $mod['modid'] ?? null, $mod['name'] ?? null);
                $meta['version'] ??= is_string($mod['version'] ?? null) ? $mod['version'] : null;
            }
        }

        return $meta;
    }

    /** @return array<string, mixed> */
    private static function toml(string $content, string $loader): array
    {
        $meta = self::blank($loader);
        // Only [[mods]] tables name mods; [[dependencies.x]] tables use "modId" for the dependency.
        $tables = preg_split('/^\s*\[\[/m', $content) ?: [];
        foreach ($tables as $table) {
            if (preg_match('/^mods\]\]/', $table) !== 1) {
                continue;
            }
            $id = preg_match('/^\s*modId\s*=\s*["\']([^"\']+)["\']/m', $table, $m) === 1 ? $m[1] : null;
            $name = preg_match('/^\s*displayName\s*=\s*["\']([^"\']+)["\']/m', $table, $n) === 1 ? $n[1] : null;
            self::addId($meta, $id, $name);
            if ($meta['version'] === null && preg_match('/^\s*version\s*=\s*["\']([^"\']+)["\']/m', $table, $v) === 1) {
                $meta['version'] = $v[1];
            }
        }
        foreach (array_slice(preg_split('/^\s*\[\[dependencies\./m', $content) ?: [], 1, 60) as $table) {
            if (preg_match('/^\s*modId\s*=\s*["\']([^"\']+)["\']/m', $table, $m) === 1 && ! in_array($m[1], ['minecraft', 'forge', 'neoforge'], true)) {
                $meta['depends'][] = $m[1];
            }
        }
        $meta['depends'] = array_values(array_unique($meta['depends']));
        $meta['ids'] = array_slice($meta['ids'], 0, 30);
        $meta['names'] = array_slice($meta['names'], 0, 30);

        return $meta;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private static function addId(array &$meta, mixed $id, mixed $name): void
    {
        if (is_string($id) && $id !== '' && strlen($id) <= 100) {
            $meta['ids'][] = strtolower($id);
        }
        if (is_string($name) && $name !== '' && strlen($name) <= 120) {
            $meta['names'][] = $name;
        }
    }
}
