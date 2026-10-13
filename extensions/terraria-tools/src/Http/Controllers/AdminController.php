<?php

namespace TerrariaTools\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Pterodactyl\Contracts\Servers\ReadsServerState;
use Pterodactyl\Contracts\Servers\SendsServerCommands;
use Pterodactyl\Models\Server;
use Symfony\Component\HttpKernel\Exception\HttpException;
use TerrariaTools\Http\Controllers\Concerns\TerrariaServer;
use TerrariaTools\Support\Terraria;

/**
 * One-click console commands: save, settle liquids, set the time and send a message.
 */
class AdminController extends Controller
{
    use TerrariaServer;

    /** Action => console command. "say" takes a message. */
    private const array COMMANDS = [
        'save' => 'save',
        'settle' => 'settle',
        'dawn' => 'dawn',
        'noon' => 'noon',
        'dusk' => 'dusk',
        'midnight' => 'midnight',
        'say' => 'say',
    ];

    public function __construct(
        private readonly ReadsServerState $state,
        private readonly SendsServerCommands $commands,
    ) {}

    public function command(Request $request, Server $server): JsonResponse
    {
        $this->ensureTerraria($server);
        $this->ensureCan($request, $server, ['ext.terraria-tools.admin', 'control.console'], 'You do not have permission to run admin commands on this server.');

        $data = $request->validate([
            'action' => ['required', 'string', 'in:'.implode(',', array_keys(self::COMMANDS))],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($this->serverState($this->state, $server) !== 'running') {
            throw new HttpException(409, 'Start the server first.');
        }

        $command = self::COMMANDS[$data['action']];
        if ($data['action'] === 'say') {
            $message = Terraria::text($data['message'] ?? null, 10000);
            if ($message === null) {
                throw new HttpException(422, 'Type a message first.');
            }
            if (mb_strlen($message) > Terraria::MAX_MESSAGE) {
                throw new HttpException(422, 'A message can be at most '.Terraria::MAX_MESSAGE.' characters.');
            }
            $command .= ' '.$message;
        }

        // The panel's command action records this in the activity log.
        $this->commands->send($server, $command);

        return new JsonResponse(['sent' => $command]);
    }
}
