<?php

namespace McCrash\Services;

use Illuminate\Support\Facades\Cache;
use McCrash\Support\CrashSections;
use McCrash\Support\JarMap;
use McCrash\Support\Text;
use McCrash\Support\Times;
use Pterodactyl\Models\Server;

/**
 * The crash reports of a server: crash-reports/*.txt, JVM crashes (hs_err_pid*.log in the root) and, on Forge and
 * NeoForge, the failure section of logs/debug.log. Each file is read once and its verdict kept per path,
 * size and modification time.
 */
class CrashReports
{
    /** A report bigger than this is not read. */
    public const int MAX_REPORT_BYTES = 3 * 1024 * 1024;

    /** logs/debug.log is read whole up to this size, because only the panel can ask for a file as a whole. */
    private const int MAX_DEBUG_BYTES = 12 * 1024 * 1024;

    private const int MAX_ITEMS = 40;

    private const int MAX_READS = 12;

    private const int MAX_SECONDS = 20;

    public function __construct(
        private readonly Files $files,
        private readonly Engine $engine,
    ) {}

    /**
     * Every report file, newest first, without reading any of them.
     *
     * @return list<array{path: string, name: string, kind: string, size: int, modified: ?string, sort: string, entry: array<string, mixed>}>
     */
    public function candidates(Server $server): array
    {
        $items = [];

        foreach ($this->files->root($server) as $entry) {
            $name = (string) ($entry['name'] ?? '');
            if (Files::isPlainFile($entry) && preg_match('/^hs_err_pid\d{1,10}\.log$/D', $name) === 1) {
                $items[] = $this->item($name, $name, 'jvm', $entry);
            }
        }

        foreach ($this->files->folder($server, 'crash-reports') as $entry) {
            $name = (string) ($entry['name'] ?? '');
            if (Files::isPlainFile($entry) && preg_match('/^[\w.\-+ ]{1,120}\.txt$/D', $name) === 1) {
                $items[] = $this->item('crash-reports/'.$name, $name, 'crash', $entry);
            }
        }

        usort($items, fn (array $a, array $b): int => strcmp($b['sort'], $a['sort']));
        $items = array_slice($items, 0, self::MAX_ITEMS);

        $debug = Files::file($this->files->folder($server, 'logs'), 'debug.log');
        if ($debug !== null && (int) ($debug['size'] ?? 0) > 0) {
            $items[] = $this->item('logs/debug.log', 'debug.log', 'log', $debug);
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $index  JarIndex::forServer()
     * @return array{reports: list<array<string, mixed>>, pending: int}
     */
    public function list(Server $server, array $index): array
    {
        $started = microtime(true);
        $reads = 0;
        $pending = 0;
        $reports = [];

        foreach ($this->candidates($server) as $candidate) {
            $result = $this->cached($server, $candidate, $index);

            if ($result === null) {
                if ($reads >= self::MAX_READS || microtime(true) - $started > self::MAX_SECONDS) {
                    $pending++;
                    $reports[] = $this->row($candidate, ['status' => 'pending', 'verdict' => null, 'time' => null]);

                    continue;
                }
                $reads++;
                $result = $this->analyse($server, $candidate, $index);
            }

            if ($result['status'] === 'none') {
                continue;
            }
            $reports[] = $this->row($candidate, $result);
        }

        usort($reports, fn (array $a, array $b): int => strcmp((string) $b['time'], (string) $a['time']));

        return ['reports' => $reports, 'pending' => $pending];
    }

    /**
     * The raw text of one report, for showing and sharing. The path must be one of the listed report files.
     *
     * @return array{text: string, truncated: bool}|null null when there is no such report
     */
    public function raw(Server $server, string $path, int $max): ?array
    {
        $candidate = $this->find($server, $path);
        if ($candidate === null) {
            return null;
        }

        $limit = $candidate['name'] === 'debug.log' ? self::MAX_DEBUG_BYTES : self::MAX_REPORT_BYTES;
        $text = $this->files->read($server, '/'.$candidate['path'], $candidate['entry'], $limit);
        if ($text === null) {
            return ['text' => '', 'truncated' => true];
        }

        if ($candidate['name'] === 'debug.log') {
            $section = CrashSections::find($text);
            $text = $section === null ? '' : $section['text'];
        }

        $text = Text::clean($text);
        $truncated = strlen($text) > $max;

        // mb_strcut cuts on a character boundary, so a multi-byte character is never split.
        return ['text' => $truncated ? mb_strcut($text, 0, $max, 'UTF-8') : $text, 'truncated' => $truncated];
    }

    /**
     * @return array{path: string, name: string, kind: string, size: int, modified: ?string, sort: string, entry: array<string, mixed>}|null
     */
    public function find(Server $server, string $path): ?array
    {
        foreach ($this->candidates($server) as $candidate) {
            if ($candidate['path'] === $path) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array{path: string, name: string, kind: string, size: int, modified: ?string, sort: string, entry: array<string, mixed>}
     */
    private function item(string $path, string $name, string $kind, array $entry): array
    {
        $modified = Times::fromListing($entry['modified'] ?? null);

        return [
            'path' => $path,
            'name' => $name,
            'kind' => $kind,
            'size' => (int) ($entry['size'] ?? 0),
            'modified' => $modified,
            'sort' => Times::fromCrashName($name) ?? $modified ?? '',
            'entry' => $entry,
        ];
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function row(array $candidate, array $result): array
    {
        return [
            'path' => $candidate['path'],
            'name' => $candidate['name'],
            'kind' => $candidate['kind'],
            'size' => $candidate['size'],
            'modified' => $candidate['modified'],
            'time' => $result['time'] ?? $candidate['sort'],
            'status' => $result['status'],
            'verdict' => $result['verdict'],
        ];
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $index
     */
    private function key(Server $server, array $candidate, array $index): string
    {
        return 'ext:mc-crash:report:'.$server->uuid.':'.sha1($candidate['path'].'|'.$candidate['size'].'|'.($candidate['entry']['modified'] ?? '').'|'.$index['signature'].'|'.$this->engine->version());
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $index
     * @return array<string, mixed>|null
     */
    private function cached(Server $server, array $candidate, array $index): ?array
    {
        $value = Cache::get($this->key($server, $candidate, $index).($index['pending'] > 0 ? ':p' : ''));

        return is_array($value) ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $index
     * @return array<string, mixed>
     */
    private function analyse(Server $server, array $candidate, array $index): array
    {
        $isDebug = $candidate['name'] === 'debug.log';
        $limit = $isDebug ? self::MAX_DEBUG_BYTES : self::MAX_REPORT_BYTES;
        $result = ['status' => 'unreadable', 'verdict' => null, 'time' => null];
        $ttl = now()->addMinutes(5);

        if ($candidate['size'] > $limit) {
            $result['status'] = $isDebug ? 'none' : 'too_large';
            $ttl = now()->addDays(30);
        } else {
            $text = $this->files->read($server, '/'.$candidate['path'], $candidate['entry'], $limit);
            if ($text !== null) {
                $time = null;
                if ($isDebug) {
                    $section = CrashSections::find($text);
                    $text = $section['text'] ?? '';
                    $time = $section['time'] ?? null;
                }

                if ($text === '') {
                    $result['status'] = 'none';
                } else {
                    /** @var JarMap $map */
                    $map = $index['map'];
                    $verdict = $this->engine->analyzer()->analyze($text, $map);
                    unset($verdict['stat'], $verdict['key']);
                    $result = ['status' => 'ok', 'verdict' => $verdict, 'time' => $verdict['time'] ?? $time];
                }
                $ttl = now()->addDays(30);
            }
        }

        // While jars are still being read the culprit may improve, so the answer is kept briefly.
        $suffix = $index['pending'] > 0 ? ':p' : '';
        Cache::put($this->key($server, $candidate, $index).$suffix, $result, $index['pending'] > 0 && $result['status'] === 'ok' ? now()->addMinutes(2) : $ttl);

        return $result;
    }
}
