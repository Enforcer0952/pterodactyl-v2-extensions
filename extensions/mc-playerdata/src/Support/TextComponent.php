<?php

namespace McPlayerData\Support;

use McPlayerData\Support\Nbt\Tag;

/**
 * Turns a Minecraft text component into plain text. Names and lore are stored as
 * JSON in a string (before 1.20.5 and up to 1.21.4), or as NBT text (1.21.5 on):
 * a plain string, a compound such as {text:"Hi",color:"red"}, or a list.
 */
final class TextComponent
{
    private const int MAX_LENGTH = 200;

    /** A text component stored in an NBT tag, or null when there is nothing readable. */
    public static function fromTag(?Tag $tag): ?string
    {
        if ($tag === null) {
            return null;
        }

        $text = $tag->is(Tag::STRING) ? self::fromString((string) $tag->text()) : self::plain($tag->toPhp());

        return self::clean($text);
    }

    /** A string that is either JSON for a component or literal text. */
    public static function fromString(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed !== '' && in_array($trimmed[0], ['{', '[', '"'], true)) {
            $decoded = json_decode($trimmed, true, 16);
            if (json_last_error() === JSON_ERROR_NONE && (is_array($decoded) || is_string($decoded))) {
                return self::plain($decoded);
            }
        }

        return $value;
    }

    public static function plain(mixed $value, int $depth = 0): string
    {
        if ($depth > 8) {
            return '';
        }

        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (! is_array($value)) {
            return '';
        }

        if (array_is_list($value)) {
            return implode('', array_map(fn (mixed $part): string => self::plain($part, $depth + 1), $value));
        }

        $text = '';
        if (isset($value['text'])) {
            $text = self::plain($value['text'], $depth + 1);
        } elseif (isset($value['translate']) && is_string($value['translate'])) {
            $text = $value['translate'];
        } elseif (isset($value['keybind']) && is_string($value['keybind'])) {
            $text = $value['keybind'];
        }

        if (isset($value['extra']) && is_array($value['extra'])) {
            $text .= self::plain($value['extra'], $depth + 1);
        }

        return $text;
    }

    /** Without formatting codes, control characters or runaway length. */
    public static function clean(string $text): ?string
    {
        $text = preg_replace('/§[0-9a-fk-orx]/iu', '', $text) ?? '';
        $text = trim(preg_replace('/[\p{Cc}\p{Zl}\p{Zp}]+/u', ' ', $text) ?? '');

        return $text === '' ? null : mb_substr($text, 0, self::MAX_LENGTH);
    }
}
