<?php

namespace ArkTools\Http\Controllers;

use ArkTools\Http\Controllers\Concerns\ArkServer;
use ArkTools\Services\KnownPlayers;
use ArkTools\Services\PlayerFiles;
use ArkTools\Services\StartupVariables;
use ArkTools\Support\Ark;
use ArkTools\Support\ListPlayersOutput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Pterodactyl\Contracts\Servers\ReadsServerLogs;
use Pterodactyl\Contracts\Servers\ReadsServerState;
use Pterodactyl\Contracts\Servers\SendsServerCommands;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Models\Server;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Online players, the whitelist, ban list and admin list. The ARK egg's console is
 * an RCON client, so console commands are RCON commands and their answers show up
 * in the console log.
 */
class PlayersController extends Controller
{
    use ArkServer;

    /** Action => [extension permission, RCON command or null when there is none]. */
    private const array ACTIONS = [
        'kick' => ['kick', 'KickPlayer'],
        'ban' => ['ban', 'BanPlayer'],
        'unban' => ['ban', 'UnbanPlayer'],
        'whitelist-add' => ['whitelist', 'AllowPlayerToJoinNoCheck'],
        'whitelist-remove' => ['whitelist', 'DisallowPlayerToJoinNoCheck'],
        'admin-add' => ['admin', null],
        'admin-remove' => ['admin', null],
    ];

    /** Launch option that makes ARK accept only whitelisted players. */
    private const string EXCLUSIVE = '-exclusivejoin';

    private const int ANSWER_DELAY_MS = 900;

    public function __construct(
        private readonly ReadsServerState $state,
        private readonly SendsServerCommands $commands,
        private readonly ReadsServerLogs $logs,
        private readonly PlayerFiles $files,
        private readonly KnownPlayers $known,
        private readonly StartupVariables $variables,
    ) {}

    /**
     * Who is online. Runs ListPlayers and reads the answer from the console; shared
     * for a few seconds so several people watching the console do not flood RCON.
     */
    public function online(Request $request, Server $server): JsonResponse
    {
        $this->authorizeView($request, $server);

        $state = $this->serverState($this->state, $server);
        if ($state !== 'running') {
            return new JsonResponse(['state' => $state, 'online' => null]);
        }

        $online = Cache::remember('ext:ark-tools:online:'.$server->uuid, 8, function () use ($server) {
            $this->commands->send($server, 'ListPlayers');
            usleep(self::ANSWER_DELAY_MS * 1000);

            return ListPlayersOutput::parse($this->logs->read($server, 100));
        });

        if (is_array($online)) {
            $this->known->remember($server, $online);
        }

        return new JsonResponse(['state' => $state, 'online' => $online]);
    }

    public function lists(Request $request, Server $server): JsonResponse
    {
        $this->authorizeView($request, $server);

        return new JsonResponse($this->listsPayload($server));
    }

    public function action(Request $request, Server $server): JsonResponse
    {
        $this->authorizeView($request, $server);

        $data = $request->validate([
            'action' => ['required', 'string', 'in:'.implode(',', array_keys(self::ACTIONS))],
            'steam_id' => ['required', 'string', 'regex:'.Ark::STEAM_ID],
        ]);

        [$permission, $command] = self::ACTIONS[$data['action']];
        $this->ensureCan($request, $server, 'ext.ark-tools.'.$permission, 'You do not have permission to do that on this server.');

        $id = $data['steam_id'];
        $state = $this->serverState($this->state, $server);

        if ($command !== null && $state === 'running') {
            $this->commands->send($server, $command.' '.$id);
            Activity::event('server:console.command')->property('command', $command.' '.$id)->log();
            // Give ARK a moment to write its list file before it is read back.
            usleep(self::ANSWER_DELAY_MS * 1000);
            Cache::forget('ext:ark-tools:online:'.$server->uuid);
        } elseif ($data['action'] === 'kick') {
            throw new HttpException(409, 'Start the server to kick players.');
        } elseif ($command !== null && $state !== 'offline') {
            throw new HttpException(409, 'The server is '.$state.'. Wait until it is running or stopped.');
        } else {
            // Stopped server, or the admin list, which has no RCON command: edit the file.
            [$list, $add] = match ($data['action']) {
                'ban' => ['bans', true],
                'unban' => ['bans', false],
                'whitelist-add' => ['whitelist', true],
                'whitelist-remove' => ['whitelist', false],
                'admin-add' => ['admins', true],
                'admin-remove' => ['admins', false],
            };

            if ($add) {
                $this->files->add($server, $list, $id);
            } elseif (! $this->files->remove($server, $list, $id)) {
                throw new HttpException(404, $id.' is not on that list.');
            }
            Activity::event('server:file.write')->property('file', $list.' list ('.$data['action'].' '.$id.')')->log();
        }

        return new JsonResponse($this->listsPayload($server));
    }

    /**
     * Turn the whitelist on or off: adds or removes -exclusivejoin in the ARGS
     * startup variable. Takes effect on the next restart.
     */
    public function whitelistMode(Request $request, Server $server): JsonResponse
    {
        $this->authorizeView($request, $server);
        $this->ensureCan($request, $server, 'ext.ark-tools.whitelist', 'You do not have permission to change the whitelist.');
        $this->ensureCan($request, $server, 'startup.update', 'You need permission to change startup settings for this.');

        $enabled = (bool) $request->validate(['enabled' => ['required', 'boolean']])['enabled'];

        $args = (string) $this->variables->get($server, 'ARGS');
        $parts = array_values(array_filter(preg_split('/\s+/', trim($args)) ?: [], fn (string $part): bool => $part !== '' && strcasecmp($part, self::EXCLUSIVE) !== 0));
        if ($enabled) {
            $parts[] = self::EXCLUSIVE;
        }
        $this->variables->set($server, 'ARGS', implode(' ', $parts));

        return new JsonResponse($this->listsPayload($server));
    }

    /**
     * @return array<string, mixed>
     */
    private function listsPayload(Server $server): array
    {
        $args = $this->variables->get($server, 'ARGS');

        return [
            'state' => $this->serverState($this->state, $server),
            'whitelist_enabled' => $args === null ? null : preg_match('/(^|\s)'.preg_quote(self::EXCLUSIVE, '/').'(\s|$)/i', $args) === 1,
            'whitelist_editable' => $this->variables->editable($server, 'ARGS'),
            'whitelist' => $this->files->ids($server, 'whitelist'),
            'bans' => $this->files->ids($server, 'bans'),
            'admins' => $this->files->ids($server, 'admins'),
            'known' => $this->known->all($server),
        ];
    }

    private function authorizeView(Request $request, Server $server): void
    {
        $this->ensureArk($server);
        $this->ensureCan($request, $server, 'ext.ark-tools.view', 'You do not have permission to see players on this server.');
    }
}
