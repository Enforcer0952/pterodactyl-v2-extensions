<?php

namespace NodeResources\Support;

/**
 * Reads the memory lines of /proc/meminfo. Lines can be separated by new lines, `;` or `|`
 * (the probe joins them to keep to a single console line).
 *
 *   MemTotal:       16318452 kB
 *   MemAvailable:   11833724 kB
 */
final class Meminfo
{
    /**
     * @return array{total: int, available: int}|null bytes. Where the kernel is too old to report
     *                                                  MemAvailable, free + buffers + cache is used.
     */
    public static function parse(string $text): ?array
    {
        $values = [];

        foreach (preg_split('/[\r\n;|]+/', $text) ?: [] as $line) {
            if (preg_match('/^\s*([A-Za-z_()0-9]+):\s*(\d{1,15})\s*(kB)?\s*$/i', $line, $match) !== 1) {
                continue;
            }
            $values[$match[1]] = (int) $match[2] * (strtolower($match[3] ?? '') === 'kb' ? 1024 : 1);
        }

        $total = $values['MemTotal'] ?? 0;
        if ($total <= 0) {
            return null;
        }

        if (isset($values['MemAvailable'])) {
            $available = $values['MemAvailable'];
        } elseif (isset($values['MemFree'])) {
            $available = $values['MemFree'] + ($values['Buffers'] ?? 0) + ($values['Cached'] ?? 0);
        } else {
            return null;
        }

        return ['total' => $total, 'available' => min($available, $total)];
    }
}
