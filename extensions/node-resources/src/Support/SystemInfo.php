<?php

namespace NodeResources\Support;

/** What Wings reports about its host on `/api/system?v=2`. */
final class SystemInfo
{
    /**
     * @param  array<mixed>  $info
     * @return array{memory_total: ?int, cpu_threads: ?int, os: ?string, version: ?string}
     */
    public static function fromWings(array $info): array
    {
        $system = is_array($info['system'] ?? null) ? $info['system'] : [];
        $memory = $system['memory_bytes'] ?? null;
        $threads = $system['cpu_threads'] ?? null;
        $os = $system['os'] ?? null;
        $version = $info['version'] ?? null;

        return [
            'memory_total' => is_int($memory) && $memory > 0 ? $memory : null,
            'cpu_threads' => is_int($threads) && $threads > 0 && $threads <= 4096 ? $threads : null,
            'os' => is_string($os) && $os !== '' ? mb_substr($os, 0, 100) : null,
            'version' => is_string($version) && $version !== '' ? mb_substr($version, 0, 50) : null,
        ];
    }
}
