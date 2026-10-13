<?php

namespace McVersion\Services;

use McVersion\Support\Backups;
use Pterodactyl\Contracts\Files\ReadsFileContents;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Exceptions\Http\Server\FileSizeTooLargeException;
use Pterodactyl\Models\Server;

/**
 * The copies of the server jar kept next to it, with the notes saved beside each.
 */
class Copies
{
    public function __construct(private readonly ReadsFileContents $reader) {}

    /**
     * Copies of the jar in a root listing, newest first, with their notes.
     *
     * @param  list<array<string, mixed>>  $root
     * @return list<array{name: string, stamp: string, size: int, meta: array{software: ?string, version: ?string, variables: array<string, string>, image: ?string, to: array{software: ?string, version: ?string, build: ?string}, at: ?int}|null}>
     */
    public function list(Server $server, string $jar, array $root): array
    {
        $names = ServerFiles::names($root);
        $copies = [];

        foreach (Backups::find($jar, $names) as $copy) {
            $entry = ServerFiles::file($root, $copy['name']);
            if ($entry === null) {
                continue;
            }

            $meta = null;
            if (ServerFiles::file($root, Backups::metaName($copy['name'])) !== null) {
                $meta = Backups::readMeta($this->read($server, '/'.Backups::metaName($copy['name'])));
            }

            $copies[] = $copy + ['size' => (int) ($entry['size'] ?? 0), 'meta' => $meta];
        }

        return $copies;
    }

    /**
     * What the newest copy's note says was put in place, when that has not run yet:
     * the server's log is older than the switch.
     *
     * @param  list<array{name: string, stamp: string, size: int, meta: array{to: array{software: ?string, version: ?string, build: ?string}, at: ?int}|null}>  $copies
     * @return array{software: ?string, version: ?string, build: ?string, at: int}|null
     */
    public static function pending(array $copies, ?int $logModified): ?array
    {
        $meta = $copies[0]['meta'] ?? null;
        if ($meta === null || $meta['at'] === null || ($meta['to']['version'] === null && $meta['to']['software'] === null)) {
            return null;
        }

        return $logModified === null || $meta['at'] > $logModified ? $meta['to'] + ['at' => $meta['at']] : null;
    }

    private function read(Server $server, string $path): ?string
    {
        try {
            return $this->reader->read($server, $path, 64 * 1024);
        } catch (FileSizeTooLargeException) {
            return null;
        } catch (DaemonConnectionException $exception) {
            if (in_array($exception->getStatusCode(), [404, 500], true)) {
                return null;
            }

            throw $exception;
        }
    }
}
