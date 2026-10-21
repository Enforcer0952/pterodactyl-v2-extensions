<?php

namespace ArkTools\Http\Controllers;

use ArkTools\Http\Controllers\Concerns\ArkServer;
use ArkTools\Services\Restarts;
use ArkTools\Support\Ark;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Pterodactyl\Contracts\Servers\ReadsServerState;
use Pterodactyl\Contracts\Servers\SendsServerCommands;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Models\Server;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * One-click admin commands and safe restarts.
 */
class AdminController extends Controller
{
    use ArkServer;

    public function __construct(
        private readonly ReadsServerState $state,
        private readonly SendsServerCommands $commands,
        private readonly Restarts $restarts,
    ) {}

    public function command(Request $request, Server $server): JsonResponse
    {
        $this->ensureArk($server);
        $this->ensureCan($request, $server, 'ext.ark-tools.admin', 'You do not have permission to run admin commands on this server.');
        $this->ensureCan($request, $server, 'control.console', 'You need permission to send console commands for this.');

        $data = $request->validate([
            'action' => ['required', 'string', 'in:save,wild-dinos,broadcast,chat'],
            'message' => ['nullable', 'string', 'max:300'],
        ]);

        if ($this->serverState($this->state, $server) !== 'running') {
            throw new HttpException(409, 'Start the server first.');
        }

        $message = Ark::text($data['message'] ?? null);
        if (in_array($data['action'], ['broadcast', 'chat'], true) && $message === null) {
            throw new HttpException(422, 'Type a message first.');
        }

        $command = match ($data['action']) {
            'save' => 'SaveWorld',
            'wild-dinos' => 'DestroyWildDinos',
            'broadcast' => 'Broadcast '.$message,
            'chat' => 'ServerChat '.$message,
        };

        // The panel's command action records this in the activity log.
        $this->commands->send($server, $command);

        return new JsonResponse(['sent' => $command]);
    }

    public function restartStatus(Request $request, Server $server): JsonResponse
    {
        $this->ensureArk($server);
        $this->ensureCan($request, $server, 'ext.ark-tools.restart', 'You do not have permission to restart this server.');

        return new JsonResponse($this->restarts->status($server));
    }

    public function restart(Request $request, Server $server): JsonResponse
    {
        $this->ensureArk($server);
        $this->ensureCan($request, $server, 'ext.ark-tools.restart', 'You do not have permission to restart this server.');
        $this->ensureCan($request, $server, 'control.restart', 'You need permission to restart this server for this.');

        $minutes = (int) $request->validate(['minutes' => ['required', 'integer', 'in:'.implode(',', Restarts::MINUTES)]])['minutes'];

        if ($this->serverState($this->state, $server) !== 'running') {
            throw new HttpException(409, 'The server is not running. Use the power buttons to start it.');
        }

        $this->restarts->start($server, $minutes);
        Activity::event('server:power.restart')->property('countdown_minutes', $minutes)->log();

        return new JsonResponse($this->restarts->status($server));
    }

    public function cancel(Request $request, Server $server): JsonResponse
    {
        $this->ensureArk($server);
        $this->ensureCan($request, $server, 'ext.ark-tools.restart', 'You do not have permission to restart this server.');
        $this->ensureCan($request, $server, 'control.restart', 'You need permission to restart this server for this.');

        if (! $this->restarts->cancel($server)) {
            throw new HttpException(404, 'No restart is counting down.');
        }

        if ($this->serverState($this->state, $server) === 'running') {
            $this->commands->send($server, 'Broadcast The server restart has been cancelled.');
        }

        return new JsonResponse($this->restarts->status($server));
    }

    public function daily(Request $request, Server $server): JsonResponse
    {
        $this->ensureArk($server);
        $this->ensureCan($request, $server, 'ext.ark-tools.restart', 'You do not have permission to restart this server.');
        $this->ensureCan($request, $server, 'control.restart', 'You need permission to restart this server for this.');
        $this->ensureCan($request, $server, 'schedule.create', 'You need permission to create schedules for this.');

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'time' => ['required', 'string', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'minutes' => ['required', 'integer', 'in:'.implode(',', Restarts::MINUTES)],
        ]);

        $this->restarts->daily($server, (bool) $data['enabled'], $data['time'], (int) $data['minutes']);

        return new JsonResponse($this->restarts->status($server));
    }
}
