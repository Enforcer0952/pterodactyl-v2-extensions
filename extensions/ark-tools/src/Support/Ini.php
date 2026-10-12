<?php

namespace ArkTools\Support;

/**
 * A lossless editor for ARK's Unreal-style INI files.
 *
 * Unlike parse_ini_string() it keeps everything ARK and mods rely on: line order,
 * comments, blank lines, repeated keys (ConfigOverride... lines), keys outside any
 * section, mixed line endings and a byte order mark. A file that is parsed and
 * rendered without edits comes back byte for byte. Section and key names match
 * case-insensitively, as Unreal reads them; edited keys keep the spelling already
 * in the file.
 */
final class Ini
{
    private const string BOM = "\xEF\xBB\xBF";

    private string $bom = '';

    /** @var list<array{text: string, eol: string, kind: 'section'|'kv'|'other', section: string, key?: string, value?: string}> */
    private array $lines = [];

    /** The line ending used for lines this class adds. */
    private string $eol = "\n";

    public static function parse(string $contents): self
    {
        $ini = new self;

        if (str_starts_with($contents, self::BOM)) {
            $ini->bom = self::BOM;
            $contents = substr($contents, 3);
        }

        if (str_contains($contents, "\r\n")) {
            $ini->eol = "\r\n";
        }

        if ($contents === '') {
            return $ini;
        }

        $parts = preg_split('/(\r\n|\n|\r)/', $contents, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$contents];
        $section = '';
        for ($i = 0, $count = count($parts); $i < $count; $i += 2) {
            $text = $parts[$i];
            $eol = $parts[$i + 1] ?? '';

            // The final element after a trailing newline is empty: it is no line at all.
            if ($text === '' && $eol === '' && $i === $count - 1) {
                break;
            }

            $line = self::classify($text, $section);
            if ($line['kind'] === 'section') {
                $section = $line['section'];
            }
            $line['eol'] = $eol;
            $ini->lines[] = $line;
        }

        return $ini;
    }

    public function render(): string
    {
        $out = $this->bom;
        foreach ($this->lines as $line) {
            $out .= $line['text'].$line['eol'];
        }

        return $out;
    }

    /**
     * Every key/value line in file order.
     *
     * @return list<array{section: string, key: string, value: string, line: int}>
     */
    public function entries(): array
    {
        $entries = [];
        foreach ($this->lines as $index => $line) {
            if ($line['kind'] === 'kv') {
                $entries[] = ['section' => $line['section'], 'key' => $line['key'], 'value' => $line['value'], 'line' => $index + 1];
            }
        }

        return $entries;
    }

    /**
     * Section names in file order, as written.
     *
     * @return list<string>
     */
    public function sections(): array
    {
        $names = [];
        foreach ($this->lines as $line) {
            if ($line['kind'] === 'section' && ! self::contains($names, $line['section'])) {
                $names[] = $line['section'];
            }
        }

        return $names;
    }

    public function hasSection(string $section): bool
    {
        return self::contains($this->sections(), $section);
    }

    /** The effective value: Unreal applies lines top to bottom, so the last one wins. */
    public function get(string $section, string $key): ?string
    {
        $values = $this->all($section, $key);

        return $values === [] ? null : $values[array_key_last($values)];
    }

    /**
     * @return list<string>
     */
    public function all(string $section, string $key): array
    {
        $values = [];
        foreach ($this->lines as $line) {
            if ($line['kind'] === 'kv' && self::same($line['section'], $section) && self::same($line['key'], $key)) {
                $values[] = $line['value'];
            }
        }

        return $values;
    }

