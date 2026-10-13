<?php

namespace TerrariaTools\Services;

use Carbon\CarbonImmutable;
use Pterodactyl\Contracts\Files\CopiesFiles;
use Pterodactyl\Contracts\Files\CreatesDirectories;
use Pterodactyl\Contracts\Files\DeletesFiles;
use Pterodactyl\Contracts\Files\ListsDirectories;
use Pterodactyl\Contracts\Files\ReadsFileContents;
use Pterodactyl\Contracts\Files\RenamesFiles;
use Pterodactyl\Contracts\Files\WritesFileContents;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Exceptions\Http\Server\FileSizeTooLargeException;
use Pterodactyl\Models\Server;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * The server's files, through the panel's own file actions. A missing folder or file
 * is never read blindly: Wings answers that with a 500 on some versions, so the
 * folder is listed first and only files that are there get read.
 */
class ServerFiles
{
    private const int KEEP_BACKUPS = 5;

    /** @var array<string, list<array{name: string, size: int, modified: ?string}>> folder => files, per request */
    private array $listings = [];

    public function __construct(
        private readonly ReadsFileContents $reader,
        private readonly WritesFileContents $writer,
        private readonly ListsDirectories $directories,
        private readonly DeletesFiles $deleter,
        private readonly CopiesFiles $copier,
        private readonly RenamesFiles $renamer,
        private readonly CreatesDirectories $creator,
    ) {}

    /**
     * The files (not folders) in a folder. A folder that does not exist is empty.
     *
     * @return list<array{name: string, size: int, modified: ?string}>
     */
    public function files(Server $server, string $directory): array
    {
        $key = $server->uuid.'|'.$directory;
        if (! isset($this->listings[$key])) {
            $this->listings[$key] = $this->list($server, $directory, true);
        }

        return $this->listings[$key];
    }

    /** Whether a folder exists inside its parent. */
    public function directoryExists(Server $server, string $directory): bool
    {
        if ($directory === '/') {
            return true;
        }

        return in_array(basename($directory), $this->list($server, dirname($directory), false), true);
    }

    public function exists(Server $server, string $directory, string $name): bool
    {
        return $this->entry($server, $directory, $name) !== null;
    }

    /**
     * @return array{name: string, size: int, modified: ?string}|null
     */
    public function entry(Server $server, string $directory, string $name): ?array
    {
        foreach ($this->files($server, $directory) as $file) {
            if ($file['name'] === $name) {
                return $file;
            }
        }

        return null;
    }

    /** The text of a file, or null when it does not exist. */
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
     * @param  list<string>  $names
     */
    public function delete(Server $server, string $directory, array $names): void
    {
        if ($names !== []) {
            $this->deleter->delete($server, $directory, $names);
            $this->forget($server, $directory);
        }
    }

    /**
     * Copy a file into another folder under a new name. Wings copies next to the
     * original with " copy" added, so the copy is found by comparing the listing
     * before and after, then moved.
     */
    public function copyTo(Server $server, string $directory, string $name, string $targetDirectory, string $targetName): void
    {
        $this->forget($server, $directory);
        $before = array_column($this->files($server, $directory), 'name');

        $this->copier->copy($server, $this->path($directory, $name));

        $this->forget($server, $directory);
        $created = array_values(array_diff(array_column($this->files($server, $directory), 'name'), $before));
        if ($created === []) {
            throw new HttpException(502, 'The copy of '.$name.' could not be found. Check the file manager.');
        }
        // Wings names it "<name> copy.<ext>" (with a number if that exists).
        $stem = pathinfo($name, PATHINFO_FILENAME);
        $copy = $created[0];
        foreach ($created as $file) {
            if (str_starts_with($file, $stem.' copy')) {
                $copy = $file;

                break;
            }
        }

        try {
            $this->move($server, $directory, $copy, $targetDirectory, $targetName);
        } catch (Throwable $exception) {
            // Do not leave a stray " copy" file behind.
            try {
                $this->delete($server, $directory, [$copy]);
            } catch (Throwable) {
            }

            throw $exception;
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
     * @return list<array{name: string, size: int, modified: ?string}>|list<string>
     */
    private function list(Server $server, string $directory, bool $files): array
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

        if (! $files) {
            return array_values(array_map(
                fn (array $entry): string => (string) ($entry['name'] ?? ''),
                array_filter($entries, fn (array $entry): bool => ($entry['file'] ?? true) === false),
            ));
        }

        $result = [];
        foreach ($entries as $entry) {
            if (($entry['file'] ?? true) === false) {
                continue;
            }
            $result[] = [
                'name' => (string) ($entry['name'] ?? ''),
                'size' => (int) ($entry['size'] ?? 0),
                'modified' => isset($entry['modified']) ? (string) $entry['modified'] : null,
            ];
        }

        return $result;
    }
}
