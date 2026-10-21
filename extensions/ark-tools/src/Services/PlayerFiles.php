<?php

namespace ArkTools\Services;

use ArkTools\Support\Ark;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Pterodactyl\Contracts\Files\ListsDirectories;
use Pterodactyl\Contracts\Files\ReadsFileContents;
use Pterodactyl\Contracts\Files\WritesFileContents;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Models\Server;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The plain-text Steam id lists ARK keeps: the whitelist ("join without check"),
 * the ban list and the admin list. Each name maps to where ARK looks for it first,
 * then any other place it has been seen on Linux servers.
 */
class PlayerFiles
{
    public const array FILES = [
        'whitelist' => [
            '/ShooterGame/Binaries/Linux/PlayersJoinNoCheckList.txt',
            '/ShooterGame/Saved/PlayersJoinNoCheckList.txt',
        ],
        'bans' => [
            '/ShooterGame/Binaries/Linux/BanList.txt',
            '/ShooterGame/Saved/BanList.txt',
        ],
        'admins' => [
            '/ShooterGame/Saved/AllowedCheaterSteamIDs.txt',
        ],
    ];

    private const int MAX_BYTES = 512 * 1024;

    /** @var array<string, list<string>> directory => file names, per request */
    private array $listings = [];

    public function __construct(
        private readonly ReadsFileContents $reader,
        private readonly WritesFileContents $writer,
        private readonly ListsDirectories $directories,
    ) {}

    /**
     * @return list<string>
     */
    public function ids(Server $server, string $list): array
    {
        $ids = [];
        foreach (self::FILES[$list] as $path) {
            $contents = $this->read($server, $path);
            if ($contents !== null) {
                $ids = [...$ids, ...Ark::steamIds($contents)];
            }
        }

        return array_values(array_unique($ids));
    }

    public function add(Server $server, string $list, string $steamId): void
    {
        $this->locked($server, function () use ($server, $list, $steamId): void {
            [$path, $ids] = $this->primary($server, $list);
            if (! in_array($steamId, $ids, true)) {
                $ids[] = $steamId;
            }
            $this->write($server, $path, $ids);
        });
    }

    /** Remove from every copy of the list, so a stale second file cannot keep the id in. */
    public function remove(Server $server, string $list, string $steamId): bool
    {
        return $this->locked($server, function () use ($server, $list, $steamId): bool {
            $removed = false;
            foreach (self::FILES[$list] as $path) {
                $contents = $this->read($server, $path);
                if ($contents === null) {
                    continue;
                }
                $ids = Ark::steamIds($contents);
                if (in_array($steamId, $ids, true)) {
                    $this->write($server, $path, array_values(array_diff($ids, [$steamId])));
                    $removed = true;
                }
            }

            return $removed;
        });
    }

    /**
     * Read-modify-write of the list files, one editor at a time per server.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function locked(Server $server, callable $callback): mixed
    {
        try {
            return Cache::lock('ext:ark-tools:player-files:'.$server->uuid, 30)->block(10, $callback);
        } catch (LockTimeoutException) {
            throw new HttpException(409, 'The lists are being changed right now. Try again in a moment.');
        }
    }

    /**
     * The file that is edited: the first one that exists, else the preferred location.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function primary(Server $server, string $list): array
    {
        foreach (self::FILES[$list] as $path) {
            $contents = $this->read($server, $path);
            if ($contents !== null) {
                return [$path, Ark::steamIds($contents)];
            }
        }

        return [self::FILES[$list][0], []];
    }

    /**
     * @param  list<string>  $ids
     */
    private function write(Server $server, string $path, array $ids): void
    {
        $this->writer->write($server, $path, $ids === [] ? '' : implode("\n", $ids)."\n");
        unset($this->listings[$server->uuid.dirname($path)]);
    }

    /**
     * Some Wings versions answer a read of a missing file with a 500 instead of a
     * 404, so files are only read once the directory listing shows they exist.
     */
    private function read(Server $server, string $path): ?string
    {
        if (! in_array(basename($path), $this->files($server, dirname($path)), true)) {
            return null;
        }

        try {
            return $this->reader->read($server, $path, self::MAX_BYTES);
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
    private function files(Server $server, string $directory): array
    {
        $key = $server->uuid.$directory;
        if (! isset($this->listings[$key])) {
            try {
                $entries = $this->directories->list($server, $directory);
            } catch (DaemonConnectionException) {
                $entries = [];
            }
            $this->listings[$key] = array_values(array_map(
                fn (array $entry): string => (string) ($entry['name'] ?? ''),
                array_filter($entries, fn (array $entry): bool => ($entry['file'] ?? true) !== false),
            ));
        }

        return $this->listings[$key];
    }
}
