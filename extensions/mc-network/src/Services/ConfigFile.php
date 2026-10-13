<?php

namespace McNetwork\Services;

use Carbon\CarbonImmutable;
use Pterodactyl\Contracts\Files\DeletesFiles;
use Pterodactyl\Contracts\Files\ListsDirectories;
use Pterodactyl\Contracts\Files\ReadsFileContents;
use Pterodactyl\Contracts\Files\WritesFileContents;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Exceptions\Http\Server\FileSizeTooLargeException;
use Pterodactyl\Models\Server;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * The proxy's config.yml. Every write first saves the old file as
 * "config.yml.bak-<date>" next to it and keeps the newest five.
 */
class ConfigFile
{
    public const string NAME = 'config.yml';

    public const string VELOCITY = 'velocity.toml';

    private const int MAX_BYTES = 2 * 1024 * 1024;

    private const int KEEP_BACKUPS = 5;

    public function __construct(
        private readonly ReadsFileContents $reader,
        private readonly WritesFileContents $writer,
        private readonly ListsDirectories $directories,
        private readonly DeletesFiles $deleter,
    ) {}

    /**
     * The file's text, null when there is no config.yml, and whether a velocity.toml is
     * there. The folder is listed first because Wings answers a read of a missing file
     * with an error.
     *
     * @return array{contents: ?string, velocity: bool}
     */
    public function read(Server $server): array
    {
        $names = $this->names($server);
        $velocity = in_array(self::VELOCITY, $names, true);
        if (! in_array(self::NAME, $names, true)) {
            return ['contents' => null, 'velocity' => $velocity];
        }

        try {
            $contents = $this->reader->read($server, '/'.self::NAME, self::MAX_BYTES);
        } catch (FileSizeTooLargeException) {
            throw new HttpException(422, 'config.yml is larger than 2 MB, too large for this page. Edit it in the file manager.');
        }

        return ['contents' => $contents, 'velocity' => $velocity];
    }

    /** Saves the old contents as a backup, writes the new ones and removes old backups. */
    public function write(Server $server, string $old, string $new): void
    {
        $stamp = CarbonImmutable::now()->format('Ymd-His');
        $this->writer->write($server, '/'.self::NAME.'.bak-'.$stamp, $old);
        $this->writer->write($server, '/'.self::NAME, $new);
        $this->prune($server);
    }

    public static function hash(string $contents): string
    {
        return sha1($contents);
    }

    /** Old backups are removed best effort: a failure here never fails the save. */
    private function prune(Server $server): void
    {
        try {
            $backups = array_values(array_filter(
                $this->names($server),
                fn (string $name): bool => preg_match('/^'.preg_quote(self::NAME, '/').'\.bak-\d{8}-\d{6}$/D', $name) === 1,
            ));
            rsort($backups);
            $old = array_slice($backups, self::KEEP_BACKUPS);
            if ($old !== []) {
                $this->deleter->delete($server, '/', $old);
            }
        } catch (Throwable) {
            // Keeping an extra backup is harmless.
        }
    }

    /**
     * @return list<string> the files in the server's root folder
     */
    private function names(Server $server): array
    {
        try {
            $entries = $this->directories->list($server, '/');
        } catch (DaemonConnectionException $exception) {
            // A server that never started has an empty or missing root folder, and some
            // Wings versions answer that with a 500. A node that is down answers 502/504.
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
