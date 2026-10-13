<?php

namespace McPlayers\Support;

/**
 * Reads the server's answer to the "list" command out of console lines. Handles
 * the vanilla, Forge, Paper and Bukkit wording, the pre-1.13 two-line form, and
 * Essentials' grouped list.
 */
final class ListOutput
{
    /**
     * @param  list<string>  $lines  console lines, oldest first
     * @return array{count: int, max: int, players: list<string>}|null the newest list answer, or null
     */
    public static function parse(array $lines): ?array
    {
        $lines = array_map([self::class, 'clean'], $lines);

        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $line = $lines[$i];

            // "There are 2 of a max of 20 players online: Steve, Alex". Anchored to the
            // start, so a chat line ("<Steve> There are 99 of ...") cannot fake it.
            if (preg_match('/^There are (\d+) of a max(?:imum)? of (\d+) players online:?\s*(.*)$/i', $line, $m) === 1) {
                return self::result((int) $m[1], (int) $m[2], self::names($m[3]));
            }

            // "There are 2/20 players online:" with names on the next line (1.12 and older)
            // "There are 2 out of maximum 20 players online." with "group: names" lines (Essentials)
            if (preg_match('/^There are (\d+)(?:\/| out of maximum )(\d+) players online/i', $line, $m) === 1) {
                $names = [];
                for ($j = $i + 1; $j < min(count($lines), $i + 12); $j++) {
                    $next = $lines[$j];
                    if ($next === '' || preg_match('/^(?:>|container@)/', $next) === 1) {
                        continue;
                    }
                    if (preg_match('/^(?:[A-Za-z][\w ]{0,31}: )?(.+)$/', $next, $g) !== 1 || preg_match(Validates::NAME, explode(', ', self::strip($g[1]))[0]) !== 1) {
                        break;
                    }
                    $names = [...$names, ...self::names($g[1])];
                }

                return self::result((int) $m[1], (int) $m[2], $names);
            }
        }

        return null;
    }

    /** Messages a server prints when a player command did nothing. */
    private const array FAILURES = [
        'That player does not exist', 'No player was found', 'Unknown player', 'Player not found', 'Could not',
        'Nothing changed', 'is already', 'is not whitelisted', 'is not banned', 'is not an operator',
        'Invalid IP', 'Invalid player', 'not a valid', 'Incorrect argument', 'Unknown or incomplete command',
        'You do not have permission',
    ];

    /** Messages that confirm a player command worked. */
    private const array SUCCESSES = [
        'Kicked', 'Banned', 'Unbanned', 'Made ', 'Added ', 'Removed ', 'Whitelist is now', 'Turned on', 'Turned off',
        'no longer a server operator', 'Banned IP', 'Unbanned IP',
    ];

    /**
     * The server's answer to a command that was just sent: the first line after the
     * command that reads like a result.
     *
     * @param  list<string>  $lines  console lines, oldest first
     * @return array{failed: bool, message: string}|null
     */
    public static function reply(array $lines, string $command): ?array
    {
        $lines = array_map([self::class, 'clean'], $lines);

        // Start after the echo of the command itself when the console shows it.
        $start = 0;
        foreach ($lines as $index => $line) {
            if (str_ends_with($line, $command)) {
                $start = $index + 1;
            }
        }

        foreach (array_slice($lines, $start) as $line) {
            if ($line === '' || str_starts_with($line, '<')) {
                continue;
            }
            foreach (self::FAILURES as $failure) {
                if (stripos($line, $failure) !== false) {
                    return ['failed' => true, 'message' => mb_substr($line, 0, 200)];
                }
            }
            foreach (self::SUCCESSES as $success) {
                if (stripos($line, $success) !== false) {
                    return ['failed' => false, 'message' => mb_substr($line, 0, 200)];
                }
            }
        }

        return null;
    }

    /** Strip ANSI colours and the "[12:00:00 INFO]: " style prefixes. */
    public static function clean(string $line): string
    {
        $line = preg_replace('/\x1B\[[0-9;?]*[ -\/]*[@-~]/', '', $line) ?? $line;
        $line = preg_replace('/§[0-9a-fk-or]/i', '', $line) ?? $line;

        return trim(preg_replace('/^(?:\[[^\]]*\]\s*)+:?\s*/', '', trim($line)) ?? $line);
    }

    /** @return list<string> */
    private static function names(string $list): array
    {
        $names = [];
        foreach (preg_split('/,\s*/', trim($list)) ?: [] as $part) {
            $name = self::strip($part);
            if (preg_match(Validates::NAME, $name) === 1) {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    /** Drop decorations such as "[AFK]", "~" nickname markers and trailing UUIDs. */
    private static function strip(string $part): string
    {
        $part = preg_replace('/\[[^\]]*\]/', '', $part) ?? $part;
        $part = preg_replace('/\s*\([0-9a-f-]{32,36}\)$/i', '', $part) ?? $part;

        return trim(ltrim(trim($part), '~*'));
    }

    /**
     * @param  list<string>  $players
     * @return array{count: int, max: int, players: list<string>}
     */
    private static function result(int $count, int $max, array $players): array
    {
        sort($players, SORT_NATURAL | SORT_FLAG_CASE);

        return ['count' => $count, 'max' => $max, 'players' => $players];
    }
}
