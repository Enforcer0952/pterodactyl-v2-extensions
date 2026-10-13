<?php

namespace McVersion\Services;

use Pterodactyl\Contracts\Files\ListsDirectories;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Models\Server;

/**
 * Directory listings that tell a missing folder apart from a failing node. Wings
 * answers a listing of a folder that does not exist with a 500 instead of a 404 on
 * some versions, so the parent is listed first and only folders that exist are opened.
 */
class ServerFiles
{
    /** @var array<string, list<array<string, mixed>>|null> per request */
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

    /** Forget listings, after changing something. */
    public function forget(Server $server): void
    {
        foreach (array_keys($this->listings) as $key) {
            if (str_starts_with($key, $server->uuid)) {
                unset($this->listings[$key]);
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>|null  $entries
     * @return list<string>
     */
    public static function names(?array $entries): array
    {
        return array_values(array_map(fn (array $entry): string => (string) ($entry['name'] ?? ''), $entries ?? []));
    }

    /**
     * The entry for a plain file in a listing, or null.
     *
     * @param  list<array<string, mixed>>|null  $entries
     * @return array<string, mixed>|null
     */
    public static function file(?array $entries, string $name): ?array
    {
        foreach ($entries ?? [] as $entry) {
            if (($entry['name'] ?? null) === $name && ($entry['file'] ?? false) === true) {
                return $entry;
            }
        }

        return null;
    }
}
