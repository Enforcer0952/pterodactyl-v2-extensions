<?php

namespace PalworldTools\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use PalworldTools\Support\OptionSettings;
use PalworldTools\Support\RestResponses;
use Pterodactyl\Contracts\Servers\ReadsServerState;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\Server;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Talks to the game's REST API: http://<allocation ip>:<RESTAPIPort>/v1/api/..., with
 * HTTP Basic auth as "admin" and the AdminPassword.
 * https://docs.palworldgame.com/category/rest-api
 *
 * The panel only connects to a port that is one of this server's own allocations. The
 * port comes from a file the server's users can edit, so anything else could aim the
 * panel at other services on the node.
 */
class RestClient
{
    public const string BASE = '/v1/api';

    private const int DEFAULT_PORT = 8212;

    public function __construct(
        private readonly SettingsFiles $files,
        private readonly ReadsServerState $state,
    ) {}

    /**
     * What the Players card shows: live data, or the reason there is none.
     *
     * @return array<string, mixed>
     */
    public function overview(Server $server): array
    {
        try {
            $state = $this->state->read($server)->state;
        } catch (DaemonConnectionException) {
            throw new HttpException(502, 'Could not reach this server\'s node.');
        }

        $result = ['state' => $state, 'available' => false, 'reason' => null, 'message' => null, 'port' => null, 'info' => null, 'metrics' => null, 'players' => []];

        try {
            $target = $this->target($server, $state);
            $result['port'] = $target['port'];
            $result['info'] = RestResponses::info($this->request($target, 'get', '/info'));
            $result['players'] = RestResponses::players($this->request($target, 'get', '/players'));
            try {
                $result['metrics'] = RestResponses::metrics($this->request($target, 'get', '/metrics'));
            } catch (RestException) {
                // The player list is the point; metrics are a bonus.
            }
            $result['available'] = true;
        } catch (RestException $exception) {
            $result['reason'] = $exception->reason;
            $result['message'] = $exception->getMessage();
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $body
     *
     * @throws RestException
     */
    public function post(Server $server, string $path, array $body = []): void
    {
        $state = $this->state->read($server)->state;
        $this->request($this->target($server, $state), 'post', $path, $body);
    }

    /**
     * @return array{host: string, port: int, password: string}
     *
     * @throws RestException
     */
    public function target(Server $server, string $state): array
    {
        if ($state === 'offline') {
            throw new RestException(RestException::OFFLINE, 'The server is offline.');
        }

        $text = $this->files->read($server);
        try {
            $options = $text === null ? null : OptionSettings::parse($text);
        } catch (InvalidArgumentException) {
            $options = null;
        }

        if (strcasecmp((string) $options?->get('RESTAPIEnabled'), 'True') !== 0) {
            throw new RestException(RestException::DISABLED, 'The REST API is off on this server.');
        }
        $port = (int) ($options->get('RESTAPIPort') ?? self::DEFAULT_PORT);
        $password = (string) $options->get('AdminPassword');
        if ($password === '') {
            throw new RestException(RestException::NO_PASSWORD, 'The REST API needs an admin password. Set one in the settings.');
        }

        $host = $this->host($server, $port);
        if ($host === null) {
            throw new RestException(RestException::NOT_ALLOCATED, 'Port '.$port.' is not one of this server\'s ports, so the panel cannot reach the REST API.');
        }

        return ['host' => $host, 'port' => $port, 'password' => $password];
    }

    /** Whether the port is one of this server's allocations. */
    public function isAllocated(Server $server, int $port): bool
    {
        return $this->host($server, $port) !== null;
    }

    /** The address to reach one of the server's own ports on, or null for a port it does not have. */
    private function host(Server $server, int $port): ?string
    {
        $server->loadMissing('allocation', 'node');
        foreach ([$server->allocation, ...$server->allocations()->get()->all()] as $allocation) {
            if ($allocation instanceof Allocation && (int) $allocation->port === $port) {
                $host = in_array($allocation->ip, ['0.0.0.0', '::', ''], true) ? (string) $server->node?->fqdn : $allocation->ip;

                return $host === '' ? null : $host;
            }
        }

        return null;
    }

    /**
     * @param  array{host: string, port: int, password: string}  $target
     * @param  array<string, mixed>  $body
     *
     * @throws RestException
     */
    private function request(array $target, string $method, string $path, array $body = []): mixed
    {
        $url = 'http://'.(str_contains($target['host'], ':') ? '['.$target['host'].']' : $target['host']).':'.$target['port'].self::BASE.$path;

        try {
            /** @var PendingRequest $client */
            $client = Http::withBasicAuth('admin', $target['password'])
                ->acceptJson()
                ->connectTimeout(3)
                ->timeout(6)
                ->withoutRedirecting();
            $response = $method === 'post' ? $client->asJson()->post($url, $body === [] ? new \stdClass : $body) : $client->get($url);
        } catch (ConnectionException) {
            throw new RestException(RestException::UNREACHABLE, 'The panel could not connect to the REST API on port '.$target['port'].'.');
        }

        if (in_array($response->status(), [401, 403], true)) {
            throw new RestException(RestException::AUTH, 'The REST API refused the admin password.', $response->status());
        }
        if (! $response->successful()) {
            // Only the status: the body comes from whatever listens on that port.
            throw new RestException(RestException::FAILED, 'The REST API answered with error '.$response->status().'.', $response->status());
        }

        $json = $response->json();

        return is_array($json) ? $json : null;
    }
}
