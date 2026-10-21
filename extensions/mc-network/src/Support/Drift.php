<?php

namespace McNetwork\Support;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards a save against changing what the page does not edit. BungeeCord reads
 * config.yml as YAML 1.1, where a plain yes, no, on, off, y or n is a boolean and a
 * value like 1:30 is a number. The YAML writer used here quotes such values, so they
 * would turn into text and the proxy would read them differently. Both checks refuse
 * the save instead of changing the file silently.
 */
final class Drift
{
    /** YAML 1.1 booleans, in any case. */
    private const string BOOLEANS = '/^(?:y|n|yes|no|on|off)$/iD';

    /** YAML 1.1 base-60 numbers such as 1:30 or 12:00:00.5. */
    private const string SEXAGESIMAL = '/^[-+]?[0-9][0-9_]*(?::[0-5]?[0-9])+(?:\.[0-9_]*)?$/D';

    /** The listener fields the page edits. */
    private const array EDITED_LISTENER_FIELDS = ['priorities', 'forced_hosts'];

    /**
     * Plain values in the parts of the file the page does not edit that YAML 1.1 reads as a
     * boolean or a number. Empty when the file is safe to rewrite.
     *
     * @return list<string> readable descriptions, such as "ip_forward is yes"
     */
    public static function risky(string $yaml): array
    {
        $data = self::parse($yaml);
        if (! $data instanceof \stdClass) {
            return [];
        }

        $found = [];
        foreach (get_object_vars($data) as $key => $value) {
            $key = (string) $key;
            if ($key === 'servers') {
                continue;
            }
            if ($key === 'listeners' && is_array($value)) {
                foreach ($value as $index => $listener) {
                    // The first listener's edited fields are rewritten from the page anyway.
                    self::collect('listeners['.$index.']', $listener, $found, $index === 0 ? self::EDITED_LISTENER_FIELDS : []);
                }

                continue;
            }
            self::collect($key, $value, $found, []);
        }

        $risky = [];
        foreach ($found as [$path, $text]) {
            // The parser does not say whether the value was quoted: look at the file itself.
            if (self::appearsPlain($yaml, $text)) {
                $risky[] = $path.' is '.$text;
            }
        }

        return array_values(array_unique($risky));
    }

    /**
     * Top-level keys (and "listeners[n].key" for listeners) that differ between the file
     * and its rewritten version, apart from the backend list and the edited fields of
     * the first listener. Empty when nothing else changes.
     *
     * @return list<string>
     */
    public static function changes(string $before, string $after): array
    {
        $old = self::parse($before);
        $new = self::parse($after);
        if (! $old instanceof \stdClass || ! $new instanceof \stdClass) {
            return ['config.yml'];
        }

        $changed = [];
        $keys = array_unique(array_merge(array_keys(get_object_vars($old)), array_keys(get_object_vars($new))));
        foreach ($keys as $key) {
            $key = (string) $key;
            if ($key === 'servers') {
                continue;
            }
            $a = $old->{$key} ?? null;
            $b = $new->{$key} ?? null;
            $presence = property_exists($old, $key) === property_exists($new, $key);

            if ($key === 'listeners' && $presence && is_array($a) && is_array($b) && count($a) === count($b)) {
                foreach ($a as $index => $listener) {
                    if (self::same($listener, $b[$index], $index === 0 ? self::EDITED_LISTENER_FIELDS : [])) {
                        continue;
                    }
                    $changed[] = 'listeners['.$index.']';
                }

                continue;
            }
            if (! $presence || ! self::same($a, $b, [])) {
                $changed[] = $key;
            }
        }

        return $changed;
    }

    /**
     * @param  list<array{0: string, 1: string}>  $found
     * @param  list<string>  $skip  property names to leave out at this level
     */
    private static function collect(string $path, mixed $value, array &$found, array $skip): void
    {
        if (is_string($value)) {
            if (self::looksLikeOtherType($value)) {
                $found[] = [$path, $value];
            }

            return;
        }

        if (is_array($value)) {
            foreach ($value as $index => $item) {
                self::collect($path.'['.$index.']', $item, $found, []);
            }

            return;
        }

        if ($value instanceof \stdClass) {
            foreach (get_object_vars($value) as $key => $item) {
                $key = (string) $key;
                if (in_array($key, $skip, true)) {
                    continue;
                }
                if (self::looksLikeOtherType($key)) {
                    $found[] = [$path.'.'.$key.' (a key)', $key];
                }
                self::collect($path.'.'.$key, $item, $found, []);
            }
        }
    }

    private static function looksLikeOtherType(string $text): bool
    {
        return preg_match(self::BOOLEANS, $text) === 1 || preg_match(self::SEXAGESIMAL, $text) === 1;
    }

    /** Whether the text shows $value somewhere as a plain (unquoted) scalar. */
    private static function appearsPlain(string $yaml, string $value): bool
    {
        $lines = array_filter(preg_split('/\R/', $yaml) ?: [], fn (string $line): bool => ! str_starts_with(ltrim($line), '#'));
        $pattern = '/(?:^|[\s\[{,])'.preg_quote($value, '/').'\s*(?:#.*)?(?:[\]},:]|$)/iD';
        foreach ($lines as $line) {
            if (preg_match($pattern, $line) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Strict deep comparison: 1 and 1.0 and "1" differ. Objects compare by their properties.
     *
     * @param  list<string>  $ignore  property names to leave out at the top of $a and $b
     */
    private static function same(mixed $a, mixed $b, array $ignore): bool
    {
        if ($a instanceof \stdClass && $b instanceof \stdClass) {
            $a = array_diff_key(get_object_vars($a), array_flip($ignore));
            $b = array_diff_key(get_object_vars($b), array_flip($ignore));
            // The order of a map's keys carries no meaning.
            ksort($a);
            ksort($b);
        }
        if (is_array($a) && is_array($b)) {
            if (array_keys($a) !== array_keys($b)) {
                return false;
            }
            foreach ($a as $key => $item) {
                if (! self::same($item, $b[$key], [])) {
                    return false;
                }
            }

            return true;
        }

        return $a === $b;
    }

    private static function parse(string $yaml): mixed
    {
        try {
            return Yaml::parse($yaml, Yaml::PARSE_OBJECT_FOR_MAP);
        } catch (ParseException) {
            return null;
        }
    }
}
