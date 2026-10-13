<?php

namespace McContent\Services;

use Illuminate\Support\Facades\Cache;
use McContent\Support\ContentKind;
use McContent\Support\Platform;
use Pterodactyl\Contracts\Files\ListsDirectories;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Models\Server;

/**
 * Directory listings that tell a missing folder apart from a failing node. Some
 * Wings versions answer a listing of a folder that does not exist with a 500
 * instead of a 404, so the parent is listed first and only folders that exist
 * are opened.
 */
class ServerFiles
{
    /** @var array<string, list<array<string, mixed>>> per request */
    private array $listings = [];

    public function __construct(private readonly ListsDirectories $lister) {}

    /**
     * Entries of a folder, or null when the folder does not exist.
     *
     * @return list<array<string, mixed>>|null
     *
     * @throws DaemonConnectionException when the node cannot be reached
     */
    public function list(Server $server, string $directory): ?array
    {
        $directory = '/'.trim($directory, '/');
        $key = $server->uuid.$directory;
        if (array_key_exists($key, $this->listings)) {
            return $this->listings[$key];
        }

        if ($directory !== '/') {
            $parent = $this->list($server, dirname($directory));
            $name = basename($directory);
            $exists = $parent !== null && array_filter(
                $parent,
                fn (array $entry): bool => ($entry['name'] ?? '') === $name && ($entry['file'] ?? true) === false,
            ) !== [];

            if (! $exists) {
                return $this->listings[$key] = null;
            }
        }

        try {
            return $this->listings[$key] = $this->lister->list($server, $directory);
        } catch (DaemonConnectionException $exception) {
            if ($exception->getStatusCode() === 404) {
                return $this->listings[$key] = null;
            }

            throw $exception;
        }
    }

    /** Forget cached listings, after creating or deleting something. */
    public function forget(Server $server): void
    {
        foreach (array_keys($this->listings) as $key) {
            if (str_starts_with($key, $server->uuid)) {
                unset($this->listings[$key]);
            }
        }
    }

    /**
     * What the server actually runs, from its files. Kept for ten minutes; a server
     * that tells nothing yet (never started) is checked again after one.
     *
     * @return array{loader: string, kind: string}|null
     */
    public function platform(Server $server): ?array
    {
        $key = 'ext:mc-content:platform:'.$server->uuid;
        $cached = Cache::get($key);
        if ($cached !== null) {
            return $cached === false ? null : $cached;
        }

        try {
            $root = $this->names($this->list($server, '/'));
            $libraries = in_array('libraries', $root, true) ? $this->names($this->list($server, '/libraries/net')) : [];
            $config = in_array('config', $root, true) ? $this->names($this->list($server, '/config')) : [];
        } catch (DaemonConnectionException) {
            return null; // Node unreachable: say nothing, and do not remember it.
        }

        $platform = Platform::classify($root, $libraries, $config);
        Cache::put($key, $platform ?? false, $platform === null ? 60 : 600);

        return $platform;
    }

    /** The loader for this kind of content: what the files say, when it is this kind. */
    public function loader(Server $server, ContentKind $kind): ?string
    {
        $platform = $this->platform($server);
        if ($platform !== null && $platform['kind'] === $kind->value) {
            return $platform['loader'];
        }

        return $kind->detectLoader($server);
    }

    /**
     * @param  list<array<string, mixed>>|null  $entries
     * @return list<string>
     */
    private function names(?array $entries): array
    {
        return array_values(array_map(fn (array $entry): string => (string) ($entry['name'] ?? ''), $entries ?? []));
    }
}
