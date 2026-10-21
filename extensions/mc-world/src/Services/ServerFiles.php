<?php

namespace McWorld\Services;

use Pterodactyl\Contracts\Files\CompressesFiles;
use Pterodactyl\Contracts\Files\CreatesDirectories;
use Pterodactyl\Contracts\Files\DecompressesFiles;
use Pterodactyl\Contracts\Files\DeletesFiles;
use Pterodactyl\Contracts\Files\ListsDirectories;
use Pterodactyl\Contracts\Files\ReadsFileContents;
use Pterodactyl\Contracts\Files\RenamesFiles;
use Pterodactyl\Contracts\Files\WritesFileContents;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Models\Server;

/**
 * The few file operations this extension needs, on top of the panel's own file actions
 * (which write the activity log). Listings come first: Wings answers a read of a missing
 * file or folder with a 500, so nothing is read without being seen in a listing.
 */
class ServerFiles
{
    public function __construct(
        private readonly ListsDirectories $lists,
        private readonly ReadsFileContents $reader,
        private readonly WritesFileContents $writer,
        private readonly DeletesFiles $deleter,
        private readonly RenamesFiles $renamer,
        private readonly CompressesFiles $compressor,
        private readonly DecompressesFiles $decompressor,
        private readonly CreatesDirectories $directories,
    ) {}

    /**
     * A folder's entries. A folder that does not exist (or cannot be listed because it
     * does not) counts as empty; a node that is down is reported.
     *
     * @return list<array<string, mixed>>
     */
    public function entries(Server $server, string $directory = '/'): array
    {
        try {
            return array_values($this->lists->list($server, $directory));
        } catch (DaemonConnectionException $exception) {
            if (in_array($exception->getStatusCode(), [404, 500], true)) {
                return [];
            }

            throw $exception;
        }
    }

    /**
     * A folder's entries, and any error is thrown. Used for the server root before anything
     * is moved or deleted: an empty list from a failed call would look like "no world here".
     *
     * @return list<array<string, mixed>>
     *
     * @throws DaemonConnectionException
     */
    public function strictEntries(Server $server, string $directory = '/'): array
    {
        return array_values($this->lists->list($server, $directory));
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return array<string, mixed>|null
     */
    public static function find(array $entries, string $name, bool $file): ?array
    {
        foreach ($entries as $entry) {
            if (($entry['name'] ?? null) === $name && (($entry['file'] ?? true) !== false) === $file) {
                return $entry;
            }
        }

        return null;
    }

    public function read(Server $server, string $path, int $maxBytes): string
    {
        return $this->reader->read($server, $path, $maxBytes);
    }

    public function write(Server $server, string $path, string $contents): void
    {
        $this->writer->write($server, $path, $contents);
    }

    /** @param list<string> $names names inside $directory */
    public function delete(Server $server, string $directory, array $names): void
    {
        $this->deleter->delete($server, $directory, $names);
    }

    public function rename(Server $server, string $directory, string $from, string $to): void
    {
        $this->renamer->rename($server, $directory, [['from' => $from, 'to' => $to]]);
    }

    /**
     * Archive folders of the server root. Wings names the archive itself and puts it in
     * the root; the name it picked is returned.
     *
     * @param  list<string>  $names
     */
    public function compress(Server $server, array $names): string
    {
        $archive = $this->compressor->compress($server, '/', $names);

        return (string) ($archive['name'] ?? '');
    }

    public function decompress(Server $server, string $directory, string $file): void
    {
        $this->decompressor->decompress($server, $directory, $file);
    }

    public function createDirectory(Server $server, string $name): void
    {
        $this->directories->create($server, $name, '/');
    }
}
