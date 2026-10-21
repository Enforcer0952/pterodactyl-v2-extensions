<?php

namespace McPlayerData\Support\Nbt;

/**
 * Modified UTF-8, the string encoding of NBT. It differs from UTF-8 in two ways:
 * the NUL character is written as C0 80, and characters above U+FFFF are written
 * as a UTF-16 surrogate pair with three bytes per surrogate.
 */
final class Mutf8
{
    /** UTF-8 text to modified UTF-8. */
    public static function encode(string $utf8): string
    {
        if (! mb_check_encoding($utf8, 'UTF-8')) {
            throw new NbtException('Text is not valid UTF-8.');
        }

        // Plain ASCII without NUL is identical in both encodings.
        if (preg_match('/^[\x01-\x7F]*$/D', $utf8) === 1) {
            return $utf8;
        }

        $out = '';
        foreach (mb_str_split($utf8, 1, 'UTF-8') as $char) {
            $code = mb_ord($char, 'UTF-8');
            if ($code === 0) {
                $out .= "\xC0\x80";
            } elseif ($code < 0x80) {
                $out .= chr($code);
            } elseif ($code < 0x800) {
                $out .= chr(0xC0 | ($code >> 6)).chr(0x80 | ($code & 0x3F));
            } elseif ($code < 0x10000) {
                $out .= self::threeBytes($code);
            } else {
                $code -= 0x10000;
                $out .= self::threeBytes(0xD800 | ($code >> 10)).self::threeBytes(0xDC00 | ($code & 0x3FF));
            }
        }

        return $out;
    }

    /**
     * Modified UTF-8 to UTF-8. Anything that is not valid becomes U+FFFD, so the
     * result is always safe to JSON-encode and show.
     */
    public static function decode(string $modified): string
    {
        if (preg_match('/^[\x01-\x7F]*$/D', $modified) === 1) {
            return $modified;
        }

        $out = '';
        $length = strlen($modified);
        $i = 0;
        while ($i < $length) {
            $a = ord($modified[$i]);
            if ($a < 0x80) {
                // A raw NUL is not valid modified UTF-8, but keep it rather than lose data.
                $out .= chr($a);
                $i++;

                continue;
            }

            $code = self::decodeOne($modified, $i, $length, $a);
            if ($code === null) {
                $out .= "\u{FFFD}";
                $i++;

                continue;
            }

            [$point, $used] = $code;
            $i += $used;

            // A high surrogate followed by a low one is one character above U+FFFF.
            if ($point >= 0xD800 && $point <= 0xDBFF && $i < $length && ord($modified[$i]) === 0xED) {
                $next = self::decodeOne($modified, $i, $length, 0xED);
                if ($next !== null && $next[0] >= 0xDC00 && $next[0] <= 0xDFFF) {
                    $point = 0x10000 + (($point - 0xD800) << 10) + ($next[0] - 0xDC00);
                    $i += $next[1];
                }
            }

            $out .= ($point >= 0xD800 && $point <= 0xDFFF) ? "\u{FFFD}" : mb_chr($point, 'UTF-8');
        }

        return $out;
    }

    /**
     * @return array{int, int}|null code point and bytes used
     */
    private static function decodeOne(string $s, int $i, int $length, int $a): ?array
    {
        if (($a & 0xE0) === 0xC0 && $i + 1 < $length) {
            $b = ord($s[$i + 1]);
            if (($b & 0xC0) === 0x80) {
                return [(($a & 0x1F) << 6) | ($b & 0x3F), 2];
            }
        } elseif (($a & 0xF0) === 0xE0 && $i + 2 < $length) {
            $b = ord($s[$i + 1]);
            $c = ord($s[$i + 2]);
            if (($b & 0xC0) === 0x80 && ($c & 0xC0) === 0x80) {
                return [(($a & 0x0F) << 12) | (($b & 0x3F) << 6) | ($c & 0x3F), 3];
            }
        }

        return null;
    }

    private static function threeBytes(int $code): string
    {
        return chr(0xE0 | ($code >> 12)).chr(0x80 | (($code >> 6) & 0x3F)).chr(0x80 | ($code & 0x3F));
    }
}
