<?php

namespace PalworldTools\Support;

/**
 * Palworld's local ban list, Pal/Saved/SaveGames/banlist.txt: one player id per line
 * (steam_7656...). Lines that are not ids are kept as they are.
 */
final class BanList
{
    /**
     * @return list<string>
     */
    public static function ids(string $text): array
    {
        $ids = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && Palworld::validUserId($line) && ! in_array($line, $ids, true)) {
                $ids[] = $line;
            }
        }

        return $ids;
    }

    /** The text without the lines that are this id. Line endings of the rest stay. */
    public static function remove(string $text, string $id): string
    {
        $kept = [];
        foreach (preg_split('/(?<=\n)/', $text) ?: [] as $line) {
            if (trim($line) !== $id) {
                $kept[] = $line;
            }
        }

        return implode('', $kept);
    }
}
