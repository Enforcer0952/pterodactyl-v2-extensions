<?php

namespace McCrash\Services;

use Illuminate\Support\Facades\Cache;
use McCrash\Support\GzTail;
use McCrash\Support\JarMap;
use McCrash\Support\LogScanner;
use McCrash\Support\Text;
use McCrash\Support\Times;
use Pterodactyl\Contracts\Servers\ReadsServerLogs;
use Pterodactyl\Models\Server;

/**
 * Scans logs/latest.log and the five newest rotated logs/*.log.gz for repeating errors. Logs can be hundreds of
 * megabytes, and the panel can only fetch a file whole, so a log is only fetched when its listing size is
 * small enough, and only its last 4 MB are scanned. Every log is scanned once; the result is kept per
 * path, size and modification time.
 */
class LogScans
{
    /** Scanned from the end of a log. */
    public const int KEEP_BYTES = 4 * 1024 * 1024;

    /** A plain log bigger than this is not fetched; the console tail is scanned instead. */
    public const int MAX_LIVE_BYTES = 12 * 1024 * 1024;

    /** A rotated log (compressed) bigger than this is skipped. */
    public const int MAX_GZ_BYTES = 10 * 1024 * 1024;

    private const int ROTATED = 5;

    private const int MAX_SCANS = 3;

    private const int MAX_SECONDS = 18;

    public function __construct(
        private readonly Files $files,
        private readonly Engine $engine,
        private readonly ReadsServerLogs $console,
    ) {}

    /**
     * The logs to scan, newest first, without reading any.
     *
     * @return list<array{path: string, name: string, live: bool, size: int, modified: ?string, entry: array<string, mixed>}>
     */
    public function sources(Server $server): array
    {
        $entries = $this->files->folder($server, 'logs');
        $sources = [];

        $latest = Files::file($entries, 'latest.log');
        if ($latest !== null) {
            $sources[] = $this->source('latest.log', true, $latest);
        }

        $rotated = [];
        foreach ($entries as $entry) {
            $name = (string) ($entry['name'] ?? '');
            if (($entry['file'] ?? true) !== false && preg_match('/^[\w.\-]{1,100}\.log\.gz$/D', $name) === 1) {
                $rotated[] = $this->source($name, false, $entry);
            }
        }
        usort($rotated, fn (array $a, array $b): int => strcmp((string) $b['modified'], (string) $a['modified']) ?: strcmp($b['name'], $a['name']));

        return [...$sources, ...array_slice($rotated, 0, self::ROTATED)];
    }

    /**
     * @param  array<string, mixed>  $index  JarIndex::forServer()
     * @return array{sources: list<array<string, mixed>>, groups: list<array<string, mixed>>, pending: int}
     */
    public function scan(Server $server, array $index): array
    {
        $started = microtime(true);
        $scans = 0;
        $pending = 0;
        $rows = [];
        $groups = [];

        foreach ($this->sources($server) as $source) {
            $result = $this->cached($server, $source, $index);

            if ($result === null) {
                if ($scans >= self::MAX_SCANS || microtime(true) - $started > self::MAX_SECONDS) {
                    $pending++;
                    $rows[] = $this->row($source, ['status' => 'pending', 'note' => null, 'entries' => 0, 'groups' => []]);

                    continue;
                }
                $scans++;
                $result = $this->process($server, $source, $index);
            }

            $rows[] = $this->row($source, $result);
            $groups = LogScanner::merge($groups, $result['groups']);
        }

        return ['sources' => $rows, 'groups' => LogScanner::present($groups), 'pending' => $pending];
    }

