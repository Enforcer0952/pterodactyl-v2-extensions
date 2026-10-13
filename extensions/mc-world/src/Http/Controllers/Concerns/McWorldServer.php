<?php

namespace McWorld\Http\Controllers\Concerns;

use Closure;
use Illuminate\Http\Request;
use McWorld\Support\MinecraftServer;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Models\Server;
use Symfony\Component\HttpKernel\Exception\HttpException;

trait McWorldServer
{
    /** Not a Minecraft Java server: answer as if the endpoint does not exist. */
    protected function ensureJava(Server $server): void
    {
        abort_unless(MinecraftServer::isJava($server), 404);
    }

    /**
     * @param  list<string>  $permissions  all of these are needed
     */
    protected function ensureCan(Request $request, Server $server, array $permissions, string $message): void
    {
        foreach ($permissions as $permission) {
            abort_unless($request->user()->can($permission, $server), 403, $message);
        }
    }

    /**
     * Runs a call that talks to the node and reports an unreachable node in plain words.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    protected function guard(Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (DaemonConnectionException $exception) {
            if (in_array($exception->getStatusCode(), [502, 503, 504, 0], true)) {
                throw new HttpException(502, 'Could not reach this server\'s node.');
            }

            throw new HttpException(502, 'The node could not do that: '.$exception->getMessage());
        }
    }
}
