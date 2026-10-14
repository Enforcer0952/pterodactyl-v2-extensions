<?php

namespace McCrash\Support;

/**
 * Maps what a crash mentions (a class, a jar name, a plugin name, a mod id) to the installed jars.
 *
 * Every answer says how sure it is:
 *  - "confirmed": the class lives in exactly one jar, or the server named the plugin or jar itself.
 *  - "likely": a guess, such as several jars sharing a package or a package name that looks like a plugin name.
 * A guess is never reported as a fact.
 */
final class JarMap
{
    /** Package words too common to tell plugins apart. */
    private const GENERIC = [
        'com', 'org', 'net', 'dev', 'io', 'me', 'de', 'nl', 'xyz', 'plugin', 'plugins', 'bukkit', 'spigot', 'paper', 'minecraft', 'mod', 'mods',
        'core', 'util', 'utils', 'common', 'api', 'main', 'server', 'client', 'event', 'events', 'listener', 'listeners', 'command', 'commands',
        'impl', 'internal', 'manager', 'managers', 'handler', 'handlers', 'data', 'config', 'platform', 'bukkitapi', 'mixin', 'mixins', 'fabric',
        'forge', 'neoforge', 'quilt', 'world', 'player', 'players', 'entity', 'item', 'items', 'block', 'blocks', 'network', 'gui', 'storage',
    ];

    /** @var list<array<string, mixed>> */
    private array $jars;

    /** @var array<string, list<int>> package => jar numbers */
    private array $owners = [];

    /** @var list<int> jars whose packages are cut down to prefixes */
    private array $coarse = [];

    /**
     * @param  list<array<string, mixed>>  $jars  results of JarInspector::inspect()
     */
    public function __construct(array $jars)
    {
        $this->jars = array_values($jars);
        foreach ($this->jars as $number => $jar) {
            foreach ((array) ($jar['packages'] ?? []) as $package) {
                $this->owners[(string) $package][] = $number;
            }
            if (! empty($jar['coarse'])) {
                $this->coarse[] = $number;
            }
        }
    }

    public function count(): int
    {
        return count($this->jars);
    }

    /**
     * Which jar holds a class.
     *
     * @return array{tier: string, jars: list<array<string, mixed>>, basis: string}|null
     */
    public function forClass(string $class): ?array
    {
        $package = Frames::package($class);
        if ($package === '') {
            return null;
        }

        $owners = $this->owners[$package] ?? [];
        foreach ($this->coarse as $number) {
            if (! in_array($number, $owners, true) && $this->coarseOwns($this->jars[$number], $package)) {
                $owners[] = $number;
            }
        }

        if (count($owners) === 1) {
            return $this->answer('confirmed', $owners, 'The class '.Text::simpleName($class).' ('.$package.') is inside '.$this->jars[$owners[0]]['file'].'.');
        }

        if (count($owners) > 1) {
            // Several jars carry the same package (often a bundled library). The one whose main class
            // sits in a parent package is the real owner.
            $byMain = array_values(array_filter($owners, fn (int $number): bool => $this->mainOwns($this->jars[$number], $package)));
            if (count($byMain) === 1) {
                return $this->answer('confirmed', $byMain, 'The class '.Text::simpleName($class).' is inside '.$this->jars[$byMain[0]]['file'].', whose main class shares the package.');
            }

            return $this->answer('likely', $owners, 'The package '.$package.' exists in more than one jar.');
        }

        // Jars without a package list (not readable): fall back to where their main class lives.
        $byMain = [];
        foreach ($this->jars as $number => $jar) {
            if (($jar['packages'] ?? []) === [] && $this->mainOwns($jar, $package)) {
                $byMain[] = $number;
            }
        }
        if ($byMain !== []) {
            return $this->answer('likely', $byMain, 'The package '.$package.' is the same as the main class package of the jar.');
        }

        $guess = $this->byPackageWords($package);

        return $guess === [] ? null : $this->answer('likely', $guess, 'The package name '.$package.' looks like the name of the jar. This is a guess.');
    }

    /**
     * A jar the trace itself names ("Foo-1.2.jar//..."). It counts as confirmed even when the jar is gone.
     *
     * @return array{tier: string, jars: list<array<string, mixed>>, basis: string}
     */
    public function forJarFile(string $file): array
    {
        foreach ($this->jars as $number => $jar) {
            if (strcasecmp((string) $jar['file'], $file) === 0) {
                return $this->answer('confirmed', [$number], 'The stack trace names '.$jar['file'].'.');
            }
        }

        return [
            'tier' => 'confirmed',
            'jars' => [['file' => $file, 'name' => self::stem($file), 'id' => null, 'version' => null, 'kind' => null, 'installed' => false]],
            'basis' => 'The stack trace names '.$file.', which is not in the plugins or mods folder any more.',
        ];
    }

