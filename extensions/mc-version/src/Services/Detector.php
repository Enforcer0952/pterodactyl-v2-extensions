<?php

namespace McVersion\Services;

use Illuminate\Support\Facades\Cache;
use McVersion\Support\Detection;
use Pterodactyl\Contracts\Files\ReadsFileContents;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Exceptions\Http\Server\FileSizeTooLargeException;
use Pterodactyl\Models\Server;

/**
 * Reads the few files that tell what a server runs and hands them to Detection. Only
 * files that a listing shows are read; Wings answers a missing file with a 500.
 */
class Detector
{
    private const int MAX_LOG_BYTES = 2 * 1024 * 1024;

    public function __construct(
        private readonly ServerFiles $files,
        private readonly ReadsFileContents $reader,
    ) {}

    /**
     * @return array{software: ?string, version: ?string, build: ?string, source: ?string, log_modified: ?int}
     *
     * @throws DaemonConnectionException when the node cannot be reached
     */
    public function detect(Server $server, bool $fresh = false): array
    {
        $key = 'ext:mc-version:detected:'.$server->uuid;
        if (! $fresh && is_array($cached = Cache::get($key))) {
            return $cached;
        }

        $this->files->forget($server);
        $root = $this->files->list($server, '/') ?? [];
        $names = ServerFiles::names($root);
        $has = fn (string $name, bool $directory): bool => array_filter(
            $root,
            fn (array $entry): bool => ($entry['name'] ?? '') === $name && (($entry['file'] ?? true) === false) === $directory,
        ) !== [];

        $libraries = $has('libraries', true) ? ServerFiles::names($this->files->list($server, '/libraries/net')) : [];
        $config = $has('config', true) ? ServerFiles::names($this->files->list($server, '/config')) : [];

        $versions = [];
        if ($has('versions', true)) {
            foreach ($this->files->list($server, '/versions') ?? [] as $entry) {
                if (($entry['file'] ?? true) === false) {
                    $versions[] = ['name' => (string) ($entry['name'] ?? ''), 'modified' => self::time($entry['modified'] ?? null) ?? 0];
                }
            }
        }

        $history = $has('version_history.json', false) ? $this->read($server, '/version_history.json', 64 * 1024) : null;

        $log = null;
        $logModified = null;
        if ($has('logs', true)) {
            $entry = ServerFiles::file($this->files->list($server, '/logs'), 'latest.log');
            if ($entry !== null) {
                $logModified = self::time($entry['modified'] ?? null);
                if ((int) ($entry['size'] ?? 0) <= self::MAX_LOG_BYTES) {
                    $log = $this->read($server, '/logs/latest.log', self::MAX_LOG_BYTES);
                }
            }
        }

        $result = Detection::analyse([
            'root' => $names,
            'libraries' => $libraries,
            'config' => $config,
            'versions' => $versions,
            'log' => $log,
            'history' => $history,
        ]) + ['log_modified' => $logModified];

        Cache::put($key, $result, 30);

        return $result;
    }

    public function forget(Server $server): void
    {
        Cache::forget('ext:mc-version:detected:'.$server->uuid);
    }

    /** A file that is too large or unreadable counts as absent; a node that is down does not. */
    private function read(Server $server, string $path, int $max): ?string
    {
        try {
            return $this->reader->read($server, $path, $max);
        } catch (FileSizeTooLargeException) {
            return null;
        } catch (DaemonConnectionException $exception) {
            if (in_array($exception->getStatusCode(), [404, 500], true)) {
                return null;
            }

            throw $exception;
        }
    }

    private static function time(mixed $value): ?int
    {
        $time = is_string($value) ? strtotime($value) : false;

        return $time === false ? null : $time;
    }
}
