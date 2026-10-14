<?php

namespace McCrash\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use McCrash\Support\MinecraftServer;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Models\Server;
use Symfony\Component\HttpKernel\Exception\HttpException;

trait CrashServer
{
    /**
     * Not a Java server or proxy: answer as if the endpoint does not exist. Then the permissions:
     * the extension's own view permission and the panel's right to read file contents.
     */
    protected function authorizeView(Request $request, Server $server): void
    {
        abort_unless(MinecraftServer::isJavaOrProxy($server), 404);
        abort_unless($request->user()->can('ext.mc-crash.view', $server), 403, 'You do not have permission to see crash reports on this server.');
        abort_unless($request->user()->can('file.read-content', $server), 403, 'You need permission to read file contents to see crash reports.');
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    protected function node(\Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (DaemonConnectionException) {
            throw new HttpException(502, 'Could not reach this server\'s node.');
        }
    }
}
