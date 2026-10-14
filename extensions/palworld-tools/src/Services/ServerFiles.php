<?php

namespace PalworldTools\Services;

use Pterodactyl\Contracts\Files\CompressesFiles;
use Pterodactyl\Contracts\Files\CreatesDirectories;
use Pterodactyl\Contracts\Files\DecompressesFiles;
use Pterodactyl\Contracts\Files\DeletesFiles;
use Pterodactyl\Contracts\Files\ListsDirectories;
use Pterodactyl\Contracts\Files\ReadsFileContents;
use Pterodactyl\Contracts\Files\RenamesFiles;
use Pterodactyl\Contracts\Files\WritesFileContents;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Exceptions\Http\Server\FileSizeTooLargeException;
use Pterodactyl\Models\Server;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The few file operations this extension needs, on top of the panel's own file actions
 * (which write the activity log). Wings answers a read or listing of a missing file or
 * folder with a 500 on some versions, so folders are listed first and only files seen
 * in a listing are read.
 */
class ServerFiles
{
    /** @var array<string, list<array<string, mixed>>> */
    private array $cache = [];

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
     * A folder's entries. A folder that does not exist counts as empty; a node that is
     * down is reported.
     *
     * @return list<array<string, mixed>>
     */
    public function entries(Server $server, string $directory): array
    {
        $key = $server->uuid.'|'.$directory;
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        try {
            return $this->cache[$key] = array_values($this->lists->list($server, $directory));
        } catch (DaemonConnectionException $exception) {
            if (in_array($exception->getStatusCode(), [404, 500], true)) {
                return [];
            }

            throw $exception;
        }
    }

    /** @return array<string, mixed>|null */
    public function find(Server $server, string $directory, string $name, bool $file): ?array
    {
        foreach ($this->entries($server, $directory) as $entry) {
            if (($entry['name'] ?? null) === $name && (($entry['file'] ?? true) !== false) === $file) {
                return $entry;
            }
        }

        return null;
    }

    public function exists(Server $server, string $directory, string $name, bool $file = true): bool
    {
        return $this->find($server, $directory, $name, $file) !== null;
    }

    /** A text file's contents, or null when it does not exist. */
    public function read(Server $server, string $directory, string $name, int $max): ?string
    {
        if (! $this->exists($server, $directory, $name)) {
            return null;
        }

        try {
            return $this->reader->read($server, $this->path($directory, $name), $max);
        } catch (FileSizeTooLargeException) {
            throw new HttpException(422, $name.' is too large to edit here. Use the file manager.');
        } catch (DaemonConnectionException $exception) {
            if ($exception->getStatusCode() === 404) {
                return null;
            }

            throw $exception;
        }
    }

    public function write(Server $server, string $directory, string $name, string $contents): void
    {
        $this->writer->write($server, $this->path($directory, $name), $contents);
        $this->forget();
    }

    /** @param list<string> $names names inside $directory */
    public function delete(Server $server, string $directory, array $names): void
    {
        if ($names !== []) {
            $this->deleter->delete($server, $directory, $names);
            $this->forget();
        }
    }

    /** Move or rename; both paths are relative to the server root. */
    public function move(Server $server, string $from, string $to): void
    {
        $this->renamer->rename($server, '/', [['from' => ltrim($from, '/'), 'to' => ltrim($to, '/')]]);
        $this->forget();
    }

    /**
     * Archive entries of one folder. Wings names the archive itself and puts it in that
     * folder; the name it picked is returned.
     *
     * @param  list<string>  $names
     */
    public function compress(Server $server, string $directory, array $names): string
    {
        $archive = $this->compressor->compress($server, $directory, $names);
        $this->forget();
        $name = (string) ($archive['name'] ?? '');
        if ($name === '' || str_contains($name, '/') || str_contains($name, '..')) {
            throw new HttpException(502, 'The node did not say where it put the archive.');
        }

        return $name;
    }

    /** Wings unpacks an archive into the folder it is in. */
    public function decompress(Server $server, string $directory, string $file): void
    {
        $this->decompressor->decompress($server, $directory, $file);
        $this->forget();
    }

    /** Create a folder and the ones above it when they are missing. */
    public function ensureDirectory(Server $server, string $directory): void
    {
        $built = '';
        foreach (array_filter(explode('/', $directory), fn (string $part): bool => $part !== '') as $part) {
            $parent = $built === '' ? '/' : $built;
            $built .= '/'.$part;
            if (! $this->exists($server, $parent, $part, false)) {
                $this->directories->create($server, $part, $parent);
                $this->forget();
            }
        }
    }

    public function forget(): void
    {
        $this->cache = [];
    }

    public function path(string $directory, string $name): string
    {
        return rtrim($directory, '/').'/'.$name;
    }
}
