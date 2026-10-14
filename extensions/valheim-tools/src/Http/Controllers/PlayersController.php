<?php

namespace ValheimTools\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Models\Server;
use Symfony\Component\HttpKernel\Exception\HttpException;
use ValheimTools\Http\Controllers\Concerns\ValheimServer;
use ValheimTools\Services\AccessLists;
use ValheimTools\Services\KnownPlayers;
use ValheimTools\Services\Preferences;
use ValheimTools\Services\PlayerTracker;
use ValheimTools\Support\PlayerIds;

/**
 * The Players card: who is online (read from the console), recently seen players, and
 * one-click ban, admin and permit, which edit the list files.
 */
class PlayersController extends Controller
{
    use ValheimServer;

    private const array ACTIONS = ['ban' => 'banned', 'admin' => 'admin', 'permit' => 'permitted'];

    public function __construct(
        private readonly PlayerTracker $tracker,
        private readonly KnownPlayers $known,
        private readonly AccessLists $lists,
        private readonly Preferences $preferences,
    ) {}

    public function index(Request $request, Server $server): JsonResponse
    {
        $this->ensureValheim($server);
        $this->ensureCan($request, $server, 'ext.valheim-tools.view', 'You do not have permission to see players on this server.');

        try {
            $online = $this->tracker->online($server);
        } catch (DaemonConnectionException) {
            throw new HttpException(502, 'Could not reach this server\'s node.');
        }

        $style = $this->preferences->idStyle();
        $onlineKeys = [];
        $players = [];
        foreach ($online['players'] as $player) {
            if ($player['id'] !== null) {
                $onlineKeys[PlayerIds::key($player['id'])] = true;
            }
            $players[] = $player + ['list_id' => $player['steam_id'] !== null ? PlayerIds::format($player['steam_id'], $style) : null];
        }

        $recent = [];
        foreach ($this->known->recent($server, 30) as $seen) {
            if ($seen['id'] !== null && isset($onlineKeys[PlayerIds::key($seen['id'])])) {
                continue;
            }
            $steam = $seen['id'] !== null ? PlayerIds::steamId($seen['id']) : null;
            $recent[] = $seen + ['steam_id' => $steam, 'list_id' => $steam !== null ? PlayerIds::format($steam, $style) : null];
            if (count($recent) >= 15) {
                break;
            }
        }

        return new JsonResponse([
            'state' => $online['state'],
            'players' => $players,
            'recent' => $recent,
            'can_act' => $request->user()->can('ext.valheim-tools.players', $server)
                && $request->user()->can('file.update', $server)
                && $request->user()->can('file.read-content', $server),
        ]);
    }

    public function action(Request $request, Server $server): JsonResponse
    {
        $this->ensureValheim($server);

        $data = $request->validate([
            'action' => ['required', 'string', 'in:ban,admin,permit'],
            'id' => ['required', 'string', 'max:100'],
            'confirm' => ['sometimes', 'boolean'],
        ]);

        $this->ensureCan($request, $server, ['ext.valheim-tools.players', 'file.read', 'file.read-content', 'file.update']);

        $id = PlayerIds::fromInput($data['id'], $this->preferences->idStyle());
        if ($id === null || PlayerIds::steamId($id) === null) {
            throw new HttpException(422, 'Only Steam players can be changed here. Use the Access lists page for other IDs.');
        }

        $added = $this->lists->add($server, self::ACTIONS[$data['action']], $id, (bool) ($data['confirm'] ?? false));

        return new JsonResponse(['action' => $data['action'], 'id' => $id, 'added' => $added]);
    }
}