    /**
     * A plugin or mod the server named by its name or id.
     *
     * @return array{tier: string, jars: list<array<string, mixed>>, basis: string}|null
     */
    public function forName(string $name, string $kind = ''): ?array
    {
        $wanted = self::token($name);
        if ($wanted === '') {
            return null;
        }

        $found = [];
        foreach ($this->jars as $number => $jar) {
            if ($kind !== '' && ($jar['kind'] ?? '') !== $kind) {
                continue;
            }
            foreach ([...(array) ($jar['ids'] ?? []), ...(array) ($jar['names'] ?? [])] as $label) {
                if (self::token((string) $label) === $wanted) {
                    $found[] = $number;

                    break;
                }
            }
        }

        if ($found === []) {
            return null;
        }

        return count($found) === 1
            ? $this->answer('confirmed', $found, 'The server names '.$name.', which is '.$this->jars[$found[0]]['file'].'.')
            : $this->answer('likely', $found, 'More than one jar is called '.$name.'.');
    }

    /**
     * Jars listed as the dependency of the jar at hand do not matter here; this finds jars by a name fragment.
     *
     * @return list<int>
     */
    private function byPackageWords(string $package): array
    {
        $words = [];
        foreach (explode('.', strtolower($package)) as $word) {
            $word = (string) preg_replace('/[^a-z0-9]/', '', $word);
            if (strlen($word) >= 4 && ! in_array($word, self::GENERIC, true)) {
                $words[] = $word;
            }
        }
        if ($words === []) {
            return [];
        }

        $found = [];
        foreach ($this->jars as $number => $jar) {
            $labels = array_filter(array_map(fn ($label): string => self::token((string) $label), [...(array) ($jar['ids'] ?? []), ...(array) ($jar['names'] ?? []), self::stem((string) $jar['file'])]), fn (string $t): bool => strlen($t) >= 4);
            foreach ($labels as $label) {
                foreach ($words as $word) {
                    if ($label === $word || (strlen($word) >= 5 && strlen($label) >= 5 && (str_contains($label, $word) || str_contains($word, $label)))) {
                        $found[] = $number;

                        continue 3;
                    }
                }
            }
        }

        return array_slice($found, 0, 5);
    }

    /** @param array<string, mixed> $jar */
    private function coarseOwns(array $jar, string $package): bool
    {
        foreach ((array) $jar['packages'] as $prefix) {
            if ($package === $prefix || str_starts_with($package, $prefix.'.')) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $jar */
    private function mainOwns(array $jar, string $package): bool
    {
        foreach ((array) ($jar['mains'] ?? []) as $main) {
            $mainPackage = Frames::package((string) $main);
            // Two segments or more, so "com" or "org.example" alone cannot claim a whole company.
            if (substr_count($mainPackage, '.') >= 1 && ($package === $mainPackage || str_starts_with($package, $mainPackage.'.'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<int>  $numbers
     * @return array{tier: string, jars: list<array<string, mixed>>, basis: string}
     */
    private function answer(string $tier, array $numbers, string $basis): array
    {
        return [
            'tier' => $tier,
            'jars' => array_map(fn (int $number): array => self::ref($this->jars[$number]), array_slice($numbers, 0, 5)),
            'basis' => $basis,
        ];
    }

    /**
     * @param  array<string, mixed>  $jar
     * @return array<string, mixed>
     */
    public static function ref(array $jar): array
    {
        return [
            'file' => (string) $jar['file'],
            'name' => (string) (($jar['names'][0] ?? null) ?: self::stem((string) $jar['file'])),
            'id' => $jar['ids'][0] ?? null,
            'version' => $jar['version'] ?? null,
            'kind' => $jar['kind'] ?? null,
            'installed' => true,
        ];
    }

    /** "LuckPerms-Bukkit-5.4.102.jar" gives "LuckPerms-Bukkit". */
    public static function stem(string $file): string
    {
        $name = (string) preg_replace('/\.jar$/i', '', $file);
        $name = (string) preg_replace('/[-_+ ]v?\d+(\.\d+|[-_.+][\w.+]*)*$/', '', $name);

        return $name === '' ? $file : $name;
    }

    /** Lowercase letters and digits only: "Luck Perms", "luck-perms" and "LuckPerms" compare equal. */
    public static function token(string $text): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', strtolower($text));
    }
}
