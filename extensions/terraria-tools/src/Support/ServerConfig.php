<?php

namespace TerrariaTools\Support;

/**
 * A lossless editor for serverconfig.txt, the key=value file the Terraria server
 * reads at start.
 *
 * Comments, blank lines, unknown keys, repeated keys, line endings and a byte order
 * mark are kept: a file that is parsed and rendered without edits comes back byte
 * for byte. Keys match without regard to case, as the server reads them; an edited
 * key keeps the spelling already in the file.
 */
final class ServerConfig
{
    private const string BOM = "\xEF\xBB\xBF";

    private string $bom = '';

    /** @var list<array{text: string, eol: string, key: ?string, value: ?string}> */
    private array $lines = [];

    /** The line ending used for lines this class adds. */
    private string $eol = "\n";

    public static function parse(string $contents): self
    {
        $config = new self;

        if (str_starts_with($contents, self::BOM)) {
            $config->bom = self::BOM;
            $contents = substr($contents, 3);
        }

        if (str_contains($contents, "\r\n")) {
            $config->eol = "\r\n";
        }

        if ($contents === '') {
            return $config;
        }

        $parts = preg_split('/(\r\n|\n|\r)/', $contents, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$contents];
        for ($i = 0, $count = count($parts); $i < $count; $i += 2) {
            $text = $parts[$i];
            $eol = $parts[$i + 1] ?? '';

            // After a trailing newline the last element is empty: it is no line at all.
            if ($text === '' && $eol === '' && $i === $count - 1) {
                break;
            }

            $line = ['text' => $text, 'eol' => $eol, 'key' => null, 'value' => null];
            if (preg_match('/^\s*([^#;=\s][^=]*?)\s*=(.*)$/', $text, $match) === 1) {
                $line['key'] = $match[1];
                $line['value'] = $match[2];
            }
            $config->lines[] = $line;
        }

        return $config;
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
     * Every key=value line in file order.
     *
     * @return list<array{key: string, value: string, line: int}>
     */
    public function entries(): array
    {
        $entries = [];
        foreach ($this->lines as $index => $line) {
            if ($line['key'] !== null) {
                $entries[] = ['key' => $line['key'], 'value' => (string) $line['value'], 'line' => $index + 1];
            }
        }

        return $entries;
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /** The effective value: the server reads top to bottom, so the last line wins. */
    public function get(string $key): ?string
    {
        $found = null;
        foreach ($this->lines as $line) {
            if ($line['key'] !== null && self::same($line['key'], $key)) {
                $found = (string) $line['value'];
            }
        }

        return $found;
    }

    /**
     * Set a key. Every existing line for it gets the new value, so a repeat further
     * down can never quietly win. A missing key is added at the end of the file.
     */
    public function set(string $key, string $value): void
    {
        $value = self::clean($value);
        $found = false;
        foreach ($this->lines as $index => $line) {
            if ($line['key'] !== null && self::same($line['key'], $key)) {
                $this->lines[$index]['value'] = $value;
                $this->lines[$index]['text'] = $line['key'].'='.$value;
                $found = true;
            }
        }
        if ($found) {
            return;
        }

        $last = array_key_last($this->lines);
        if ($last !== null && $this->lines[$last]['eol'] === '') {
            $this->lines[$last]['eol'] = $this->eol;
        }
        $this->lines[] = ['text' => $key.'='.$value, 'eol' => $this->eol, 'key' => $key, 'value' => $value];
    }

    /** Remove every line of a key. Returns whether anything was removed. */
    public function remove(string $key): bool
    {
        $before = count($this->lines);
        $this->lines = array_values(array_filter(
            $this->lines,
            fn (array $line): bool => ! ($line['key'] !== null && self::same($line['key'], $key)),
        ));

        return count($this->lines) !== $before;
    }

    /**
     * The text with the value of the given keys hidden. Used for the password, so it
     * is only sent to people allowed to read it.
     *
     * @param  list<string>  $keys
     */
    public function masked(array $keys, string $mask): string
    {
        $copy = clone $this;
        foreach ($copy->lines as $index => $line) {
            if ($line['key'] === null || ! self::inList($keys, $line['key']) || trim((string) $line['value']) === '') {
                continue;
            }
            $copy->lines[$index]['value'] = $mask;
            $copy->lines[$index]['text'] = $line['key'].'='.$mask;
        }

        return $copy->render();
    }

    /** One line: no line break can sneak a second key into the file. */
    private static function clean(string $value): string
    {
        return trim(preg_replace('/[\p{Cc}\p{Zl}\p{Zp}]+/u', ' ', $value) ?? '');
    }

    public static function same(string $a, string $b): bool
    {
        return strcasecmp(trim($a), trim($b)) === 0;
    }

    /**
     * @param  list<string>  $keys
     */
    private static function inList(array $keys, string $key): bool
    {
        foreach ($keys as $candidate) {
            if (self::same($candidate, $key)) {
                return true;
            }
        }

        return false;
    }
}
