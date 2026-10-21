<?php

namespace ArkTools\Http\Controllers\Concerns;

use ArkTools\Support\Ark;
use Illuminate\Http\Request;
use Pterodactyl\Contracts\Servers\ReadsServerState;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Models\Server;
use Symfony\Component\HttpKernel\Exception\HttpException;

trait ArkServer
{
    /** Not an ARK server: answer as if the endpoint does not exist. */
    protected function ensureArk(Server $server): void
    {
        abort_unless(Ark::isArk($server), 404);
    }

    protected function ensureCan(Request $request, Server $server, string $permission, string $message): void
    {
        abort_unless($request->user()->can($permission, $server), 403, $message);
    }

    protected function serverState(ReadsServerState $state, Server $server): string
    {
        try {
            \Illuminate\Support\Facades\Cache::forget('resources:'.$server->uuid); // the panel caches power state for 20 s
            return $state->read($server)->state;
        } catch (DaemonConnectionException) {
            throw new HttpException(502, 'Could not reach this server\'s node.');
        }
    }
}
