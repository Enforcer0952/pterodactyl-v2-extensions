<?php

namespace CrashGuard\Http\Controllers;

use CrashGuard\Services\Guard;
use CrashGuard\Support\Limits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Pterodactyl\Contracts\Servers\SendsServerPower;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Facades\Extensions;
use Pterodactyl\Models\Server;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * A server's Crash Guard: read it, change its limits, clear it.
 */
class GuardController extends Controller
{
    public function __construct(
        private readonly Guard $guard,
        private readonly SendsServerPower $power,
    ) {}

    public function show(Request $request, Server $server): JsonResponse
    {
        $this->ensureCan($request, $server, 'ext.crash-guard.view', 'You do not have permission to see Crash Guard on this server.');

        return new JsonResponse($this->payload($server));
    }

    public function update(Request $request, Server $server): JsonResponse
    {
        $this->ensureCan($request, $server, 'ext.crash-guard.manage', 'You do not have permission to change Crash Guard on this server.');

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'max_crashes' => ['required', 'integer', 'min:'.Limits::MIN_CRASHES, 'max:'.Limits::MAX_CRASHES],
            'window_minutes' => ['required', 'integer', 'min:'.Limits::MIN_MINUTES, 'max:'.Limits::MAX_MINUTES],
        ]);

        $this->guard->save($server, (bool) $data['enabled'], (int) $data['max_crashes'], (int) $data['window_minutes']);

        Activity::event('server:crash-guard.update')->subject($server)->property([
            'enabled' => (bool) $data['enabled'],
            'max_crashes' => (int) $data['max_crashes'],
            'window_minutes' => (int) $data['window_minutes'],
        ])->log();

        return new JsonResponse($this->payload($server));
    }

    public function clear(Request $request, Server $server): JsonResponse
    {
        $this->ensureCan($request, $server, 'ext.crash-guard.manage', 'You do not have permission to clear Crash Guard on this server.');

        $request->validate(['start' => ['sometimes', 'boolean']]);
        $start = $request->boolean('start');
        if ($start) {
            $this->ensureCan($request, $server, 'control.start', 'You do not have permission to start this server.');
        }

        $this->guard->clear($server);
        Activity::event('server:crash-guard.clear')->subject($server)->property('start', $start)->log();

        if ($start) {
            try {
                $this->power->send($server, 'start');
            } catch (DaemonConnectionException) {
                throw new HttpException(502, 'Crash Guard was cleared, but this server\'s node could not be reached to start it.');
            } catch (Throwable $throwable) {
                throw new HttpException(502, 'Crash Guard was cleared, but the server could not be started: '.mb_substr($throwable->getMessage(), 0, 300));
            }
        }

        return new JsonResponse($this->payload($server));
    }

    /** @return array<string, mixed> */
    private function payload(Server $server): array
    {
        return [
            ...$this->guard->status($server),
            'mc_crash' => $this->mcCrashInstalled(),
        ];
    }

    private function mcCrashInstalled(): bool
    {
        try {
            return Extensions::isAvailable('mc-crash');
        } catch (Throwable) {
            return false;
        }
    }

    private function ensureCan(Request $request, Server $server, string $permission, string $message): void
    {
        abort_unless($request->user()->can($permission, $server), 403, $message);
    }
}
