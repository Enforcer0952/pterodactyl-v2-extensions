<?php

namespace McVersion\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use McVersion\Services\Overview;
use McVersion\Services\Startup;
use McVersion\Services\Switcher;
use McVersion\Services\Upstream;
use McVersion\Support\MinecraftServer;
use McVersion\Support\Software;
use McVersion\Support\Versions;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Models\Server;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The Version page: what a Minecraft Java server runs, the versions it can switch to,
 * the switch itself, restoring the previous jar and fixing the Java version.
 */
class VersionController extends Controller
{
    public function __construct(
        private readonly Overview $overview,
        private readonly Upstream $upstream,
        private readonly Switcher $switcher,
        private readonly Startup $startup,
    ) {}

    public function show(Request $request, Server $server): JsonResponse
    {
        $this->authorizeView($request, $server);

        return new JsonResponse($this->node(fn (): array => $this->overview->build($server, $request->user())));
    }

    public function versions(Request $request, Server $server): JsonResponse
    {
        $this->authorizeView($request, $server);
        $data = $request->validate([
            'software' => ['required', 'string', 'in:'.implode(',', Software::INSTALLABLE)],
            'experimental' => ['sometimes', 'boolean'],
        ]);

        return new JsonResponse([
            'software' => $data['software'],
            'versions' => $this->upstream->versions($data['software'], $request->boolean('experimental')),
        ]);
    }

    public function plan(Request $request, Server $server): JsonResponse
    {
        $this->authorizeView($request, $server);
        $data = $this->target($request);

        return new JsonResponse($this->node(fn (): array => $this->switcher->plan($server, $data['software'], $data['version'], $request->boolean('experimental'))));
    }

    public function apply(Request $request, Server $server): JsonResponse
    {
        $this->authorizeChange($request, $server);
        $data = $this->target($request);
        $request->validate(['confirmed' => ['sometimes', 'boolean'], 'set_java' => ['sometimes', 'boolean']]);

        $result = $this->node(fn (): array => $this->switcher->apply(
            $server,
            $request->user(),
            $data['software'],
            $data['version'],
            $request->boolean('experimental'),
            $request->boolean('confirmed'),
            $request->boolean('set_java', true),
        ));

        return new JsonResponse($result + ['overview' => $this->node(fn (): array => $this->overview->build($server, $request->user()))]);
    }

    public function rollback(Request $request, Server $server): JsonResponse
    {
        $this->authorizeChange($request, $server);
        $request->validate(['restore_java' => ['sometimes', 'boolean']]);

        $result = $this->node(fn (): array => $this->switcher->rollback($server, $request->user(), $request->boolean('restore_java', true)));

        return new JsonResponse($result + ['overview' => $this->node(fn (): array => $this->overview->build($server, $request->user()))]);
    }

    /**
     * Switch the server to another Java image the egg offers. Takes effect on the next start.
     */
    public function java(Request $request, Server $server): JsonResponse
    {
        $this->authorizeView($request, $server);
        abort_unless($request->user()->can('startup.docker-image', $server), 403, 'You do not have permission to change this server\'s Docker image.');
        $data = $request->validate(['image' => ['required', 'string', 'max:255']]);

        $this->startup->setImage($server, $data['image']);

        return new JsonResponse(['overview' => $this->node(fn (): array => $this->overview->build($server, $request->user()))]);
    }

    /** Not a Minecraft Java server: answer as if the endpoint does not exist. */
    private function authorizeView(Request $request, Server $server): void
    {
        abort_unless(MinecraftServer::isJava($server), 404);
        abort_unless(
            $request->user()->can('file.read', $server) && $request->user()->can('startup.read', $server),
            403,
            'You need permission to read files and startup settings to use this page.',
        );
    }

    private function authorizeChange(Request $request, Server $server): void
    {
        $this->authorizeView($request, $server);
        foreach (['file.update', 'file.create', 'startup.update'] as $permission) {
            abort_unless($request->user()->can($permission, $server), 403, 'You need permission to update and create files and to change startup settings to switch versions.');
        }
    }

    /**
     * @return array{software: string, version: string}
     */
    private function target(Request $request): array
    {
        $data = $request->validate([
            'software' => ['required', 'string', 'in:'.implode(',', Software::INSTALLABLE)],
            'version' => ['required', 'string', 'max:40', fn (string $attribute, mixed $value, \Closure $fail) => is_string($value) && Versions::isValid($value) ? null : $fail('That is not a Minecraft version.')],
            'experimental' => ['sometimes', 'boolean'],
        ]);

        return ['software' => $data['software'], 'version' => $data['version']];
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    private function node(\Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (DaemonConnectionException $exception) {
            // Wings answered but refused: say so; a node that did not answer is the common case.
            throw new HttpException(502, $exception->getStatusCode() === 504 ? 'Could not reach this server\'s node.' : $exception->getMessage(), $exception);
        }
    }
}
