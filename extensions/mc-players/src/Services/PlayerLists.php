<?php

namespace McPlayers\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use McPlayers\Support\BrokenListFile;
use McPlayers\Support\ListFile;
use McPlayers\Support\Validates;
use Pterodactyl\Contracts\Files\ListsDirectories;
use Pterodactyl\Contracts\Files\ReadsFileContents;
use Pterodactyl\Contracts\Files\WritesFileContents;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Exceptions\Http\Server\FileSizeTooLargeException;
use Pterodactyl\Models\Server;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Reads and, while the server is stopped, edits the server's whitelist, ops, ban
 * lists and server.properties directly. While it runs, changes go through console
 * commands instead, because the server rewrites these files from memory.
 */
class PlayerLists
{
    public const string WHITELIST = '/whitelist.json';

    public const string OPS = '/ops.json';

    public const string BANNED_PLAYERS = '/banned-players.json';

    public const string BANNED_IPS = '/banned-ips.json';

    public const string PROPERTIES = '/server.properties';

    /** Every player who has joined, with an expiry one month after they were last seen. */
    public const string USERCACHE = '/usercache.json';

    /** usercache.json and ban lists of big servers grow past 1 MB. */
    private const int MAX_BYTES = 8 * 1024 * 1024;

    /** @var array<string, list<string>> server uuid => file names in the server root, per request */
    private array $rootFiles = [];

    /** @var array<string, ?string> server uuid + file => contents, per request */
    private array $contents = [];

    /** @var array<string, string> file => why it could not be read, for the last all() */
    private array $problems = [];

