<?php

namespace ValheimTools\Services;

use Carbon\CarbonImmutable;
use Pterodactyl\Contracts\Files\CompressesFiles;
use Pterodactyl\Contracts\Files\CreatesDirectories;
use Pterodactyl\Contracts\Files\DecompressesFiles;
use Pterodactyl\Contracts\Files\DeletesFiles;
use Pterodactyl\Contracts\Files\ListsDirectories;
use Pterodactyl\Contracts\Files\PullsFiles;
use Pterodactyl\Contracts\Files\ReadsFileContents;
use Pterodactyl\Contracts\Files\RenamesFiles;
use Pterodactyl\Contracts\Files\WritesFileContents;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Exceptions\Http\Server\FileSizeTooLargeException;
use Pterodactyl\Models\Server;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;
use ValheimTools\Support\Valheim;

/**
 * The server's files, through the panel's own file actions. A missing folder or file
 * is never read blindly: Wings answers that with a 500 on some versions, so the folder
 * is listed first and only what is there gets read.
 *
 * Wings has no way to copy into another folder, so a copy is made by packing the files,
 * moving the archive and unpacking it there.
 */
class ServerFiles
{
    private const int KEEP_BACKUPS = 5;

    /** @var array<string, list<array{name: string, file: bool, size: int, modified: ?string}>> */
    private array $listings = [];

    public function __construct(
        private readonly ReadsFileContents $reader,
        private readonly WritesFileContents $writer,
        private readonly ListsDirectories $directories,
        private readonly DeletesFiles $deleter,
        private readonly RenamesFiles $renamer,
        private readonly CreatesDirectories $creator,
        private readonly CompressesFiles $compressor,
        private readonly DecompressesFiles $decompressor,
        private readonly PullsFiles $puller,
    ) {}

    /**
     * Files and folders in a folder. A folder that does not exist is empty.
     *
     * @return list<array{name: string, file: bool, size: int, modified: ?string}>
     */
    public function entries(Server $server, string $directory): array
    {
        $key = $server->uuid.'|'.$directory;
        if (! isset($this->listings[$key])) {
            $this->listings[$key] = $this->list($server, $directory);
        }

        return $this->listings[$key];
    }

    /**
     * @return list<array{name: string, file: bool, size: int, modified: ?string}>
     */
    public function files(Server $server, string $directory): array
    {
        return array_values(array_filter($this->entries($server, $directory), fn (array $entry): bool => $entry['file']));
    }

    /**
     * @return list<array{name: string, file: bool, size: int, modified: ?string}>
     */
    public function folders(Server $server, string $directory): array
    {
        return array_values(array_filter($this->entries($server, $directory), fn (array $entry): bool => ! $entry['file']));
    }

    public function directoryExists(Server $server, string $directory): bool
    {
        if ($directory === '/') {
            return true;
        }

        foreach ($this->entries($server, dirname($directory) === '.' ? '/' : dirname($directory)) as $entry) {
            if (! $entry['file'] && $entry['name'] === basename($directory)) {
                return true;
            }
        }

        return false;
    }

    public function exists(Server $server, string $directory, string $name): bool
    {
        return $this->entry($server, $directory, $name) !== null;
    }