    /**
     * Set a single-value key. Every existing line for it gets the new value, so a
     * duplicate further down can never quietly win. A missing key is added at the end
     * of its section; a missing section is added at the end of the file.
     */
    public function set(string $section, string $key, string $value): void
    {
        $value = self::clean($value);
        $found = false;
        foreach ($this->lines as $index => $line) {
            if ($line['kind'] === 'kv' && self::same($line['section'], $section) && self::same($line['key'], $key)) {
                $this->lines[$index]['value'] = $value;
                $this->lines[$index]['text'] = $line['key'].'='.$value;
                $found = true;
            }
        }
        if ($found) {
            return;
        }

        $new = ['text' => $key.'='.$value, 'eol' => $this->eol, 'kind' => 'kv', 'section' => $section, 'key' => $key, 'value' => $value];

        $at = $this->endOfSection($section);
        if ($at !== null) {
            // The line before keeps or gains a line ending, so the new line starts on its own.
            if ($at > 0 && $this->lines[$at - 1]['eol'] === '') {
                $this->lines[$at - 1]['eol'] = $this->eol;
            }
            $new['section'] = $this->lines[$at - 1]['section'];
            array_splice($this->lines, $at, 0, [$new]);

            return;
        }

        $last = array_key_last($this->lines);
        if ($last !== null) {
            if ($this->lines[$last]['eol'] === '') {
                $this->lines[$last]['eol'] = $this->eol;
            }
            if (trim($this->lines[$last]['text']) !== '') {
                $this->lines[] = ['text' => '', 'eol' => $this->eol, 'kind' => 'other', 'section' => $this->lines[$last]['section']];
            }
        }
        $this->lines[] = ['text' => '['.$section.']', 'eol' => $this->eol, 'kind' => 'section', 'section' => $section];
        $this->lines[] = $new;
    }

    /** Remove every line of a key. Returns whether anything was removed. */
    public function remove(string $section, string $key): bool
    {
        $before = count($this->lines);
        $this->lines = array_values(array_filter(
            $this->lines,
            fn (array $line): bool => ! ($line['kind'] === 'kv' && self::same($line['section'], $section) && self::same($line['key'], $key)),
        ));

        return count($this->lines) !== $before;
    }

    /** Index after the last non-blank line of the first section with this name. */
    private function endOfSection(string $section): ?int
    {
        $start = null;
        foreach ($this->lines as $index => $line) {
            if ($line['kind'] === 'section' && self::same($line['section'], $section)) {
                $start = $index;
                break;
            }
        }
        if ($start === null) {
            return null;
        }

        $end = $start + 1;
        for ($index = $start + 1, $count = count($this->lines); $index < $count; $index++) {
            if ($this->lines[$index]['kind'] === 'section') {
                break;
            }
            if (trim($this->lines[$index]['text']) !== '') {
                $end = $index + 1;
            }
        }

        return $end;
    }

    /**
     * @return array{text: string, eol: string, kind: 'section'|'kv'|'other', section: string, key?: string, value?: string}
     */
    private static function classify(string $text, string $section): array
    {
        if (preg_match('/^\s*\[([^\]]+)\]\s*$/', $text, $match) === 1) {
            return ['text' => $text, 'eol' => '', 'kind' => 'section', 'section' => trim($match[1])];
        }

        if (preg_match('/^\s*([^;#=\s][^=]*?)\s*=(.*)$/', $text, $match) === 1) {
            return ['text' => $text, 'eol' => '', 'kind' => 'kv', 'section' => $section, 'key' => $match[1], 'value' => $match[2]];
        }

        return ['text' => $text, 'eol' => '', 'kind' => 'other', 'section' => $section];
    }

    /** One line: no line breaks can sneak a second key into the file. */
    private static function clean(string $value): string
    {
        return trim(str_replace(["\r", "\n"], ' ', $value));
    }

    public static function same(string $a, string $b): bool
    {
        return strcasecmp(trim($a), trim($b)) === 0;
    }

    /**
     * @param  list<string>  $names
     */
    private static function contains(array $names, string $name): bool
    {
        foreach ($names as $existing) {
            if (self::same($existing, $name)) {
                return true;
            }
        }

        return false;
    }
}
