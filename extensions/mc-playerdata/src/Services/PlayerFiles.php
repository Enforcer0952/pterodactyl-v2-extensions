<?php

namespace McPlayerData\Services;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use McPlayerData\Support\Backups;
use McPlayerData\Support\MinecraftServer;
use McPlayerData\Support\Nbt\Limits;
use McPlayerData\Support\Nbt\NbtDocument;
use McPlayerData\Support\Nbt\NbtException;
use McPlayerData\Support\Nbt\Reader;
use McPlayerData\Support\Nbt\Writer;
use McPlayerData\Support\Players;
use Carbon\CarbonImmutable;
use Pterodactyl\Contracts\Files\DeletesFiles;
use Pterodactyl\Contracts\Files\ListsDirectories;
use Pterodactyl\Contracts\Files\ReadsFileContents;
use Pterodactyl\Contracts\Files\WritesFileContents;
use Pterodactyl\Contracts\Servers\ReadsServerState;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Exceptions\Http\Server\FileSizeTooLargeException;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Models\Server;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Finds, reads and rewrites <world>/players/data/<uuid>.dat (1.21.9 and later) or
 * <world>/playerdata/<uuid>.dat.
 *
 * Wings answers a read of a missing file or folder with a 500, so every folder is
 * listed before anything in it is read. A write keeps the old file as
 * "<uuid>.dat.mcpd-bak-<time>" next to it and keeps the newest ten, and any from the last week.
 */
class PlayerFiles
{
    /** Player files are a few kilobytes; even heavily modded ones stay well below this. */
    private const int MAX_FILE_BYTES = 4 * 1024 * 1024;

    public function __construct(
        private readonly ReadsFileContents $reader,
        private readonly WritesFileContents $writer,
        private readonly ListsDirectories $directories,
        private readonly DeletesFiles $deleter,
        private readonly ReadsServerState $state,
    ) {}

    public function limits(): Limits
    {
        return new Limits;
    }

    /**
     * The world's player data folder and what the server knows about its players.
     *
     * @return array{
     *     world: string, directory: string, folder: list<array<string, mixed>>, properties: ?string,
     *     known: list<array{name: string, uuid: string, last_seen: ?string}>, online_mode: ?bool
     * }
     *
     * @throws HttpException 404 when the server has no player data yet
     */
    private function locate(Server $server): array
    {
        $root = $this->entries($server, '/');
        $rootFiles = $this->fileNames($root);

        $properties = in_array('server.properties', $rootFiles, true) ? $this->readText($server, '/server.properties', 1024 * 1024) : null;
        $known = Players::known(in_array('usercache.json', $rootFiles, true) ? $this->readText($server, '/usercache.json', 8 * 1024 * 1024) : null);

        $world = MinecraftServer::worldFolder($properties);
        if ($world === null) {
            throw new HttpException(422, 'The world folder name in server.properties is not supported here.');
        }
        if (! in_array($world, $this->dirNames($root), true)) {
            throw new HttpException(404, 'There is no "'.$world.'" folder yet. Start the server once first.');
        }

        // 1.21.9 and later keep player files in <world>/players/data, older versions in
        // <world>/playerdata. Each parent is listed before the folder inside it is opened.
        $worldEntries = $this->entries($server, '/'.$world);
        $worldDirs = $this->dirNames($worldEntries);
        $found = [];
        foreach (MinecraftServer::playerDataFolders() as $relative) {
            $parts = explode('/', $relative);
            $parent = '/'.$world;
            $dirs = $worldDirs;
            foreach ($parts as $index => $part) {
                if (! in_array($part, $dirs, true)) {
                    continue 2;
                }
                $parent .= '/'.$part;
                if ($index < count($parts) - 1) {
                    $dirs = $this->dirNames($this->entries($server, $parent));
                }
            }

            $found[$parent] = $this->entries($server, $parent);
        }

        if ($found === []) {
            throw new HttpException(404, 'The world has no player data folder yet.');
        }

        $directory = MinecraftServer::pickPlayerDataFolder(array_map(fn (array $entries): array => $this->fileNames($entries), $found));
        if ($directory === null) {
            throw new HttpException(404, 'The world has no player data folder yet.');
        }

        return [
            'world' => $world,
            'directory' => $directory,
            'folder' => $found[$directory],
            'properties' => $properties,
            'known' => $known,
            'online_mode' => $properties === null ? null : ! preg_match('/^online-mode=false\s*$/mi', $properties),
        ];
    }

    /**
     * Everyone with a saved player file, most recently saved first. Names come from
     * usercache.json; a player it does not know is listed by UUID.
     *
     * @return list<array{name: ?string, uuid: string, saved: ?string}>
     */
    public function available(Server $server): array
    {
        try {
            $place = $this->locate($server);
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() === 404) {
                return [];
            }

            throw $exception;
        }

