<?php

namespace NodeResources\Support;

/**
 * The line the probe server prints to its console once a minute:
 *
 *   NRPROBE {"v":1,"ts":1760000000,"cpus":8,"df":"/dev/sda2 479152124 ... /home/container","mem":"MemTotal: 16318452 kB;...","load":"0.52 0.40 0.30 1/200 1234"}
 *
 * The probe sends raw text and the panel does the reading, so a fix to the parsing needs no
 * change on the node. Anything that does not parse is ignored.
 */
final class Probe
{
    public const int VERSION = 1;

    private const string LINE = '/NRPROBE (\{.*\})\s*$/';

    /**
     * @return array{ts: int, cpus: int, disk_total: int, disk_used: int, disk_free: int, mem_total: int, mem_available: int, load1: ?float, load5: ?float, load15: ?float}|null
     */
    public static function parseLine(string $line): ?array
    {
        $line = self::clean($line);
        if (strlen($line) > 4096 || preg_match(self::LINE, $line, $match) !== 1) {
            return null;
        }

        $data = json_decode($match[1], true);
        if (! is_array($data)) {
            return null;
        }

        $ts = $data['ts'] ?? null;
        if (! is_int($ts) || $ts < 1_000_000_000 || $ts > 4_000_000_000) {
            return null;
        }
        $version = $data['v'] ?? self::VERSION;
        if (! is_int($version) || $version < 1 || $version > self::VERSION) {
            return null;
        }
        $cpus = $data['cpus'] ?? 0;
        if (! is_int($cpus) || $cpus < 0 || $cpus > 4096) {
            return null;
        }
        if (! is_string($data['df'] ?? null) || ! is_string($data['mem'] ?? null)) {
            return null;
        }

        $disk = Df::parse($data['df']);
        $mem = Meminfo::parse($data['mem']);
        if ($disk === null || $mem === null) {
            return null;
        }
        $load = is_string($data['load'] ?? null) ? LoadAvg::parse($data['load']) : null;

        return [
            'ts' => $ts,
            'cpus' => $cpus,
            'disk_total' => $disk['total'],
            'disk_used' => $disk['used'],
            'disk_free' => $disk['free'],
            'mem_total' => $mem['total'],
            'mem_available' => $mem['available'],
            'load1' => $load[1] ?? null,
            'load5' => $load[5] ?? null,
            'load15' => $load[15] ?? null,
        ];
    }

    /**
     * The newest valid reading in the console lines (oldest first), or null.
     *
     * @param  list<string>  $lines
     * @return array{ts: int, cpus: int, disk_total: int, disk_used: int, disk_free: int, mem_total: int, mem_available: int, load1: ?float, load5: ?float, load15: ?float}|null
     */
    public static function latest(array $lines): ?array
    {
        $best = null;

        foreach ($lines as $line) {
            $reading = is_string($line) ? self::parseLine($line) : null;
            if ($reading !== null && ($best === null || $reading['ts'] >= $best['ts'])) {
                $best = $reading;
            }
        }

        return $best;
    }

    private static function clean(string $line): string
    {
        // Colour codes and other control characters the console may add.
        $line = (string) preg_replace('/\x1B\[[0-9;?]*[A-Za-z]/', '', $line);

        return trim((string) preg_replace('/[\p{Cc}\p{Zl}\p{Zp}]/u', '', $line));
    }

    /**
     * Whether an egg that is already in the panel is the one this extension ships: same
     * startup command and same first Docker image. The probe runs whatever that egg says,
     * so an egg with the same name from somewhere else must not be used.
     *
     * @param  array<string, mixed>  $shipped  the decoded egg file
     * @param  array<string, string>|mixed  $images  the existing egg's Docker images
     */
    public static function eggMatches(array $shipped, string $startup, mixed $images): bool
    {
        $wanted = is_array($shipped['docker_images'] ?? null) ? array_values($shipped['docker_images'])[0] ?? null : null;
        $have = is_array($images) ? array_values($images)[0] ?? null : null;

        return is_string($wanted) && is_string($have) && $wanted === $have
            && is_string($shipped['startup'] ?? null) && trim($shipped['startup']) === trim($startup);
    }
}
