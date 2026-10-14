<?php

namespace NodeResources\Support;

/** Sizes as people read them. Everything is counted in steps of 1024, as the panel does for its MB limits. */
final class Format
{
    public const int MIB = 1_048_576;

    public static function bytes(int|float $bytes): string
    {
        $bytes = max(0, (float) $bytes);
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        $digits = $i === 0 || $bytes >= 100 ? 0 : 1;

        $number = number_format($bytes, $digits, '.', '');
        if (str_contains($number, '.')) {
            $number = rtrim(rtrim($number, '0'), '.');
        }

        return $number.' '.$units[$i];
    }

    public static function days(float $days): string
    {
        if ($days < 1) {
            return 'less than a day';
        }
        $rounded = (int) round($days);

        return $rounded === 1 ? '1 day' : $rounded.' days';
    }
}
