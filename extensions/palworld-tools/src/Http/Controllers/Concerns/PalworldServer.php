<?php

namespace PalworldTools\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use PalworldTools\Support\Palworld;
use Pterodactyl\Contracts\Servers\ReadsServerState;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Models\Server;
use Symfony\Component\HttpKernel\Exception\HttpException;

trait PalworldServer
{
    /** Not a Palworld server: answer as if the endpoint does not exist. */
    protected function ensurePalworld(Server $server): void
    {
        abort_unless(Palworld::isPalworld($server), 404);
    }

    /**
     * The user needs every one of these permissions: the extension's own and the
     * panel's, so a page never does more than the file manager or console would.
     *
     * @param  string|list<string>  $permissions
     */
    protected function ensureCan(Request $request, Server $server, string|array $permissions, string $message = 'You do not have permission to do that on this server.'): void
    {
        foreach ((array) $permissions as $permission) {
            abort_unless($request->user()->can($permission, $server), 403, $message);
        }
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

    protected function ensureStopped(ReadsServerState $state, Server $server, string $message): void
    {
        $current = $this->serverState($state, $server);
        if ($current !== 'offline') {
            throw new HttpException(409, $message.' The server is '.$current.'.');
        }
    }
}
