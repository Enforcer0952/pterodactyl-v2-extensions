<?php

namespace NodeResources\Support;

/**
 * Reads the output of `df -P -k <path>`: the size, use and free space of the disk that
 * holds the servers' volumes.
 *
 *   Filesystem     1024-blocks      Used Available Capacity Mounted on
 *   /dev/sda2        479152124 301231288 153502340      67% /home/container
 *
 * The header is optional (the probe only sends the last line). Without one the blocks
 * are taken as 1024 bytes, which is what `-k` asks for.
 */
final class Df
{
    private const string LINE = '/^(.*?)\s+(\d+)\s+(\d+)\s+(\d+)\s+(?:\d+%|-)\s+(\/.*)$/';

    /**
     * @return array{total: int, used: int, free: int}|null bytes; free is what a normal user can still write
     */
    public static function parse(string $text): ?array
    {
        $blockSize = 1024;
        $lines = preg_split('/\R/', trim($text)) ?: [];

        foreach ($lines as $line) {
            if (preg_match('/^\s*Filesystem\s+(\d*)(K?)-blocks/i', $line, $header) === 1) {
                $blockSize = $header[1] === '' ? 1024 : ((int) $header[1]) * ($header[2] === '' ? 1 : 1024);
            }
        }

        for ($i = count($lines) - 1; $i >= 0; $i--) {
            if (preg_match(self::LINE, trim($lines[$i]), $match) !== 1) {
                continue;
            }

            // Anything past 63 bits is not a disk.
            if (strlen($match[2]) > 15 || strlen($match[3]) > 15 || strlen($match[4]) > 15 || $blockSize < 1) {
                return null;
            }

            $total = (int) $match[2] * $blockSize;
            $used = (int) $match[3] * $blockSize;
            $free = (int) $match[4] * $blockSize;

            if ($total <= 0 || $free > $total || $used > $total) {
                return null;
            }

            return ['total' => $total, 'used' => $used, 'free' => $free];
        }

        return null;
    }
}
