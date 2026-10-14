<?php

namespace NodeResources\Support;

/** Reads /proc/loadavg: "0.52 0.40 0.30 1/200 1234". */
final class LoadAvg
{
    /** @return array{1: float, 5: float, 15: float}|null */
    public static function parse(string $text): ?array
    {
        if (preg_match('/^\s*(\d{1,5}(?:\.\d+)?)\s+(\d{1,5}(?:\.\d+)?)\s+(\d{1,5}(?:\.\d+)?)(?:\s|$)/', $text, $match) !== 1) {
            return null;
        }

        return [1 => (float) $match[1], 5 => (float) $match[2], 15 => (float) $match[3]];
    }
}
