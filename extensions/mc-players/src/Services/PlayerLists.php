<?php

namespace McPlayers\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use McPlayers\Support\Validates;
use Pterodactyl\Contracts\Files\ReadsFileContents;
use Pterodactyl\Contracts\Files\WritesFileContents;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
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

    private const int MAX_BYTES = 1024 * 1024;

    public function __construct(
        private readonly ReadsFileContents $reader,
        private readonly WritesFileContents $writer,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function all(Server $server): array
    {
        $properties = $this->properties($server);

        return [
            'whitelist_enabled' => isset($properties['white-list']) ? $properties['white-list'] === 'true' : null,
            'online_mode' => isset($properties['online-mode']) ? $properties['online-mode'] !== 'false' : null,
            'whitelist' => array_map(fn (array $row): array => [
                'name' => (string) ($row['name'] ?? ''),
                'uuid' => (string) ($row['uuid'] ?? ''),
            ], $this->json($server, self::WHITELIST)),
            'ops' => array_map(fn (array $row): array => [
                'name' => (string) ($row['name'] ?? ''),
                'uuid' => (string) ($row['uuid'] ?? ''),
                'level' => (int) ($row['level'] ?? 4),
            ], $this->json($server, self::OPS)),
            'bans' => array_map(fn (array $row): array => [
                'name' => (string) ($row['name'] ?? ''),
                'uuid' => (string) ($row['uuid'] ?? ''),
                'reason' => $row['reason'] ?? null,
                'source' => $row['source'] ?? null,
                'created' => $row['created'] ?? null,
                'expires' => $row['expires'] ?? null,
            ], $this->json($server, self::BANNED_PLAYERS)),
            'ip_bans' => array_map(fn (array $row): array => [
                'ip' => (string) ($row['ip'] ?? ''),
                'reason' => $row['reason'] ?? null,
                'source' => $row['source'] ?? null,
                'created' => $row['created'] ?? null,
                'expires' => $row['expires'] ?? null,
            ], $this->json($server, self::BANNED_IPS)),
            'known' => $this->known($server),
        ];
    }

    /**
     * Players who have joined the server before, most recently seen first.
     *
     * @return list<array{name: string, uuid: string, last_seen: ?string}>
     */
    private function known(Server $server): array
    {
        $players = [];
        foreach ($this->json($server, self::USERCACHE) as $row) {
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
            'op' => $this->upsert($server, self::OPS, [...$this->profile($server, $target), 'level' => 4, 'bypassesPlayerLimit' => false]),
            'deop' => $this->remove($server, self::OPS, 'name', $target),
            'ban' => $this->upsert($server, self::BANNED_PLAYERS, [
                ...$this->profile($server, $target),
                ...$this->banFields($reason, $actor),
            ]),
            'pardon' => $this->remove($server, self::BANNED_PLAYERS, 'name', $target),
            'ban-ip' => $this->upsert($server, self::BANNED_IPS, ['ip' => $target, ...$this->banFields($reason, $actor)], 'ip'),
            'pardon-ip' => $this->remove($server, self::BANNED_IPS, 'ip', $target),
            'whitelist-on' => $this->setProperty($server, 'white-list', 'true'),
            'whitelist-off' => $this->setProperty($server, 'white-list', 'false'),
            default => throw new HttpException(409, 'Start the server to do this.'),
        };
    }

    /**
     * The UUID and correctly-cased name for a player: Mojang's for online-mode
     * servers, the derived offline UUID otherwise.
     *
     * @return array{uuid: string, name: string}
     */
    private function profile(Server $server, string $name): array
    {
        if (str_starts_with($name, '.') || ($this->properties($server)['online-mode'] ?? 'true') === 'false') {
            return ['uuid' => Validates::offlineUuid($name), 'name' => $name];
        }

        $response = Http::withUserAgent('pterodactyl-mc-players/1.0')
            ->connectTimeout(5)
            ->timeout(10)
            ->get('https://api.mojang.com/users/profiles/minecraft/'.rawurlencode($name));

        if ($response->status() === 404 || $response->status() === 204) {
            throw new HttpException(422, 'There is no Minecraft account called '.$name.'.');
        }

        if (! $response->successful() || ! is_string($response->json('id'))) {
            throw new HttpException(502, 'Could not look up '.$name.' with Mojang. Try again, or start the server and use the console.');
        }

        return ['uuid' => Validates::dashed($response->json('id')), 'name' => (string) $response->json('name', $name)];
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
     */
    private function upsert(Server $server, string $file, array $entry, string $key = 'uuid'): void
    {
        $rows = array_values(array_filter(
            $this->json($server, $file),
            fn (array $row): bool => strcasecmp((string) ($row[$key] ?? ''), (string) $entry[$key]) !== 0
                && ($key !== 'uuid' || strcasecmp((string) ($row['name'] ?? ''), (string) ($entry['name'] ?? '')) !== 0),
        ));
        $rows[] = $entry;

        $this->writer->write($server, $file, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }

    private function remove(Server $server, string $file, string $key, string $value): void
    {
        $rows = $this->json($server, $file);
        $kept = array_values(array_filter($rows, fn (array $row): bool => strcasecmp((string) ($row[$key] ?? ''), $value) !== 0));

        if (count($kept) === count($rows)) {
            throw new HttpException(404, $value.' is not on that list.');
        }

        $this->writer->write($server, $file, json_encode($kept, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
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
    }

    /**
     * @return array<string, string>
     */
    private function properties(Server $server): array
    {
        $properties = [];
        foreach (preg_split('/\R/', $this->read($server, self::PROPERTIES) ?? '') ?: [] as $line) {
            if ($line === '' || $line[0] === '#' || ! str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $properties[trim($key)] = trim($value);
        }

        return $properties;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function json(Server $server, string $file): array
    {
        $decoded = json_decode($this->read($server, $file) ?? '[]', true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
    }

    private function read(Server $server, string $file): ?string
    {
        try {
            return $this->reader->read($server, $file, self::MAX_BYTES);
        } catch (DaemonConnectionException $exception) {
            if ($exception->getStatusCode() === 404) {
                return null;
            }

            throw $exception;
        }
    }
}
