<?php

namespace McCrash\Support;

/**
 * Forge and NeoForge keep their start-up failures in logs/debug.log instead of a crash report. This cuts
 * the part around the last failure out of a long log, so it can be analysed like a report.
 */
final class CrashSections
{
    /** Lines of the log that mark a failure worth a report. */
    private const MARKERS = '/---- Minecraft Crash Report ----|Missing or unsupported mandatory dependencies|Loading errors encountered|Crash report saved to|Exception in server tick loop|Encountered an unexpected exception|Failed to start the minecraft server|FAILED TO BIND TO PORT|UnsupportedClassVersionError|Mixin apply for mod|Critical injection failure|java\.lang\.OutOfMemoryError|The server has stopped responding/';

    private const BEFORE = 8 * 1024;

    private const AFTER = 56 * 1024;

    /**
     * The text around the last failure, or null when the log holds none.
     *
     * @return array{text: string, time: ?string}|null
     */
    public static function find(string $log): ?array
    {
        $text = Text::clean($log);
        if (preg_match_all(self::MARKERS, $text, $m, PREG_OFFSET_CAPTURE) < 1) {
            return null;
        }

        // The last marker; markers within a few lines of each other belong to the same failure, so the
        // section starts at the first marker of that cluster.
        $hits = array_map(fn (array $hit): int => $hit[1], $m[0]);
        $start = $hits[count($hits) - 1];
        for ($i = count($hits) - 2; $i >= 0; $i--) {
            if ($start - $hits[$i] > self::AFTER / 2) {
                break;
            }
            $start = $hits[$i];
        }

        $from = max(0, $start - self::BEFORE);
        $lineStart = $from === 0 ? 0 : (int) strpos($text, "\n", $from) + 1;
        $section = substr($text, $lineStart, self::BEFORE + self::AFTER);

        return ['text' => $section, 'time' => self::time($section)];
    }

    /** The first dated line of the section: Forge writes "[12Oct2026 20:11:05.220]". */
    public static function time(string $text): ?string
    {
        $months = ['Jan' => 1, 'Feb' => 2, 'Mar' => 3, 'Apr' => 4, 'May' => 5, 'Jun' => 6, 'Jul' => 7, 'Aug' => 8, 'Sep' => 9, 'Oct' => 10, 'Nov' => 11, 'Dec' => 12];
        if (preg_match('/^\[(\d{1,2})([A-Za-z]{3})(\d{4}) (\d{2}:\d{2}:\d{2})/m', $text, $m) === 1 && isset($months[$m[2]])) {
            return sprintf('%04d-%02d-%02dT%s', (int) $m[3], $months[$m[2]], (int) $m[1], $m[4]);
        }

        return null;
    }
}