    /**
     * The log file as sent to a sharing service: its end, up to $bytes and $lines.
     *
     * @return array{text: string, note: ?string}|null null when there is no such log
     */
    public function tailOf(Server $server, string $path, int $bytes, int $lines): ?array
    {
        foreach ($this->sources($server) as $source) {
            if ($source['path'] !== $path) {
                continue;
            }

            $text = $this->fetch($server, $source, $bytes);
            if ($text === null) {
                return ['text' => '', 'note' => 'This log is too big to send.'];
            }
            $text = Text::clean($text);
            $all = explode("\n", $text);
            if (count($all) > $lines) {
                $text = implode("\n", array_slice($all, -$lines));
            }

            return ['text' => $text, 'note' => null];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array{path: string, name: string, live: bool, size: int, modified: ?string, entry: array<string, mixed>}
     */
    private function source(string $name, bool $live, array $entry): array
    {
        return [
            'path' => 'logs/'.$name,
            'name' => $name,
            'live' => $live,
            'size' => (int) ($entry['size'] ?? 0),
            'modified' => Times::fromListing($entry['modified'] ?? null),
            'entry' => $entry,
        ];
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function row(array $source, array $result): array
    {
        return [
            'path' => $source['path'],
            'name' => $source['name'],
            'live' => $source['live'],
            'size' => $source['size'],
            'modified' => $source['modified'],
            'status' => $result['status'],
            'note' => $result['note'],
            'entries' => $result['entries'],
        ];
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $index
     */
    private function key(Server $server, array $source, array $index): string
    {
        return 'ext:mc-crash:log:'.$server->uuid.':'.sha1($source['path'].'|'.$source['size'].'|'.($source['entry']['modified'] ?? '').'|'.$index['signature'].'|'.$this->engine->version()).($index['pending'] > 0 ? ':p' : '');
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $index
     * @return array<string, mixed>|null
     */
    private function cached(Server $server, array $source, array $index): ?array
    {
        $value = Cache::get($this->key($server, $source, $index));

        return is_array($value) ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $index
     * @return array<string, mixed>
     */
    private function process(Server $server, array $source, array $index): array
    {
        /** @var JarMap $map */
        $map = $index['map'];
        $result = ['status' => 'failed', 'note' => 'Could not read this log.', 'entries' => 0, 'groups' => []];
        $ttl = now()->addMinutes(5);

        $limit = $source['live'] ? self::MAX_LIVE_BYTES : self::MAX_GZ_BYTES;
        $note = null;

        if ($source['size'] === 0) {
            $result = ['status' => 'empty', 'note' => 'This log is empty.', 'entries' => 0, 'groups' => []];
            $ttl = now()->addDays(1);
        } elseif ($source['size'] > $limit) {
            $result = ['status' => 'too_large', 'note' => 'Too big to scan ('.$this->megabytes($source['size']).').', 'entries' => 0, 'groups' => []];
            $ttl = now()->addDays(1);

            if ($source['live']) {
                $console = $this->consoleTail($server, $map);
                if ($console !== null) {
                    $result = ['status' => 'console', 'note' => 'latest.log is '.$this->megabytes($source['size']).', too big to scan. The last 100 console lines were scanned instead.'] + $console;
                    $ttl = now()->addMinutes(1);
                }
            }
        } else {
            $text = $this->fetch($server, $source, self::KEEP_BYTES, $note);
            if ($text !== null) {
                $date = $source['live'] ? null : Times::dateFromLogName($source['name']);
                $anchor = 'start';
                if ($date === null) {
                    $date = $source['modified'] !== null ? substr($source['modified'], 0, 10) : null;
                    $anchor = 'end';
                }

                $scan = $this->engine->scanner()->scan($text, $map, $date, $date === null ? 'none' : $anchor);
                $result = ['status' => 'scanned', 'note' => $note] + $scan;
                $ttl = $source['live'] ? now()->addDays(1) : now()->addDays(30);
            }
        }

        $ttl = $index['pending'] > 0 && $result['status'] === 'scanned' ? now()->addMinutes(2) : $ttl;
        Cache::put($this->key($server, $source, $index), $result, $ttl);

        return $result;
    }

    /**
     * The text of a log, at most $bytes of its end. Null when it could not be read.
     *
     * @param  array<string, mixed>  $source
     */
    private function fetch(Server $server, array $source, int $bytes, ?string &$note = null): ?string
    {
        $limit = $source['live'] ? self::MAX_LIVE_BYTES : self::MAX_GZ_BYTES;
        $content = $this->files->read($server, '/'.$source['path'], $source['entry'], $limit);
        if ($content === null) {
            return null;
        }

        if ($source['live']) {
            if (strlen($content) > $bytes) {
                $note = 'Only the last '.intdiv($bytes, 1048576).' MB were scanned.';
                $content = Text::tail($content, $bytes);
            }

            return $content;
        }

        $tail = GzTail::tail($content, $bytes);
        if ($tail['error'] !== null && $tail['text'] === '') {
            return null;
        }
        if ($tail['truncated']) {
            $note = $tail['error'] ?? 'This log is very big; part of it was skipped.';
        } elseif ($tail['cut']) {
            $note = 'Only the last '.intdiv($bytes, 1048576).' MB were scanned.';
        }

        return $tail['text'];
    }

    /**
     * @return array{entries: int, groups: list<array<string, mixed>>, dropped: int}|null
     */
    private function consoleTail(Server $server, JarMap $map): ?array
    {
        try {
            $lines = $this->console->read($server, ReadsServerLogs::MAX_LINES);
        } catch (\Throwable) {
            return null;
        }

        return $lines === [] ? null : $this->engine->scanner()->scan(implode("\n", $lines), $map);
    }

    private function megabytes(int $bytes): string
    {
        return round($bytes / 1048576, 1).' MB';
    }
}