        $names = [];
        foreach ($place['known'] as $player) {
            $names[$player['uuid']] = $player['name'];
        }

        $players = [];
        foreach ($place['folder'] as $entry) {
            if (($entry['file'] ?? true) === false || preg_match('/^([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\.dat$/D', (string) ($entry['name'] ?? ''), $match) !== 1) {
                continue;
            }

            $players[] = ['name' => $names[$match[1]] ?? null, 'uuid' => $match[1], 'saved' => isset($entry['modified']) ? (string) $entry['modified'] : null];
        }

        usort($players, fn (array $a, array $b): int => strcmp((string) $b['saved'], (string) $a['saved']));

        return $players;
    }

    /**
     * @return array{
     *     uuid: string, name: string|null, path: string, directory: string, world: string,
     *     bytes: string, hash: string, document: NbtDocument, raw: string,
     *     modified: ?string, size: int, online_mode: ?bool, backups: list<string>
     * }
     *
     * @throws HttpException with a message meant for the user
     */
    public function load(Server $server, string $input): array
    {
        $ref = Players::parse($input);
        if ($ref === null) {
            throw new HttpException(422, 'That is not a valid player name or UUID.');
        }

        $place = $this->locate($server);
        $found = Players::find($place['known'], $ref);
        $uuid = $ref['uuid'] ?? $found['uuid'] ?? null;
        if ($uuid === null && $place['online_mode'] === false) {
            $uuid = Players::offlineUuid($ref['name']);
        }
        if ($uuid === null) {
            throw new HttpException(404, $ref['name'].' has not joined this server, so there is no saved data to show.');
        }

        $directory = $place['directory'];
        $file = $this->entry($place['folder'], $uuid.'.dat');
        if ($file === null) {
            throw new HttpException(404, 'There is no saved data for this player yet. It is created when they leave the server.');
        }

        $bytes = $this->readBytes($server, $directory.'/'.$uuid.'.dat');
        try {
            $document = Reader::readFile($bytes, $this->limits());
            $raw = Reader::uncompressed($bytes, $this->limits());
        } catch (NbtException $exception) {
            throw new HttpException(422, 'This player file could not be read: '.$exception->getMessage());
        }

        return [
            'uuid' => $uuid,
            'name' => Players::find($place['known'], ['uuid' => $uuid])['name'] ?? ($ref['name'] ?? null),
            'path' => $directory.'/'.$uuid.'.dat',
            'directory' => $directory,
            'world' => $place['world'],
            'bytes' => $bytes,
            'hash' => sha1($bytes),
            'document' => $document,
            'raw' => $raw,
            'modified' => isset($file['modified']) ? (string) $file['modified'] : null,
            'size' => (int) ($file['size'] ?? strlen($bytes)),
            'online_mode' => $place['online_mode'],
            'backups' => $this->backupNames($this->fileNames($place['folder']), $uuid),
        ];
    }

    /**
     * The world's spawn point from level.dat, or null when it cannot be read.
     *
     * @return array{x: float, y: float, z: float, dimension: string}|null
     */
    public function worldSpawn(Server $server, string $world): ?array
    {
        try {
            if ($this->entry($this->entries($server, '/'.$world), 'level.dat') === null) {
                return null;
            }

            $document = Reader::readFile($this->readBytes($server, '/'.$world.'/level.dat', 2 * 1024 * 1024), $this->limits());

            return \McPlayerData\Support\PlayerEdits::worldSpawn($document->root);
        } catch (NbtException|HttpException) {
            return null;
        }
    }

    /**
     * Rewrite a player's file. $change gets the freshly read file and edits its
     * document; nothing is written when it changes nothing.
     *
     * The server must be stopped: a running server keeps online players in memory and
     * writes them back when they leave, which would undo the edit. The file must also be
     * the one the person was looking at.
     *
     * @param  callable(array<string, mixed>): void  $change
     * @return array{changed: bool, backup: ?string}
     *
     * @throws HttpException with a message meant for the user
     */
    public function edit(Server $server, string $player, string $hash, callable $change, string $action): array
    {
        try {
            return Cache::lock('ext:mc-playerdata:files:'.$server->uuid, 30)->block(10, function () use ($server, $player, $hash, $change, $action): array {
                // The server must still be off when the file is written.
                $state = $this->serverState($server);
                if ($state !== 'offline') {
                    throw new HttpException(409, 'Stop the server to edit player data. It is '.$state.' now.');
                }

                $loaded = $this->load($server, $player);
                if (! hash_equals($loaded['hash'], $hash)) {
                    throw new HttpException(409, 'This player file changed since you opened it. Reload the page and try again.');
                }

                // Only edit files this reader writes back exactly as it found them.
                $document = $loaded['document'];
                if (Writer::writeDocument($document) !== $loaded['raw']) {
                    throw new HttpException(422, 'This file has an unusual structure, so it is not edited here. Nothing was changed.');
                }

                $change($loaded);

                $after = Writer::writeDocument($document);
                if ($after === $loaded['raw']) {
                    return ['changed' => false, 'backup' => null];
                }

                $bytes = Writer::writeFile($document);
                try {
                    // The new file must read back as exactly what is about to be written.
                    if (Writer::writeDocument(Reader::readFile($bytes, $this->limits())) !== $after) {
                        throw new NbtException('The edited data does not read back the same.');
                    }
                } catch (NbtException $exception) {
                    throw new HttpException(500, 'The edit was not saved because the result was not valid: '.$exception->getMessage());
                }

                $backup = $this->backup($server, $loaded);
                $this->writer->write($server, $loaded['path'], $bytes);
                $this->prune($server, $loaded['directory'], $loaded['uuid']);

                Activity::event('server:player-data.edit')->subject($server)->property(array_filter([
                    'player' => $loaded['uuid'],
                    'name' => $loaded['name'],
                    'action' => $action,
                    'backup' => $backup,
                ]))->log();

                return ['changed' => true, 'backup' => $backup];
            });
        } catch (LockTimeoutException) {
            throw new HttpException(409, 'Someone else is editing player data on this server. Try again in a moment.');
        } catch (DaemonConnectionException) {
            throw new HttpException(502, 'Could not reach this server\'s node.');
        }
    }

    public function serverState(Server $server): string
    {
        try {
            \Illuminate\Support\Facades\Cache::forget('resources:'.$server->uuid); // the panel caches power state for 20 s
            return $this->state->read($server)->state;
        } catch (DaemonConnectionException) {
            throw new HttpException(502, 'Could not reach this server\'s node.');
        }
    }

    /**
     * Copy the current file aside. The name sorts by time, so the newest are easy to find.
     *
     * @param  array<string, mixed>  $loaded
     */
    private function backup(Server $server, array $loaded): string
    {
        $stamp = CarbonImmutable::now('UTC')->format('Ymd-His');
        $existing = $this->fileNames($this->entries($server, $loaded['directory']));
        $name = $loaded['uuid'].'.dat.mcpd-bak-'.$stamp;
        for ($n = 2; in_array($name, $existing, true); $n++) {
            $name = $loaded['uuid'].'.dat.mcpd-bak-'.$stamp.'-'.$n;
        }

        $this->writer->write($server, $loaded['directory'].'/'.$name, $loaded['bytes']);

        return $name;
    }

    /** Old backups are removed best effort: an extra one is harmless. */
    private function prune(Server $server, string $directory, string $uuid): void
    {
        try {
            $backups = $this->backupNames($this->fileNames($this->entries($server, $directory)), $uuid);
            $old = Backups::expired($backups, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
            if ($old !== []) {
                $this->deleter->delete($server, $directory, $old);
            }
        } catch (Throwable) {
            // Keeping an extra backup is harmless.
        }
    }

    /**
     * @param  list<string>  $names  files in the playerdata folder
     * @return list<string> this player's backups, newest first
     */
    private function backupNames(array $names, string $uuid): array
    {
        $backups = array_values(array_filter(
            $names,
            fn (string $name): bool => str_starts_with($name, $uuid.'.dat.mcpd-bak-') && preg_match('/\.dat\.mcpd-bak-\d{8}-\d{6}(-\d+)?$/D', $name) === 1,
        ));
        rsort($backups);

        return $backups;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function entries(Server $server, string $directory): array
    {
        return $this->directories->list($server, $directory);
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return list<string>
     */
    private function fileNames(array $entries): array
    {
        return array_values(array_map(fn (array $e): string => (string) $e['name'], array_filter($entries, fn (array $e): bool => ($e['file'] ?? true) !== false && isset($e['name']))));
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return list<string>
     */
    private function dirNames(array $entries): array
    {
        return array_values(array_map(fn (array $e): string => (string) $e['name'], array_filter($entries, fn (array $e): bool => ($e['file'] ?? true) === false && isset($e['name']))));
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return array<string, mixed>|null
     */
    private function entry(array $entries, string $name): ?array
    {
        foreach ($entries as $entry) {
            if (($entry['name'] ?? null) === $name && ($entry['file'] ?? true) !== false) {
                return $entry;
            }
        }

        return null;
    }

    private function readText(Server $server, string $path, int $max): ?string
    {
        try {
            return $this->reader->read($server, $path, $max);
        } catch (FileSizeTooLargeException) {
            return null;
        }
    }

    private function readBytes(Server $server, string $path, int $max = self::MAX_FILE_BYTES): string
    {
        try {
            return $this->reader->read($server, $path, $max);
        } catch (FileSizeTooLargeException) {
            throw new HttpException(422, basename($path).' is larger than '.intdiv($max, 1024 * 1024).' MB, which is too large to show here.');
        }
    }
}
