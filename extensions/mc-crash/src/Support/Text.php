<?php

namespace McCrash\Support;

/** Small helpers for text that comes out of server files: encoding, control characters, size caps. */
final class Text
{
    /**
     * Valid UTF-8 with "\n" line ends and no control characters except tab and newline.
     * Cut to $max bytes (on a character boundary) when given.
     */
    public static function clean(string $text, ?int $max = null): string
    {
        if ($max !== null && strlen($text) > $max) {
            $text = substr($text, 0, $max);
        }

        // Invalid bytes become "?" instead of breaking the regexes (and JSON) further on.
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        // ANSI colour codes first, so the escape byte is not left behind as a stray bracket.
        $text = (string) preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', $text);

        return (string) preg_replace('/[^\P{Cc}\n\t]|[\p{Zl}\p{Zp}]/u', '', $text);
    }

    /** The last $bytes of the text, starting on a fresh line. Reports whether anything was cut. */
    public static function tail(string $text, int $bytes): string
    {
        if (strlen($text) <= $bytes) {
            return $text;
        }

        $cut = substr($text, -$bytes);
        $newline = strpos($cut, "\n");

        return $newline === false ? $cut : substr($cut, $newline + 1);
    }

    /** The first $lines lines. */
    public static function head(string $text, int $lines): string
    {
        $parts = explode("\n", $text, $lines + 1);
        if (count($parts) <= $lines) {
            return $text;
        }

        return implode("\n", array_slice($parts, 0, $lines));
    }

    /** One line, cut to $max characters with an ellipsis. */
    public static function oneLine(string $text, int $max = 200): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 1)).'…' : $text;
    }

    /** Last segment of a dotted class name, without the nested-class part: "a.b.Foo$Bar" gives "Foo". */
    public static function simpleName(string $class): string
    {
        $pos = strrpos($class, '.');
        $name = $pos === false ? $class : substr($class, $pos + 1);
        $dollar = strpos($name, '$');

        return $dollar === false || $dollar === 0 ? $name : substr($name, 0, $dollar);
    }
}
