<?php

namespace ArkTools\Support;

/**
 * Reads the answer to the RCON "ListPlayers" command from the console:
 *
 *   0. Some Survivor, 76561198000000001
 *   1. Another One, 76561198000000002
 *
 * or "No Players Connected".
 */
final class ListPlayersOutput
{
    private const string LINE = '/^\s*(?:>\s*)?(\d+)\.\s*(.*?),\s*(7656119\d{10})\s*$/u';

    private const string NONE = '/No Players Connected/i';

    /**
     * @param  list<string>  $lines  console lines, oldest first
     * @return list<array{name: string, steam_id: string}>|null null when no answer was found
     */
    public static function parse(array $lines): ?array
    {
        $lines = array_map(fn (string $line): string => self::strip($line), $lines);

        // Walk back from the newest line to the latest answer.
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            if (preg_match(self::NONE, $lines[$i]) === 1) {
                return [];
            }

            if (preg_match(self::LINE, $lines[$i]) !== 1) {
                continue;
            }

            // Found the end of a block; collect upwards until it stops.
            $players = [];
            for ($j = $i; $j >= 0; $j--) {
                if (trim($lines[$j]) === '') {
                    continue;
                }
                if (preg_match(self::LINE, $lines[$j], $match) !== 1) {
                    break;
                }
                $players[(int) $match[1]] = ['name' => trim($match[2]), 'steam_id' => $match[3]];
                if ((int) $match[1] === 0) {
                    break;
                }
            }

            ksort($players);

            return array_values($players);
        }

        return null;
    }

    private static function strip(string $line): string
    {
        // Remove terminal colour codes the console may carry.
        return (string) preg_replace('/\x1B\[[0-9;]*[A-Za-z]/', '', $line);
    }
}