    public function __construct(
        private readonly ReadsFileContents $reader,
        private readonly WritesFileContents $writer,
        private readonly ListsDirectories $directories,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function all(Server $server): array
    {
        $this->problems = [];
        $properties = $this->properties($server);

        $lists = [
            'whitelist_enabled' => isset($properties['white-list']) ? $properties['white-list'] === 'true' : null,
            'online_mode' => isset($properties['online-mode']) ? $properties['online-mode'] !== 'false' : null,
            'whitelist' => array_map(fn (array $row): array => [
                'name' => (string) ($row['name'] ?? ''),
                'uuid' => (string) ($row['uuid'] ?? ''),
            ], $this->safeJson($server, self::WHITELIST)),
            'ops' => array_map(fn (array $row): array => [
                'name' => (string) ($row['name'] ?? ''),
                'uuid' => (string) ($row['uuid'] ?? ''),
                'level' => (int) ($row['level'] ?? 4),
            ], $this->safeJson($server, self::OPS)),
            'bans' => array_map(fn (array $row): array => [
                'name' => (string) ($row['name'] ?? ''),
                'uuid' => (string) ($row['uuid'] ?? ''),
                'reason' => $row['reason'] ?? null,
                'source' => $row['source'] ?? null,
                'created' => $row['created'] ?? null,
                'expires' => $row['expires'] ?? null,
            ], $this->safeJson($server, self::BANNED_PLAYERS)),
            'ip_bans' => array_map(fn (array $row): array => [
                'ip' => (string) ($row['ip'] ?? ''),
                'reason' => $row['reason'] ?? null,
                'source' => $row['source'] ?? null,
                'created' => $row['created'] ?? null,
                'expires' => $row['expires'] ?? null,
            ], $this->safeJson($server, self::BANNED_IPS)),
            'known' => $this->known($server),
        ];

        // Files that exist but could not be read: shown instead of an empty list.
        $lists['problems'] = $this->problems;

        return $lists;
    }

    /**
     * Players who have joined the server before, most recently seen first.
     *
     * @return list<array{name: string, uuid: string, last_seen: ?string}>
     */
    private function known(Server $server): array
    {
        $players = [];
        foreach ($this->safeJson($server, self::USERCACHE) as $row) {
            $name = (string) ($row['name'] ?? '');
            if (preg_match(Validates::NAME, $name) !== 1) {
                continue;
            }

            // The server writes expiresOn as last seen + one month.
            $lastSeen = null;
            if (is_string($row['expiresOn'] ?? null)) {
                try {
                    $lastSeen = CarbonImmutable::parse($row['expiresOn'])->subMonth()->toAtomString();
                } catch (\Throwable) {
                    $lastSeen = null;
                }
            }

            $players[strtolower($name)] = ['name' => $name, 'uuid' => (string) ($row['uuid'] ?? ''), 'last_seen' => $lastSeen];
        }

        $players = array_values($players);
        usort($players, fn (array $a, array $b): int => strcmp((string) $b['last_seen'], (string) $a['last_seen']));

        return $players;
    }

    /**
     * Apply an action straight to the files of a stopped server.
     */
    public function applyOffline(Server $server, string $action, string $target, ?string $reason, string $actor): void
    {
        match ($action) {
            'whitelist-add' => $this->upsert($server, self::WHITELIST, $this->profile($server, $target)),
            'whitelist-remove' => $this->remove($server, self::WHITELIST, 'name', $target),
            // Re-opping someone keeps the level and limit bypass they already had.
            'op' => $this->upsert($server, self::OPS, [...$this->profile($server, $target), 'level' => 4, 'bypassesPlayerLimit' => false], 'uuid', ['level', 'bypassesPlayerLimit']),
            'deop' => $this->remove($server, self::OPS, 'name', $target),
            'ban' => $this->upsert($server, self::BANNED_PLAYERS, [
                ...$this->profile($server, $target),
                ...$this->banFields($reason, $actor),
            ]),
            'pardon' => $this->remove($server, self::BANNED_PLAYERS, 'name', $target),
            'ban-ip' => filter_var($target, FILTER_VALIDATE_IP) === false
                ? throw new HttpException(422, 'The server is stopped, so a player name cannot be turned into their IP address. Enter the IP address, or start the server.')
                : $this->upsert($server, self::BANNED_IPS, ['ip' => $target, ...$this->banFields($reason, $actor)], 'ip'),
            'pardon-ip' => $this->remove($server, self::BANNED_IPS, 'ip', $target),
            'whitelist-on' => $this->setProperty($server, 'white-list', 'true'),
            'whitelist-off' => $this->setProperty($server, 'white-list', 'false'),
            default => throw new HttpException(409, 'Start the server to do this.'),
        };
    }

    /**
     * The UUID and correctly-cased name for a player. A player who has joined before
     * gets the UUID the server itself recorded, which is right for offline mode,
     * proxies and Floodgate alike. Otherwise: Mojang's for online-mode servers, the
     * derived offline UUID for offline-mode ones.
     *
     * @return array{uuid: string, name: string}
     */
    private function profile(Server $server, string $name): array
    {
        $cached = ListFile::cachedProfile($this->safeJson($server, self::USERCACHE), $name);
        if ($cached !== null) {
            return $cached;
        }

        if (str_starts_with($name, '.')) {
            throw new HttpException(422, 'Bedrock players (names starting with a dot) need to join once first, or be added with the server running.');
        }

        if (($this->properties($server)['online-mode'] ?? 'true') === 'false') {
            return ['uuid' => Validates::offlineUuid($name), 'name' => $name];
        }

        $known = Cache::get('ext:mc-players:mojang:'.strtolower($name));
        if (is_array($known)) {
            return $known;
        }

        try {
            $response = Http::withUserAgent('pterodactyl-mc-players/1.2')
                ->connectTimeout(5)
                ->timeout(10)
                ->get('https://api.mojang.com/users/profiles/minecraft/'.rawurlencode($name));
        } catch (ConnectionException) {
            throw new HttpException(502, 'Could not reach Mojang to look up '.$name.'. Try again, or start the server and use the console.');
        }

        if ($response->status() === 404 || $response->status() === 204) {
            throw new HttpException(422, 'There is no Minecraft account called '.$name.'.');
        }

        if (! $response->successful() || ! is_string($response->json('id'))) {
            throw new HttpException(502, 'Could not look up '.$name.' with Mojang. Try again, or start the server and use the console.');
        }

        $profile = ['uuid' => Validates::dashed($response->json('id')), 'name' => (string) $response->json('name', $name)];
        Cache::put('ext:mc-players:mojang:'.strtolower($name), $profile, now()->addDay());

        return $profile;
    }

    /**
     * @return array{created: string, source: string, expires: string, reason: string}
     */
    private function banFields(?string $reason, string $actor): array
    {
        return [
            'created' => CarbonImmutable::now()->format('Y-m-d H:i:s O'),
            'source' => $actor,
            'expires' => 'forever',
            'reason' => $reason ?? 'Banned by an operator.',
        ];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  list<string>  $keep
     */
    private function upsert(Server $server, string $file, array $entry, string $key = 'uuid', array $keep = []): void
    {
        $this->write($server, $file, ListFile::upsert($this->json($server, $file), $entry, $key, $keep));
    }

    private function remove(Server $server, string $file, string $key, string $value): void
    {
        $kept = ListFile::remove($this->json($server, $file), $key, $value);
        if ($kept === null) {
            throw new HttpException(404, $value.' is not on that list.');
        }

        $this->write($server, $file, $kept);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function write(Server $server, string $file, array $rows): void
    {
        $this->writer->write($server, $file, ListFile::encode($rows));
        unset($this->contents[$server->uuid.$file], $this->rootFiles[$server->uuid]);
    }

    private function setProperty(Server $server, string $key, string $value): void
    {
        $contents = $this->read($server, self::PROPERTIES);
        if ($contents === null) {
            throw new HttpException(404, 'server.properties does not exist yet. Start the server once first.');
        }

        $pattern = '/^'.preg_quote($key, '/').'=.*$/m';
        $contents = preg_match($pattern, $contents) === 1
            ? preg_replace($pattern, $key.'='.$value, $contents)
            : rtrim($contents)."\n".$key.'='.$value."\n";

        $this->writer->write($server, self::PROPERTIES, (string) $contents);
        unset($this->contents[$server->uuid.self::PROPERTIES]);
    }

    /**
     * The server's usercache.json rows (everyone who has joined), for one player's details.
     *
     * @return list<array<string, mixed>>
     */
    public function usercache(Server $server): array
    {
        return $this->safeJson($server, self::USERCACHE);
    }

    /**
     * Contents of a file in the server, or null when it does not exist. For files
     * outside the server root (a world's stats), the folder is listed first.
     */
    public function readFile(Server $server, string $directory, string $name, int $maxBytes = 1024 * 1024): ?string
    {
        try {
            $entries = $this->directories->list($server, $directory);
        } catch (DaemonConnectionException) {
            return null;
        }
        if (! in_array($name, array_map(fn (array $entry): string => (string) ($entry['name'] ?? ''), $entries), true)) {
            return null;
        }

        try {
            return $this->reader->read($server, rtrim($directory, '/').'/'.$name, $maxBytes);
        } catch (DaemonConnectionException|FileSizeTooLargeException) {
            return null;
        }
    }

    /**
     * Whether a folder exists. Each parent is listed first, because Wings answers a
     * listing of a missing folder with an error instead of an empty result.
     */
    public function folderExists(Server $server, string $path): bool
    {
        $path = '/'.trim($path, '/');
        if ($path === '/') {
            return true;
        }

        $parent = dirname($path);
        if (! $this->folderExists($server, $parent)) {
            return false;
        }

        try {
            $entries = $this->directories->list($server, $parent);
        } catch (DaemonConnectionException) {
            return false;
        }

        foreach ($entries as $entry) {
            if (($entry['name'] ?? null) === basename($path) && ($entry['file'] ?? true) === false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string>
     *
     * @throws HttpException when server.properties exists but cannot be read
     */
    public function properties(Server $server): array
    {
        try {
            $contents = $this->read($server, self::PROPERTIES);
        } catch (DaemonConnectionException) {
            throw new HttpException(502, 'Could not read server.properties from this server\'s node.');
        } catch (FileSizeTooLargeException) {
            throw new HttpException(422, 'server.properties is too large to read.');
        }

        $properties = [];
        foreach (preg_split('/\R/', $contents ?? '') ?: [] as $line) {
            if ($line === '' || $line[0] === '#' || ! str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $properties[trim($key)] = trim($value);
        }

        return $properties;
    }

    /**
     * Rows of a list file that is about to be edited. A broken file stops the edit.
     *
     * @return list<array<string, mixed>>
     */
    private function json(Server $server, string $file): array
    {
        try {
            return ListFile::decode($this->read($server, $file), $file);
        } catch (BrokenListFile $exception) {
            throw new HttpException(409, $exception->getMessage());
        }
    }

    /**
     * Rows of a list file for display. A file that cannot be read shows as empty,
     * with the reason in "problems", so one bad file does not break every tab.
     *
     * @return list<array<string, mixed>>
     */
    private function safeJson(Server $server, string $file): array
    {
        try {
            return ListFile::decode($this->read($server, $file), $file);
        } catch (BrokenListFile $exception) {
            $this->problems[ltrim($file, '/')] = $exception->getMessage();
        } catch (FileSizeTooLargeException) {
            $this->problems[ltrim($file, '/')] = ltrim($file, '/').' is larger than 8 MB, so it is not shown.';
        } catch (DaemonConnectionException) {
            $this->problems[ltrim($file, '/')] = 'Could not read '.ltrim($file, '/').' from the node.';
        }

        return [];
    }

    /**
     * Contents of a file in the server root, or null when it does not exist. Some
     * Wings versions answer a read of a missing file with a 500, so the root is
     * listed first and only files that exist are read.
     */
    private function read(Server $server, string $file): ?string
    {
        $key = $server->uuid.$file;
        if (array_key_exists($key, $this->contents)) {
            return $this->contents[$key];
        }

        if (! in_array(ltrim($file, '/'), $this->rootFiles($server), true)) {
            return $this->contents[$key] = null;
        }

        try {
            return $this->contents[$key] = $this->reader->read($server, $file, self::MAX_BYTES);
        } catch (DaemonConnectionException $exception) {
            if ($exception->getStatusCode() === 404) {
                return $this->contents[$key] = null;
            }

            throw $exception;
        }
    }

    /**
     * @return list<string>
     */
    private function rootFiles(Server $server): array
    {
        return $this->rootFiles[$server->uuid] ??= array_values(array_map(
            fn (array $entry): string => (string) ($entry['name'] ?? ''),
            array_filter($this->directories->list($server, '/'), fn (array $entry): bool => ($entry['file'] ?? true) !== false),
        ));
    }

    /** Whether a name or IP is already on a list, for removals of odd legacy names. */
    public function listed(Server $server, string $action, string $target): bool
    {
        [$file, $key] = match ($action) {
            'whitelist-remove' => [self::WHITELIST, 'name'],
            'deop' => [self::OPS, 'name'],
            'pardon' => [self::BANNED_PLAYERS, 'name'],
            'pardon-ip' => [self::BANNED_IPS, 'ip'],
            default => [null, null],
        };

        return $file !== null && ListFile::has($this->safeJson($server, $file), $key, $target);
    }
}
