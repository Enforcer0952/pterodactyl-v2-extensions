<?php

namespace PalworldTools\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use PalworldTools\Http\Controllers\Concerns\PalworldServer;
use PalworldTools\Services\RestClient;
use PalworldTools\Services\RestException;
use PalworldTools\Support\Palworld;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Models\Server;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The Players card on the console page, through the game's REST API.
 */
class PlayersController extends Controller
{
    use PalworldServer;

    public function __construct(private readonly RestClient $rest) {}

    public function show(Request $request, Server $server): JsonResponse
    {
        $this->ensurePalworld($server);
        $this->ensureCan($request, $server, 'ext.palworld-tools.view', 'You do not have permission to see the players.');

        return new JsonResponse($this->rest->overview($server));
    }

    public function action(Request $request, Server $server): JsonResponse
    {
        $this->ensurePalworld($server);
        $this->ensureCan($request, $server, 'ext.palworld-tools.players', 'You do not have permission to manage players.');

        $data = $request->validate([
            'action' => ['required', 'string', 'in:kick,ban,unban,announce,save'],
            'user_id' => ['required_if:action,kick,ban,unban', 'nullable', 'string', 'max:64', 'regex:'.Palworld::USER_ID],
            'message' => ['required_if:action,announce', 'nullable', 'string', 'max:300'],
        ]);

        $action = $data['action'];
        $userId = $data['user_id'] ?? null;
        $message = Palworld::text($data['message'] ?? null, $action === 'announce' ? 200 : 100);
        if ($action === 'announce' && $message === null) {
            throw new HttpException(422, 'Write a message to announce.');
        }

        $path = '/'.$action;
        $body = match ($action) {
            'kick', 'ban' => array_filter(['userid' => $userId, 'message' => $message], fn (?string $value): bool => $value !== null),
            'unban' => ['userid' => $userId],
            'announce' => ['message' => $message],
            default => [],
        };

        try {
            $this->rest->post($server, $path, $body);
        } catch (RestException $exception) {
            throw new HttpException(in_array($exception->reason, [RestException::AUTH, RestException::FAILED], true) ? 502 : 409, $exception->getMessage());
        } catch (DaemonConnectionException) {
            throw new HttpException(502, 'Could not reach this server\'s node.');
        }

        // The REST API is not part of the panel's own actions, so it is logged here.
        Activity::event('server:palworld.'.$action)->subject($server)->property(array_filter(['user_id' => $userId, 'message' => $message]))->log();

        return new JsonResponse($this->rest->overview($server));
    }
}
