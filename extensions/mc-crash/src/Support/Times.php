<?php

namespace McCrash\Support;

/**
 * Local timestamps as written by the server, as "2026-10-12T21:30:00" with no time zone. The same clock
 * is used for the logs, the crash reports and the file listing, so they sort together without conversions.
 */
final class Times
{
    /** "2026-10-12T21:30:00+02:00" from a file listing gives "2026-10-12T21:30:00". */
    public static function fromListing(mixed $modified): ?string
    {
        if (is_string($modified) && preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2}:\d{2})/', $modified, $m) === 1) {
            return $m[1].'T'.$m[2];
        }

        return null;
    }

    /** "crash-2026-10-12_21.30.00-server.txt" */
    public static function fromCrashName(string $name): ?string
    {
        if (preg_match('/(\d{4})-(\d{2})-(\d{2})_(\d{2})[.:](\d{2})[.:](\d{2})/', $name, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return sprintf('%s-%s-%sT%s:%s:%s', $m[1], $m[2], $m[3], $m[4], $m[5], $m[6]);
        }

        return null;
    }

    /** "2026-10-12-1.log.gz" gives "2026-10-12". */
    public static function dateFromLogName(string $name): ?string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:-\d+)?\.log(?:\.gz)?$/', $name, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return $m[1].'-'.$m[2].'-'.$m[3];
        }

        return null;
    }

    /**
     * The "Time:" line of a crash report or hs_err file.
     * Vanilla writes "2026-10-12 21:30:00 CEST" (older versions "10/12/26 9:30 PM"), the JVM "Tue Oct 12 21:30:00 2026 UTC".
     */
    public static function fromReport(string $text): ?string
    {
        if (preg_match('/^\s*Time: (\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})/m', $text, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return sprintf('%s-%s-%sT%s:%s:%s', $m[1], $m[2], $m[3], $m[4], $m[5], $m[6]);
        }

        if (preg_match('/^\s*Time: (\d{1,2})\/(\d{1,2})\/(\d{2,4}),? (\d{1,2}):(\d{2})(?::(\d{2}))?\s?(AM|PM)?/mi', $text, $m) === 1) {
            $year = (int) $m[3] < 100 ? 2000 + (int) $m[3] : (int) $m[3];
            $hour = (int) $m[4];
            $meridiem = strtoupper($m[7] ?? '');
            if ($meridiem === 'PM' && $hour < 12) {
                $hour += 12;
            } elseif ($meridiem === 'AM' && $hour === 12) {
                $hour = 0;
            }
            if (checkdate((int) $m[1], (int) $m[2], $year) && $hour < 24) {
                return sprintf('%04d-%02d-%02dT%02d:%s:%s', $year, (int) $m[1], (int) $m[2], $hour, $m[5], ($m[6] ?? '') !== '' ? $m[6] : '00');
            }
        }

        $months = ['Jan' => 1, 'Feb' => 2, 'Mar' => 3, 'Apr' => 4, 'May' => 5, 'Jun' => 6, 'Jul' => 7, 'Aug' => 8, 'Sep' => 9, 'Oct' => 10, 'Nov' => 11, 'Dec' => 12];
        if (preg_match('/^\s*Time: \w{3} (\w{3}) +(\d{1,2}) (\d{2}:\d{2}:\d{2}) (\d{4})/m', $text, $m) === 1 && isset($months[$m[1]])) {
            return sprintf('%04d-%02d-%02dT%s', (int) $m[4], $months[$m[1]], (int) $m[2], $m[3]);
        }

        return null;
    }

    /** The day before a "2026-10-12" date. */
    public static function previousDay(string $date): string
    {
        return gmdate('Y-m-d', (int) strtotime($date.' 00:00:00 UTC') - 86400);
    }

    public static function nextDay(string $date): string
    {
        return gmdate('Y-m-d', (int) strtotime($date.' 00:00:00 UTC') + 86400);
    }
}
