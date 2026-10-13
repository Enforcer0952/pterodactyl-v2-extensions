<?php

namespace TerrariaTools\Support;

/**
 * Reads and edits banlist.txt. The server writes two lines per ban: a comment with
 * the player's name, then the identifier it matches on (an address):
 *
 *   //Griefer
 *   192.168.1.30
 *
 * Lines are kept as they are; only the ones removed by unban disappear.
 */
final class BanList
{
    private const int MAX_ID = 200;

    /**
     * @return list<array{name: ?string, id: string}>
     */
    public static function parse(string $contents): array
    {
        $entries = [];
        $name = null;
        foreach (self::split($contents) as [$text]) {
            $line = trim($text);
            if ($line === '') {
                continue;
            }
            if (str_starts_with($line, '//')) {
                $name = Terraria::text(substr($line, 2), Terraria::MAX_PLAYER_NAME);

                continue;
            }
            if (self::validId($line)) {
                $entries[] = ['name' => $name, 'id' => $line];
            }
            $name = null;
        }

        return $entries;
    }

    /**
     * The file without the bans that match an identifier, name comments included.
     * Everything else stays byte for byte. Returns null when nothing matched.
     */
    public static function remove(string $contents, string $id): ?string
    {
        $lines = self::split($contents);
        $drop = [];
        $comment = null;
        foreach ($lines as $index => [$text]) {
            $line = trim($text);
            if ($line === '') {
                continue;
            }
            if (str_starts_with($line, '//')) {
                $comment = $index;

                continue;
            }
            if ($line === $id) {
                $drop[$index] = true;
                if ($comment !== null) {
                    $drop[$comment] = true;
                }
            }
            $comment = null;
        }

        if ($drop === []) {
            return null;
        }

        $out = str_starts_with($contents, "\xEF\xBB\xBF") ? "\xEF\xBB\xBF" : '';
        foreach ($lines as $index => [$text, $eol]) {
            if (! isset($drop[$index])) {
                $out .= $text.$eol;
            }
        }

        return $out;
    }

    /** An identifier that is one plain line. */
    public static function validId(string $id): bool
    {
        return $id !== '' && strlen($id) <= self::MAX_ID && ! str_starts_with($id, '//') && preg_match('/[\p{Cc}\p{Zl}\p{Zp}]/u', $id) !== 1;
    }

    /**
     * @return list<array{0: string, 1: string}> text and line ending of each line
     */
    private static function split(string $contents): array
    {
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }
        if ($contents === '') {
            return [];
        }

        $parts = preg_split('/(\r\n|\n|\r)/', $contents, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$contents];
        $lines = [];
        for ($i = 0, $count = count($parts); $i < $count; $i += 2) {
            if ($parts[$i] === '' && ($parts[$i + 1] ?? '') === '' && $i === $count - 1) {
                break;
            }
            $lines[] = [$parts[$i], $parts[$i + 1] ?? ''];
        }

        return $lines;
    }
}
