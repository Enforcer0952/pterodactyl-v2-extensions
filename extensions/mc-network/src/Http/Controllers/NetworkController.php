<?php

namespace McNetwork\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use McNetwork\Services\Network;
use McNetwork\Support\Eggs;
use McNetwork\Support\Validate;
use Pterodactyl\Contracts\Servers\SendsServerPower;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Models\Server;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The Network page of a BungeeCord or Waterfall proxy: its backend servers, what is
 * wrong with them, and edits to config.yml.
 */
class NetworkController extends Controller
{
    public function __construct(
        private readonly Network $network,
        private readonly SendsServerPower $power,
    ) {}

    public function show(Request $request, Server $server): JsonResponse
    {
        $this->authorizeFor($request, $server, 'file.read', 'You do not have permission to read this server\'s files.');

        return new JsonResponse($this->guard(fn (): array => $this->network->load($server, $request->user())));
    }

    /** Problems of a draft that is not saved yet. Nothing is written. */
    public function check(Request $request, Server $server): JsonResponse
    {
        $this->authorizeFor($request, $server, 'file.read', 'You do not have permission to read this server\'s files.');
        $draft = $this->draft($request);

        return new JsonResponse($this->guard(fn (): array => $this->network->check($server, $request->user(), $draft)));
    }

    public function update(Request $request, Server $server): JsonResponse
    {
        $this->authorizeFor($request, $server, 'file.update', 'You do not have permission to change this server\'s files.');
        $draft = $this->draft($request);
        $hash = (string) $request->validate(['hash' => ['required', 'string', 'size:40']])['hash'];

        return new JsonResponse($this->guard(fn (): array => $this->network->save($server, $request->user(), $draft, $hash)));
    }

    public function restart(Request $request, Server $server): JsonResponse
    {
        $this->authorizeFor($request, $server, 'control.restart', 'You do not have permission to restart this server.');

        $this->guard(function () use ($server): void {
            $this->power->send($server, 'restart');
        });

        return new JsonResponse(['restarting' => true]);
    }

    /**
     * @return array{servers: list<array<string, mixed>>, priorities: list<string>, forced_hosts: list<array{host: string, server: string}>}
     */
    private function draft(Request $request): array
    {
        $data = $request->validate([
            'servers' => ['present', 'array', 'max:'.Validate::MAX_SERVERS],
            'servers.*.name' => ['required', 'string', 'max:64'],
            'servers.*.address' => ['present', 'nullable', 'string', 'max:300'],
            'servers.*.motd' => ['present', 'nullable', 'string', 'max:2000'],
            'servers.*.restricted' => ['present', 'boolean'],
            'servers.*.was' => ['nullable', 'string', 'max:64'],
            'priorities' => ['present', 'array', 'max:'.Validate::MAX_SERVERS],
            'priorities.*' => ['string', 'max:64'],
            'forced_hosts' => ['present', 'array', 'max:'.Validate::MAX_FORCED_HOSTS],
            'forced_hosts.*.host' => ['required', 'string', 'max:253'],
            'forced_hosts.*.server' => ['required', 'string', 'max:64'],
        ]);

        return [
            'servers' => array_values(array_map(fn (array $server): array => [
                'name' => $server['name'],
                'address' => trim((string) ($server['address'] ?? '')),
                'motd' => (string) ($server['motd'] ?? ''),
                'restricted' => (bool) $server['restricted'],
                'was' => $server['was'] ?? null,
            ], $data['servers'])),
            'priorities' => array_values($data['priorities']),
            // Browsers send host names in lower case, so that is how they are kept.
            'forced_hosts' => array_values(array_map(fn (array $forced): array => [
                'host' => strtolower($forced['host']),
                'server' => $forced['server'],
            ], $data['forced_hosts'])),
        ];
    }

    private function authorizeFor(Request $request, Server $server, string $permission, string $message): void
    {
        abort_unless(Eggs::isProxy($server->tagSlugs()), 404);
        abort_unless($request->user()->can($permission, $server), 403, $message);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function guard(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (DaemonConnectionException) {
            throw new HttpException(502, 'Could not reach this server\'s node.');
        }
    }
}
