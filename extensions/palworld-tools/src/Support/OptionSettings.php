<?php

namespace PalworldTools\Support;

use InvalidArgumentException;

/**
 * The OptionSettings line of PalWorldSettings.ini:
 *
 *   OptionSettings=(Difficulty=None,ExpRate=1.000000,ServerName="My, (server)",...)
 *
 * Palworld keeps every setting in one parenthesised tuple. This class edits single
 * values and leaves everything else exactly as it was: unknown keys, their order,
 * spacing and the text around the line. Quoted strings may contain commas,
 * parentheses and escaped quotes (\"), and values may be nested tuples such as
 * CrossplayPlatforms=(Steam,Xbox,PS5,Mac).
 *
 * Format reference: https://docs.palworldgame.com/settings-and-operation/configuration
 */
final class OptionSettings
{
    /** @param list<string> $parts the raw text between the commas of the tuple */
    private function __construct(
        private readonly string $head,
        private array $parts,
        private readonly string $tail,
    ) {}

    /** Whether the text has an OptionSettings=( line. */
    public static function exists(string $text): bool
    {
        return self::locate($text) !== null;
    }

    /**
     * @throws InvalidArgumentException when there is no OptionSettings tuple or it is not closed
     */
    public static function parse(string $text): self
    {
        $open = self::locate($text);
        if ($open === null) {
            throw new InvalidArgumentException('There is no OptionSettings=(...) line in the file.');
        }

        $length = strlen($text);
        $depth = 0;
        $quoted = false;
        $start = $open + 1;
        $parts = [];
        $buffer = '';
        for ($i = $start; $i < $length; $i++) {
            $char = $text[$i];
            if ($quoted) {
                $buffer .= $char;
                if ($char === '\\' && $i + 1 < $length) {
                    $buffer .= $text[++$i];
                } elseif ($char === '"') {
                    $quoted = false;
                } elseif ($char === "\n") {
                    throw new InvalidArgumentException('A quoted value in OptionSettings is not closed.');
                }

                continue;
            }
            if ($char === '"') {
                $quoted = true;
                $buffer .= $char;
            } elseif ($char === '(') {
                $depth++;
                $buffer .= $char;
            } elseif ($char === ')') {
                if ($depth === 0) {
                    $parts[] = $buffer;

                    return new self(substr($text, 0, $start), $parts === [''] ? [] : $parts, substr($text, $i));
                }
                $depth--;
                $buffer .= $char;
            } elseif ($char === ',' && $depth === 0) {
                $parts[] = $buffer;
                $buffer = '';
            } else {
                $buffer .= $char;
            }
        }

        throw new InvalidArgumentException('OptionSettings is missing its closing bracket.');
    }

    public function render(): string
    {
        return $this->head.implode(',', $this->parts).$this->tail;
    }

    /**
     * @return list<array{key: string, raw: string}> every pair in order (duplicates included)
     */
    public function entries(): array
    {
        $entries = [];
        foreach ($this->parts as $part) {
            $pair = self::split($part);
            if ($pair !== null) {
                $entries[] = ['key' => $pair['key'], 'raw' => $pair['value']];
            }
        }

        return $entries;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_column($this->entries(), 'key');
    }

    public function has(string $key): bool
    {
        return $this->index($key) !== null;
    }

    /** The value as written in the file (last occurrence wins, like the game). */
    public function raw(string $key): ?string
    {
        $index = $this->index($key);

        return $index === null ? null : (self::split($this->parts[$index])['value'] ?? null);
    }

    /** The value without quotes or brackets, as a person would read it. */
    public function get(string $key): ?string
    {
        $raw = $this->raw($key);

        return $raw === null ? null : self::display($raw);
    }

    /**
     * Set a value token (already formatted: see {@see quote()}). The last occurrence is
     * changed in place and earlier duplicates are dropped. A new key goes at the end.
     */
    public function set(string $key, string $token): void
    {
        self::assertKey($key);
        self::assertToken($token);
        $index = $this->index($key);
        if ($index === null) {
            $this->parts[] = $key.'='.$token;

            return;
        }

        $pair = self::split($this->parts[$index]);
        $this->parts[$index] = $pair['lead'].$pair['key'].$pair['gap'].'='.$pair['gap2'].$token.$pair['trail'];
        foreach (array_keys($this->parts) as $other) {
            if ($other !== $index && strcasecmp(self::split($this->parts[$other])['key'] ?? '', $key) === 0) {
                unset($this->parts[$other]);
            }
        }
        $this->parts = array_values($this->parts);
    }

