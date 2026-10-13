<?php

namespace McWorld\Support;

/**
 * A Java .properties file (server.properties) that is edited without being rewritten.
 * Every line that is not changed keeps its exact bytes: comments, blank lines, order,
 * unknown keys, separators and the file's line endings. A changed value is written the
 * way Java's Properties.store writes it: backslash, colon, equals, hash and bang are
 * escaped, and anything outside printable ASCII becomes a \uXXXX escape.
 */
final class Properties
{
    /**
     * @param  list<array{raw: string, key: ?string, value: string, prefix: ?string, eol: string}>  $lines
     */
    private function __construct(private readonly array $lines) {}

    public static function parse(string $text): self
    {
        preg_match_all('/[^\r\n]*(?:\r\n|\r|\n)|[^\r\n]+$/', $text, $matches);
        $pieces = $matches[0];
        $lines = [];

        for ($i = 0, $count = count($pieces); $i < $count; $i++) {
            $raw = $pieces[$i];
            [$content, $eol] = self::splitEol($raw);
            $trimmed = ltrim($content, " \t\f");

            if ($trimmed === '' || $trimmed[0] === '#' || $trimmed[0] === '!') {
                $lines[] = ['raw' => $raw, 'key' => null, 'value' => '', 'prefix' => null, 'eol' => $eol];

                continue;
            }

            // A line ending in an odd number of backslashes continues on the next one.
            $logical = $content;
            $single = true;
            while (self::continues($content) && $i + 1 < $count) {
                $i++;
                [$content, $eol] = self::splitEol($pieces[$i]);
                $raw .= $pieces[$i];
                $logical = substr($logical, 0, -1).ltrim($content, " \t\f");
                $single = false;
            }
            if (self::continues($content)) {
                $logical = substr($logical, 0, -1);
            }

            [$key, $valueAt] = self::split($logical);
            $lines[] = [
                'raw' => $raw,
                'key' => self::unescape($key),
                'value' => self::unescape(substr($logical, $valueAt)),
                'prefix' => $single ? substr($logical, 0, $valueAt) : null,
                'eol' => $eol,
            ];
        }

        return new self($lines);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    /** The value of a key; when a key is listed twice, the last one counts, as in Java. */
    public function get(string $key): ?string
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * @return array<string, string> key => decoded value, in file order
     */
    public function all(): array
    {
        $all = [];
        foreach ($this->lines as $line) {
            if ($line['key'] !== null) {
                $all[$line['key']] = $line['value'];
            }
        }

        return $all;
    }

    /**
     * A copy with the given keys changed. A string sets the key (appending it when it is
     * new), null removes it. A key that already has that value is left untouched.
     *
     * @param  array<string, ?string>  $changes
     */
    public function with(array $changes): self
    {
        $lines = $this->lines;
        $eol = $this->eol();

        foreach ($changes as $key => $value) {
            $key = (string) $key;
            $indexes = [];
            foreach ($lines as $index => $line) {
                if ($line['key'] === $key) {
                    $indexes[] = $index;
                }
            }

            if ($value === null) {
                foreach (array_reverse($indexes) as $index) {
                    array_splice($lines, $index, 1);
                }

                continue;
            }

            if ($indexes === []) {
                $last = count($lines) - 1;
                if ($last >= 0 && $lines[$last]['eol'] === '') {
                    $lines[$last]['raw'] .= $eol;
                    $lines[$last]['eol'] = $eol;
                }
                $prefix = self::escape($key, true).'=';
                $lines[] = ['raw' => $prefix.self::escape($value, false).$eol, 'key' => $key, 'value' => $value, 'prefix' => $prefix, 'eol' => $eol];

                continue;
            }

            $target = end($indexes);
            if ($lines[$target]['value'] === $value) {
                continue;
            }
            $prefix = $lines[$target]['prefix'] ?? self::escape($key, true).'=';
            $lines[$target] = [
                'raw' => $prefix.self::escape($value, false).$lines[$target]['eol'],
                'key' => $key,
                'value' => $value,
                'prefix' => $prefix,
                'eol' => $lines[$target]['eol'],
            ];
        }

        return new self(array_values($lines));
    }

    public function toString(): string
    {
        return implode('', array_column($this->lines, 'raw'));
    }

    /** The line ending the file uses (the first one found), or "\n" for an empty file. */
    public function eol(): string
    {
        foreach ($this->lines as $line) {
            if ($line['eol'] !== '') {
                return $line['eol'];
            }
        }

        return "\n";
    }

    /** Encode a key or value for a .properties file. */
    public static function escape(string $text, bool $isKey): string
    {
        $out = '';
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($chars as $position => $char) {
            $code = mb_ord($char, 'UTF-8');
            if ($char === ' ') {
                $out .= $position === 0 || $isKey ? '\\ ' : ' ';
            } elseif ($char === "\t") {
                $out .= '\\t';
            } elseif ($char === "\n") {
                $out .= '\\n';
            } elseif ($char === "\r") {
                $out .= '\\r';
            } elseif ($char === "\f") {
                $out .= '\\f';
            } elseif (in_array($char, ['\\', '=', ':', '#', '!'], true)) {
                $out .= '\\'.$char;
            } elseif ($code < 0x20 || $code > 0x7E) {
                if ($code > 0xFFFF) {
                    $code -= 0x10000;
                    $out .= sprintf('\\u%04X\\u%04X', 0xD800 + ($code >> 10), 0xDC00 + ($code & 0x3FF));
                } else {
                    $out .= sprintf('\\u%04X', $code);
                }
            } else {
                $out .= $char;
            }
        }

        return $out;
    }

    /** Decode the escapes of a key or value. */
    public static function unescape(string $text): string
    {
        $out = '';
        $length = strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];
            if ($char !== '\\') {
                $out .= $char;

                continue;
            }
            $i++;
            if ($i >= $length) {
                break;
            }
            $next = $text[$i];
            if ($next === 'u') {
                $unit = self::unit($text, $i + 1);
                if ($unit === null) {
                    // Not a valid escape: keep it as it is rather than lose data.
                    $out .= '\\u';

                    continue;
                }
                $i += 4;
                if ($unit >= 0xD800 && $unit <= 0xDBFF) {
                    $low = substr($text, $i + 1, 2) === '\\u' ? self::unit($text, $i + 3) : null;
                    if ($low !== null && $low >= 0xDC00 && $low <= 0xDFFF) {
                        $i += 6;
                        $out .= mb_chr(0x10000 + (($unit - 0xD800) << 10) + ($low - 0xDC00), 'UTF-8');
                    } else {
                        $out .= "\u{FFFD}";
                    }
                } elseif ($unit >= 0xDC00 && $unit <= 0xDFFF) {
                    $out .= "\u{FFFD}";
                } else {
                    $out .= mb_chr($unit, 'UTF-8');
                }

                continue;
            }
            $out .= match ($next) {
                't' => "\t",
                'n' => "\n",
                'r' => "\r",
                'f' => "\f",
                default => $next,
            };
        }

