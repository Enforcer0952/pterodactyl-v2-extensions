<?php

namespace ArkTools\Services;

use ArkTools\Support\ConfigModel;
use Carbon\CarbonImmutable;
use Pterodactyl\Contracts\Files\DeletesFiles;
use Pterodactyl\Contracts\Files\ListsDirectories;
use Pterodactyl\Contracts\Files\ReadsFileContents;
use Pterodactyl\Contracts\Files\WritesFileContents;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Exceptions\Http\Server\FileSizeTooLargeException;
use Pterodactyl\Models\Server;
use Throwable;

/**
 * Reads and writes GameUserSettings.ini and Game.ini. Every write first saves the
 * old file as "<name>.<date>.bak" next to it and keeps the newest five.
 */
class ConfigFiles
{
    private const int MAX_BYTES = 4 * 1024 * 1024;

    private const int KEEP_BACKUPS = 5;

    public function __construct(
        private readonly ReadsFileContents $reader,
        private readonly WritesFileContents $writer,
        private readonly ListsDirectories $directories,
        private readonly DeletesFiles $deleter,
        private readonly ConfigModel $model,
    ) {}

    /**
     * @return array<string, ?string> file name => contents, null when the file does not exist
     */
    public function read(Server $server): array
    {
        $contents = [];
        $listings = [];
        foreach ($this->model->files() as $name => $path) {
            $directory = dirname($path);
            $listings[$directory] ??= $this->names($server, $directory);
            $contents[$name] = in_array($name, $listings[$directory], true) ? $this->readFile($server, $path) : null;
        }

        return $contents;
    }

    /**
     * @param  array<string, string>  $changed  file name => new contents
     * @param  array<string, ?string>  $current  file name => contents before the change
     */
    public function write(Server $server, array $changed, array $current): void
    {
        $stamp = CarbonImmutable::now()->format('Ymd-His');
        foreach ($changed as $name => $contents) {
            $path = $this->model->files()[$name];
            if (($current[$name] ?? null) !== null) {
                $this->writer->write($server, $path.'.'.$stamp.'.bak', (string) $current[$name]);
            }
            $this->writer->write($server, $path, $contents);
            $this->prune($server, $path);
        }
    }

    public static function hash(?string $contents): string
    {
        return sha1($contents ?? '');
    }

    /** Old backups are removed best effort: a failure here never fails the save. */
    private function prune(Server $server, string $path): void
    {
        try {
            $prefix = basename($path).'.';
            $backups = array_values(array_filter(
                $this->names($server, dirname($path)),
                fn (string $name): bool => str_starts_with($name, $prefix) && preg_match('/\.\d{8}-\d{6}\.bak$/', $name) === 1,
            ));
            rsort($backups);
            $old = array_slice($backups, self::KEEP_BACKUPS);
            if ($old !== []) {
                $this->deleter->delete($server, dirname($path), $old);
            }
        } catch (Throwable) {
            // Keeping an extra backup is harmless.
        }
    }

    private function readFile(Server $server, string $path): ?string
    {
        try {
            return $this->reader->read($server, $path, self::MAX_BYTES);
        } catch (FileSizeTooLargeException) {
            throw new \Symfony\Component\HttpKernel\Exception\HttpException(422, basename($path).' is larger than 4 MB, too large for the editor. Edit it in the file manager.');
        } catch (DaemonConnectionException $exception) {
            if ($exception->getStatusCode() === 404) {
                return null;
            }

            throw $exception;
        }
    }

    /**
     * @return list<string>
     */
    private function names(Server $server, string $directory): array
    {
        try {
            $entries = $this->directories->list($server, $directory);
        } catch (DaemonConnectionException $exception) {
            // A server that never started has no config folder, and some Wings
            // versions answer that with a 500 instead of a 404. A node that is down
            // answers 502/504 and is reported as such.
            if (in_array($exception->getStatusCode(), [404, 500], true)) {
                return [];
            }

            throw $exception;
        }

        return array_values(array_map(
            fn (array $entry): string => (string) ($entry['name'] ?? ''),
            array_filter($entries, fn (array $entry): bool => ($entry['file'] ?? true) !== false),
        ));
    }
}