    public function remove(string $key): void
    {
        $this->parts = array_values(array_filter(
            $this->parts,
            fn (string $part): bool => strcasecmp(self::split($part)['key'] ?? '', $key) !== 0,
        ));
    }

    /** A string as the game writes it: in double quotes, with \ and " escaped. */
    public static function quote(string $value): string
    {
        if (preg_match('/[\p{Cc}\p{Zl}\p{Zp}]/u', $value) === 1) {
            throw new InvalidArgumentException('Text values cannot contain line breaks or control characters.');
        }

        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }

    /** The text of a value without its quotes or surrounding brackets. */
    public static function display(string $raw): string
    {
        $raw = trim($raw);
        if (strlen($raw) >= 2 && $raw[0] === '"' && str_ends_with($raw, '"')) {
            return preg_replace('/\\\\(["\\\\])/', '$1', substr($raw, 1, -1)) ?? '';
        }
        if (strlen($raw) >= 2 && $raw[0] === '(' && str_ends_with($raw, ')')) {
            $items = array_map(fn (string $item): string => trim($item, " \t\""), explode(',', substr($raw, 1, -1)));

            return implode(',', array_filter($items, fn (string $item): bool => $item !== ''));
        }

        return $raw;
    }

    public static function isQuoted(string $raw): bool
    {
        $raw = trim($raw);

        return strlen($raw) >= 2 && $raw[0] === '"' && str_ends_with($raw, '"');
    }

    /** Byte offset of the "(" that opens the last OptionSettings assignment, or null. */
    private static function locate(string $text): ?int
    {
        if (preg_match_all('/^[ \t]*OptionSettings[ \t]*=[ \t]*\(/im', $text, $matches, PREG_OFFSET_CAPTURE) < 1) {
            return null;
        }
        $last = end($matches[0]);

        return $last[1] + strlen($last[0]) - 1;
    }

    private function index(string $key): ?int
    {
        $found = null;
        foreach ($this->parts as $index => $part) {
            $pair = self::split($part);
            if ($pair !== null && strcasecmp($pair['key'], $key) === 0) {
                $found = $index;
            }
        }

        return $found;
    }

    /**
     * @return array{lead: string, key: string, gap: string, gap2: string, value: string, trail: string}|null
     */
    private static function split(string $part): ?array
    {
        if (preg_match('/^(\s*)([^=\s"(),]+)(\s*)=(\s*)(.*?)(\s*)$/s', $part, $m) !== 1) {
            return null;
        }

        return ['lead' => $m[1], 'key' => $m[2], 'gap' => $m[3], 'gap2' => $m[4], 'value' => $m[5], 'trail' => $m[6]];
    }

    private static function assertKey(string $key): void
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/D', $key) !== 1) {
            throw new InvalidArgumentException('Invalid setting name.');
        }
    }

    /** A value token must not be able to end or split the tuple. */
    private static function assertToken(string $token): void
    {
        if (preg_match('/[\p{Cc}\p{Zl}\p{Zp}]/u', $token) === 1) {
            throw new InvalidArgumentException('A value cannot contain line breaks or control characters.');
        }
        $depth = 0;
        $quoted = false;
        $length = strlen($token);
        for ($i = 0; $i < $length; $i++) {
            $char = $token[$i];
            if ($quoted) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === '"') {
                    $quoted = false;
                }
            } elseif ($char === '"') {
                $quoted = true;
            } elseif ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                if (--$depth < 0) {
                    throw new InvalidArgumentException('Unbalanced brackets in a value.');
                }
            } elseif ($char === ',' && $depth === 0) {
                throw new InvalidArgumentException('A value cannot contain a bare comma.');
            }
        }
        if ($quoted || $depth !== 0) {
            throw new InvalidArgumentException('Unbalanced quotes or brackets in a value.');
        }
    }
}
