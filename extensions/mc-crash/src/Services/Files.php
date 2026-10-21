<?php

namespace McCrash\Services;

use Pterodactyl\Contracts\Files\ListsDirectories;
use Pterodactyl\Contracts\Files\ReadsFileContents;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Exceptions\Http\Server\FileSizeTooLargeException;
use Pterodactyl\Models\Server;

/**
 * The file reads this extension needs. Wings answers a read or listing of something that does not exist with
 * a 500, so a folder is only listed after its parent has shown it, and a file is only read after a listing has
 * shown it. A read is also refused when the listing says the file is bigger than allowed, because the panel
 * would otherwise download all of it before it could say no.
 */
class Files
{
    /** @var array<string, list<array<string, mixed>>> per request */
    private array $listings = [];

    public function __construct(
        private readonly ListsDirectories $lists,
        private readonly ReadsFileContents $reader,
    ) {}

    /**
     * Entries of the server root. A node that is down throws.
     *
     * @return list<array<string, mixed>>
     *
     * @throws DaemonConnectionException
     */
    public function root(Server $server): array
    {
        return $this->listings[$server->uuid.':/'] ??= array_values($this->lists->list($server, '/'));
    }

    /**
     * Entries of a folder directly inside the root; a folder that is not there counts as empty.
     *
     * @return list<array<string, mixed>>
     *
     * @throws DaemonConnectionException
     */
    public function folder(Server $server, string $name): array
    {
        $key = $server->uuid.':/'.$name;
        if (isset($this->listings[$key])) {
            return $this->listings[$key];
        }

        $exists = false;
        foreach ($this->root($server) as $entry) {
            if (($entry['name'] ?? null) === $name && ($entry['file'] ?? true) === false && ! self::isLink($entry)) {
                $exists = true;

                break;
            }
        }
        if (! $exists) {
            return $this->listings[$key] = [];
        }

        try {
            return $this->listings[$key] = array_values($this->lists->list($server, '/'.$name));
        } catch (DaemonConnectionException $exception) {
            // Gone between the two calls, or not readable: an optional folder is simply empty then.
            if (in_array($exception->getStatusCode(), [404, 500], true)) {
                return $this->listings[$key] = [];
            }

            throw $exception;
        }
    }

    /**
     * Read a file that a listing has shown. Null when it is bigger than $max or could not be read.
     *
     * @param  array<string, mixed>  $entry  the file's listing entry
     *
     * @throws DaemonConnectionException
     */
    public function read(Server $server, string $path, array $entry, int $max): ?string
    {
        if ((int) ($entry['size'] ?? 0) > $max) {
            return null;
        }

        try {
            return $this->reader->read($server, $path, $max);
        } catch (FileSizeTooLargeException) {
            return null;
        } catch (DaemonConnectionException $exception) {
            // The file vanished between the listing and the read; a node that is down is still reported.
            if (in_array($exception->getStatusCode(), [404, 500], true)) {
                return null;
            }

            throw $exception;
        }
    }

    /**
     * A symbolic link in a listing. Links are never followed: one can point out of the server's folder.
     *
     * @param  array<string, mixed>  $entry
     */
    public static function isLink(array $entry): bool
    {
        return ($entry['symlink'] ?? false) === true;
    }

    /**
     * A real file of a listing, by name: not a folder and not a link.
     *
     * @param  array<string, mixed>  $entry
     */
    public static function isPlainFile(array $entry): bool
    {
        return ($entry['file'] ?? true) !== false && ! self::isLink($entry);
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return array<string, mixed>|null
     */
    public static function file(array $entries, string $name): ?array
    {
        foreach ($entries as $entry) {
            if (($entry['name'] ?? null) === $name && self::isPlainFile($entry)) {
                return $entry;
            }
        }

        return null;
    }
}
