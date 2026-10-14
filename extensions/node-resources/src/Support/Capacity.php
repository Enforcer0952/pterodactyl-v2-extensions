<?php

namespace NodeResources\Support;

/**
 * The numbers for one node: what it has, what is promised to servers, what they really
 * use and what is left. Limits of 0 mean unlimited, and an unlimited server is counted
 * by what it has really used (its 7-day peak, or what it uses now if that is higher), so
 * it neither disappears from the sum nor counts as infinite.
 *
 * Sizes in the result are bytes; limits that come in are the panel's MB values.
 */
final class Capacity
{
    /**
     * @param  array{memory: int, memory_overallocate: int, disk: int, disk_overallocate: int}  $node  the node's settings (MB, percent)
     * @param  list<array{memory: int, disk: int, cpu: int, mem_now: ?int, disk_now: ?int, cpu_now: ?float, mem_peak: ?int, disk_peak: ?int, cpu_peak: ?float}>  $servers
     * @param  array{mem_total: ?int, mem_available: ?int, disk_total: ?int, disk_free: ?int, cpu_threads: ?int, load1: ?float}  $host
     * @return array{ram: array<string, mixed>, disk: array<string, mixed>, cpu: array<string, mixed>}
     */
    public static function summarize(array $node, array $servers, array $host): array
    {
        $memTotal = $host['mem_total'];
        $memAvailable = $host['mem_available'];
        $diskTotal = $host['disk_total'];
        $diskFree = $host['disk_free'];

        $ram = self::resource(
            $node['memory'], $node['memory_overallocate'], $servers, 'memory', 'mem_now', 'mem_peak',
            $memTotal, $memAvailable
        );
        $disk = self::resource(
            $node['disk'], $node['disk_overallocate'], $servers, 'disk', 'disk_now', 'disk_peak',
            $diskTotal, $diskFree
        );

        $cpuAllocated = 0;
        $cpuUnlimited = 0;
        $cpuUsed = 0.0;
        foreach ($servers as $server) {
            if ($server['cpu'] > 0) {
                $cpuAllocated += $server['cpu'];
            } else {
                $cpuUnlimited++;
            }
            $cpuUsed += (float) ($server['cpu_now'] ?? 0);
        }
        $threads = $host['cpu_threads'];

        return [
            'ram' => $ram,
            'disk' => $disk,
            'cpu' => [
                'threads' => $threads,
                'capacity' => $threads === null ? null : $threads * 100,
                'load1' => $host['load1'],
                'allocated' => $cpuAllocated,
                'unlimited' => $cpuUnlimited,
                'servers_used' => round($cpuUsed, 1),
                'committed' => $cpuAllocated + self::unlimitedCpu($servers),
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $servers
     * @return array<string, mixed>
     */
    private static function resource(int $nodeMb, int $overallocate, array $servers, string $limitKey, string $nowKey, string $peakKey, ?int $hostTotal, ?int $hostFree): array
    {
        $allocated = 0;
        $unlimited = 0;
        $used = 0;
        $committed = 0;

        foreach ($servers as $server) {
            $now = (int) ($server[$nowKey] ?? 0);
            $used += $now;

            if ($server[$limitKey] > 0) {
                $allocated += $server[$limitKey] * Format::MIB;
                $committed += $server[$limitKey] * Format::MIB;
            } else {
                $unlimited++;
                $committed += max($now, (int) ($server[$peakKey] ?? 0));
            }
        }

        $panelLimit = (int) round($nodeMb * (1 + max(0, $overallocate) / 100) * Format::MIB);
        $hostUsed = $hostTotal !== null && $hostFree !== null ? max(0, $hostTotal - $hostFree) : null;

        return [
            'host_total' => $hostTotal,
            'host_used' => $hostUsed,
            'host_free' => $hostFree,
            'node_size' => $nodeMb * Format::MIB,
            'overallocate' => max(0, $overallocate),
            'panel_limit' => $panelLimit,
            'allocated' => $allocated,
            'unlimited' => $unlimited,
            'servers_used' => $used,
            'committed' => $committed,
            'unallocated' => max(0, $panelLimit - $allocated),
            'overcommitted' => $hostTotal === null ? null : $committed > $hostTotal,
        ];
    }

    /** @param list<array<string, mixed>> $servers */
    private static function unlimitedCpu(array $servers): int
    {
        $sum = 0;
        foreach ($servers as $server) {
            if ($server['cpu'] <= 0) {
                $sum += (int) ceil(max((float) ($server['cpu_now'] ?? 0), (float) ($server['cpu_peak'] ?? 0)));
            }
        }

        return $sum;
    }
}