        return $out;
    }

    private static function unit(string $text, int $at): ?int
    {
        $hex = substr($text, $at, 4);

        return preg_match('/^[0-9A-Fa-f]{4}$/D', $hex) === 1 ? (int) hexdec($hex) : null;
    }

    /**
     * @return array{0: string, 1: int} the raw key and the offset where the raw value starts
     */
    private static function split(string $logical): array
    {
        $length = strlen($logical);
        $i = strspn($logical, " \t\f");
        $start = $i;
        while ($i < $length) {
            $char = $logical[$i];
            if ($char === '\\') {
                $i += 2;

                continue;
            }
            if ($char === '=' || $char === ':' || $char === ' ' || $char === "\t" || $char === "\f") {
                break;
            }
            $i++;
        }
        $i = min($i, $length);
        $key = substr($logical, $start, $i - $start);
        $i += strspn($logical, " \t\f", $i);
        if ($i < $length && ($logical[$i] === '=' || $logical[$i] === ':')) {
            $i++;
            $i += strspn($logical, " \t\f", $i);
        }

        return [$key, min($i, $length)];
    }

    private static function continues(string $content): bool
    {
        return (strlen($content) - strlen(rtrim($content, '\\'))) % 2 === 1;
    }

    /**
     * @return array{0: string, 1: string} the text without its line ending, and the line ending
     */
    private static function splitEol(string $raw): array
    {
        if (str_ends_with($raw, "\r\n")) {
            return [substr($raw, 0, -2), "\r\n"];
        }
        if (str_ends_with($raw, "\n") || str_ends_with($raw, "\r")) {
            return [substr($raw, 0, -1), substr($raw, -1)];
        }

        return [$raw, ''];
    }
}