    /**
     * @return array{name: string, file: bool, size: int, modified: ?string}|null
     */
    public function entry(Server $server, string $directory, string $name): ?array
    {
        foreach ($this->entries($server, $directory) as $entry) {
            if ($entry['name'] === $name) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * The size of a folder: its files, and the folders in it down to $depth levels.
     * Wings reports a folder's own size, not what is inside, so it is added up here.
     */
    public function folderSize(Server $server, string $directory, int $depth = 2): int
    {
        $total = 0;
        foreach ($this->entries($server, $directory) as $entry) {
            if ($entry['file']) {
                $total += $entry['size'];
            } elseif ($depth > 0 && Valheim::plainName($entry['name'])) {
                $total += $this->folderSize($server, $this->path($directory, $entry['name']), $depth - 1);
            }
        }

        return $total;
    }

    /** The text of a file, or null when it does not exist. */
    public function read(Server $server, string $directory, string $name, int $max): ?string
    {
        $entry = $this->entry($server, $directory, $name);
        if ($entry === null || ! $entry['file']) {
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
        $this->ensureDirectory($server, $directory);
        $this->writer->write($server, $this->path($directory, $name), $contents);
        $this->forget($server, $directory);
    }

    /**
     * Save a text file after keeping the old one as "<name>.<date>.bak" next to it.
     * The newest few backups stay.
     */
    public function writeWithBackup(Server $server, string $directory, string $name, string $contents, ?string $current): void
    {
        if ($current !== null) {
            $stamp = CarbonImmutable::now('UTC')->format('Ymd-His');
            $this->write($server, $directory, $name.'.'.$stamp.'.bak', $current);
        }
        $this->write($server, $directory, $name, $contents);
        $this->prune($server, $directory, $name);
    }

    /**
     * @param  list<string>  $names  files or folders directly inside $directory
     */
    public function delete(Server $server, string $directory, array $names): void
    {
        foreach ($names as $name) {
            if (! Valheim::plainName($name)) {
                throw new HttpException(422, 'Refusing to delete a file with an unsafe name.');
            }
        }
        if ($names !== []) {
            $this->deleter->delete($server, $directory, $names);
            $this->forget($server, $directory);
        }
    }

    public function move(Server $server, string $directory, string $name, string $targetDirectory, string $targetName): void
    {
        $this->renamer->rename($server, '/', [[
            'from' => ltrim($this->path($directory, $name), '/'),
            'to' => ltrim($this->path($targetDirectory, $targetName), '/'),
        ]]);
        $this->forget($server, $directory);
        $this->forget($server, $targetDirectory);
    }

    /**
     * Copy files or folders from one folder to another, keeping their names.
     *
     * @param  list<string>  $names
     */
    public function copyEntries(Server $server, string $directory, array $names, string $targetDirectory): void
    {
        foreach ($names as $name) {
            if (! Valheim::plainName($name)) {
                throw new HttpException(422, 'Refusing to copy a file with an unsafe name.');
            }
        }
        if ($names === []) {
            throw new HttpException(422, 'There is nothing to copy.');
        }

        $this->ensureDirectory($server, $targetDirectory);

        $archive = $this->compressor->compress($server, $directory, $names);
        $archiveName = (string) ($archive['name'] ?? '');
        $this->forget($server, $directory);
        if (! Valheim::plainName($archiveName) || preg_match('/\.(tar\.gz|zip|tgz)$/i', $archiveName) !== 1) {
            throw new HttpException(502, 'The panel could not pack the files.');
        }

        $moved = false;
        try {
            $this->move($server, $directory, $archiveName, $targetDirectory, $archiveName);
            $moved = true;
            $this->decompressor->decompress($server, $targetDirectory, $archiveName);
        } catch (Throwable $exception) {
            // Do not leave the archive behind.
            try {
                $this->delete($server, $moved ? $targetDirectory : $directory, [$archiveName]);
            } catch (Throwable) {
            }

            throw $exception;
        }

        $this->delete($server, $targetDirectory, [$archiveName]);
        $this->forget($server, $targetDirectory);
    }

    /** Download a file into a folder. The panel's pull action records it in the activity log. */
    public function pull(Server $server, string $url, string $directory, string $filename): void
    {
        $this->ensureDirectory($server, $directory);
        $this->puller->pull($server, $url, $directory, ['filename' => $filename, 'foreground' => true, 'timeout' => 120]);
        $this->forget($server, $directory);
    }

    public function decompress(Server $server, string $directory, string $file): void
    {
        $this->decompressor->decompress($server, $directory, $file);
        $this->forget($server, $directory);
    }

    /** Create a folder (and the ones above it) when it is missing. */
    public function ensureDirectory(Server $server, string $directory): void
    {
        $built = '';
        foreach (array_filter(explode('/', $directory), fn (string $part): bool => $part !== '') as $part) {
            $parent = $built === '' ? '/' : $built;
            $built .= '/'.$part;
            if (! $this->directoryExists($server, $built)) {
                $this->creator->create($server, $part, $parent);
                $this->forget($server, $parent);
            }
        }
    }

    public function forget(Server $server, string $directory): void
    {
        unset($this->listings[$server->uuid.'|'.$directory]);
    }

    public function path(string $directory, string $name): string
    {
        return rtrim($directory, '/').'/'.$name;
    }

    /** Old backups are removed best effort: a failure here never fails the save. */
    private function prune(Server $server, string $directory, string $name): void
    {
        try {
            $backups = array_values(array_filter(
                array_column($this->files($server, $directory), 'name'),
                fn (string $file): bool => str_starts_with($file, $name.'.') && preg_match('/\.\d{8}-\d{6}\.bak$/', $file) === 1,
            ));
            rsort($backups);
            $this->delete($server, $directory, array_slice($backups, self::KEEP_BACKUPS));
        } catch (Throwable) {
            // Keeping an extra backup is harmless.
        }
    }

    /**
     * @return list<array{name: string, file: bool, size: int, modified: ?string}>
     */
    private function list(Server $server, string $directory): array
    {
        try {
            $entries = $this->directories->list($server, $directory);
        } catch (DaemonConnectionException $exception) {
            // A folder that does not exist yet: some Wings versions answer 500 instead of 404.
            // A node that is down answers 502/504 and is reported as such.
            if (in_array($exception->getStatusCode(), [404, 500], true)) {
                return [];
            }

            throw $exception;
        }

        $result = [];
        foreach ($entries as $entry) {
            $result[] = [
                'name' => (string) ($entry['name'] ?? ''),
                'file' => ($entry['file'] ?? true) !== false,
                'size' => (int) ($entry['size'] ?? 0),
                'modified' => isset($entry['modified']) ? (string) $entry['modified'] : null,
            ];
        }

        return $result;
    }
}
