<?php

namespace TerrariaTools\Support;

/**
 * Reads the answer to the console command "playing":
 *
 *   Anton (192.168.1.20:51234)
 *   Koen (192.168.1.21:49822)
 *   2 players connected.
 *
 * The summary is "No players connected.", "1 player connected." or
 * "N players connected." Each player line is the name followed by the address in
 * brackets. The addresses are dropped: nothing here needs them.
 */
final class PlayingOutput
{
    private const string SUMMARY = '/^(?:(?<none>No players connected)|(?<count>\d+) players? connected)\.?$/iD';

    /** "Name (address)": the address is the last bracketed group, the name may contain brackets. */
    private const string PLAYER = '/^(?<name>.*\S)\s+\((?<address>[^()]*)\)$/uD';

    /**
     * @param  list<string>  $lines  console lines that arrived after the command, oldest first
     * @return list<string>|null player names, or null when the answer is not there (yet)
     */
    public static function parse(array $lines): ?array
    {
        $clean = array_map(self::clean(...), array_values($lines));

        // Walk back from the newest line to the latest summary.
        for ($i = count($clean) - 1; $i >= 0; $i--) {
            if (preg_match(self::SUMMARY, $clean[$i], $summary) !== 1) {
                continue;
            }

            if (($summary['none'] ?? '') !== '') {
                return [];
            }

            $count = (int) $summary['count'];
            $names = [];
            // Exactly as many player lines as the summary says sit right above it.
            for ($j = $i - 1; $j >= 0 && count($names) < $count; $j--) {
                if ($clean[$j] === '') {
                    continue;
                }
                if (preg_match(self::PLAYER, $clean[$j], $player) !== 1) {
                    break;
                }
                array_unshift($names, $player['name']);
            }

            // A summary without its players is a partial read: ask again.
            return count($names) === $count ? $names : null;
        }

        return null;
    }

    private static function clean(string $line): string
    {
        // Terminal colour codes, then the console prompt that can precede a line.
        $line = (string) preg_replace('/\x1B\[[0-9;?]*[A-Za-z]/', '', $line);
        $line = (string) preg_replace('/^\s*[:>]\s+/', '', $line);

        return trim($line);
    }
}
