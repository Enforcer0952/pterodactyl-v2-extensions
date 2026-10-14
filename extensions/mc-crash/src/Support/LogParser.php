<?php

namespace McCrash\Support;

/**
 * Splits console log text into entries: a header line ("[21:30:00 ERROR]: ...") plus the lines that belong to it
 * (stack traces and multi-line messages). Only warnings and errors are kept.
 *
 * Handles the formats of Paper/Spigot, vanilla/Fabric/Forge, BungeeCord and older servers.
 */
final class LogParser
{
    /** Longest entry kept, in lines and in bytes. */
    private const MAX_LINES = 150;

    private const MAX_BYTES = 16384;

    /**
     * @return array{time: string, level: string, message: string}|null level is ERROR, WARN or INFO
     */
    public static function header(string $line): ?array
    {
        if ($line === '') {
            return null;
        }

        $m = [];
        if ($line[0] === '[') {
            if (preg_match('/^\[(\d{2}:\d{2}:\d{2})\s+([A-Z]+)\]:?\s?(.*)$/s', $line, $m) === 1) {
                return self::result($m[1], $m[2], $m[3]);
            }
            if (preg_match('/^\[(\d{2}:\d{2}:\d{2})\]\s+\[([^\]\/]*)\/([A-Z]+)\](?:\s+\[[^\]]*\])*:?\s?(.*)$/s', $line, $m) === 1) {
                return self::result($m[1], $m[3], $m[4]);
            }
            if (preg_match('/^\[\d{1,2}[A-Za-z]{3}\d{4} (\d{2}:\d{2}:\d{2})(?:\.\d+)?\]\s+\[([^\]\/]*)\/([A-Z]+)\](?:\s+\[[^\]]*\])*:?\s?(.*)$/s', $line, $m) === 1) {
                return self::result($m[1], $m[3], $m[4]);
            }

            return null;
        }

        if (preg_match('/^(?:\d{4}-\d{2}-\d{2} )?(\d{2}:\d{2}:\d{2}) \[([A-Z]+)\] (.*)$/s', $line, $m) === 1) {
            return self::result($m[1], $m[2], $m[3]);
        }

        return null;
    }

    /**
     * Warning and error entries, oldest first. The generator's return value is the number of times the
     * clock wrapped past midnight, which is how dates are worked out afterwards.
     *
     * @return \Generator<int, array{time: ?string, day: int, level: string, message: string, tag: ?string, body: string}, mixed, int>
     */
    public static function entries(string $text): \Generator
    {
        $day = 0;
        $previous = null;
        $current = null;

        foreach (explode("\n", $text) as $line) {
            $header = self::header($line);

            if ($header !== null) {
                if ($current !== null && $current['level'] !== 'INFO') {
                    yield self::finish($current);
                }

                if ($previous !== null && strcmp($header['time'], $previous) < 0 && self::seconds($previous) - self::seconds($header['time']) > 3600) {
                    $day++;
                }
                $previous = $header['time'];

                $current = $header + ['day' => $day, 'lines' => [$line], 'bytes' => strlen($line), 'count' => 1];

                continue;
            }

            if ($current === null) {
                // Output before the first header, such as a failed start: only error-looking text counts.
                if (preg_match('/Exception|Error|^\s+at /', $line) === 1) {
                    $current = ['time' => '', 'level' => 'ERROR', 'message' => Text::oneLine($line, 300), 'day' => 0, 'lines' => [$line], 'bytes' => strlen($line), 'count' => 1];
                }

                continue;
            }

            if ($current['level'] !== 'INFO' && $current['count'] < self::MAX_LINES && $current['bytes'] < self::MAX_BYTES) {
                $current['lines'][] = $line;
                $current['bytes'] += strlen($line) + 1;
                $current['count']++;
            }
        }

        if ($current !== null && $current['level'] !== 'INFO') {
            yield self::finish($current);
        }

        return $day;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array{time: ?string, day: int, level: string, message: string, tag: ?string, body: string}
     */
    private static function finish(array $entry): array
    {
        $message = (string) $entry['message'];
        $tag = null;
        if (preg_match('/^\[([\w\-. ]{2,40})\] /', $message, $m) === 1) {
            $tag = $m[1];
        }

        return [
            'time' => $entry['time'] === '' ? null : $entry['time'],
            'day' => $entry['day'],
            'level' => $entry['level'],
            'message' => $message,
            'tag' => $tag,
            'body' => implode("\n", $entry['lines']),
        ];
    }

    /** @return array{time: string, level: string, message: string} */
    private static function result(string $time, string $level, string $message): array
    {
        $level = match ($level) {
            'FATAL', 'SEVERE', 'ERROR' => 'ERROR',
            'WARN', 'WARNING' => 'WARN',
            default => 'INFO',
        };

        return ['time' => $time, 'level' => $level, 'message' => $message];
    }

    private static function seconds(string $time): int
    {
        return (int) substr($time, 0, 2) * 3600 + (int) substr($time, 3, 2) * 60 + (int) substr($time, 6, 2);
    }
}
