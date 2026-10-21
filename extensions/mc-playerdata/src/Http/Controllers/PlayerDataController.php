<?php

namespace McPlayerData\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use McPlayerData\Services\PlayerFiles;
use McPlayerData\Support\MinecraftServer;
use McPlayerData\Support\PlayerData;
use McPlayerData\Support\PlayerEditException;
use McPlayerData\Support\PlayerEdits;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Models\Server;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PlayerDataController extends Controller
{
    /** Edit actions and what each one needs besides the player and the file hash. */
    private const array ACTIONS = ['remove-item', 'clear-inventory', 'clear-ender', 'clear-effects', 'game-mode', 'teleport', 'teleport-spawn'];

    public function __construct(private readonly PlayerFiles $files) {}

    /** Players who have a saved file, for the picker. */
    public function players(Request $request, Server $server): JsonResponse
    {
        $this->authorizeRead($request, $server);

        try {
            return new JsonResponse(['players' => $this->files->available($server)]);
        } catch (DaemonConnectionException) {
            throw new HttpException(502, 'Could not reach this server\'s node.');
        }
    }

    public function show(Request $request, Server $server): JsonResponse
    {
        $this->authorizeRead($request, $server);
        $player = (string) $request->validate(['player' => ['required', 'string', 'max:64']])['player'];

        try {
            return new JsonResponse($this->view($request, $server, $player));
        } catch (DaemonConnectionException) {
            throw new HttpException(502, 'Could not reach this server\'s node.');
        }
    }

    public function edit(Request $request, Server $server): JsonResponse
    {
        $this->authorizeRead($request, $server);
        abort_unless($request->user()->can('ext.mc-playerdata.manage', $server), 403, 'You do not have permission to edit player data on this server.');

        $data = $request->validate([
            'player' => ['required', 'string', 'max:64'],
            'hash' => ['required', 'string', 'size:40'],
            'action' => ['required', 'string', 'in:'.implode(',', self::ACTIONS)],
            'area' => ['nullable', 'string', 'in:inventory,ender,equipment'],
            'slot' => ['nullable', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_int($value) && ! is_string($value)) {
                    $fail('Unknown slot.');
                }
            }],
            'amount' => ['nullable', 'integer', 'min:1', 'max:999'],
            'mode' => ['nullable', 'integer', 'between:0,3'],
            'x' => ['nullable', 'numeric', 'between:-30000000,30000000'],
            'y' => ['nullable', 'numeric', 'between:'.PlayerEdits::MIN_Y.','.PlayerEdits::MAX_Y],
            'z' => ['nullable', 'numeric', 'between:-30000000,30000000'],
            'dimension' => ['nullable', 'string', 'max:128'],
        ]);

        $action = $data['action'];
        if ($action === 'remove-item' && (! isset($data['area']) || ! isset($data['slot']))) {
            throw new HttpException(422, 'Choose a slot.');
        }
        if ($action === 'game-mode' && ! isset($data['mode'])) {
            throw new HttpException(422, 'Choose a game mode.');
        }
        if ($action === 'teleport' && (! isset($data['x'], $data['y'], $data['z']) || ! isset($data['dimension']))) {
            throw new HttpException(422, 'Enter X, Y, Z and a dimension.');
        }

        try {
            $result = $this->files->edit($server, $data['player'], $data['hash'], function (array $loaded) use ($server, $action, $data): void {
                $root = $loaded['document']->root;

                try {
                    match ($action) {
                        'remove-item' => PlayerEdits::removeItem(
                            $root,
                            $data['area'],
                            $data['area'] === 'equipment' ? (string) $data['slot'] : $this->intSlot($data['slot']),
                            isset($data['amount']) ? (int) $data['amount'] : null,
                        ),
                        'clear-inventory' => PlayerEdits::clearInventory($root),
                        'clear-ender' => PlayerEdits::clearEnderChest($root),
                        'clear-effects' => PlayerEdits::clearEffects($root),
                        'game-mode' => PlayerEdits::setGameMode($root, (int) $data['mode']),
                        'teleport' => PlayerEdits::teleport($root, (float) $data['x'], (float) $data['y'], (float) $data['z'], (string) $data['dimension']),
                        'teleport-spawn' => $this->toSpawn($server, $loaded),
                    };
                } catch (PlayerEditException $exception) {
                    throw new HttpException(422, $exception->getMessage());
                }
            }, $action);
        } catch (DaemonConnectionException) {
            throw new HttpException(502, 'Could not reach this server\'s node.');
        }

        return new JsonResponse([
            ...$this->view($request, $server, $data['player']),
            'changed' => $result['changed'],
            'backup' => $result['backup'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $loaded
     */
    private function toSpawn(Server $server, array $loaded): void
    {
        $spawn = $this->files->worldSpawn($server, $loaded['world']);
        if ($spawn === null) {
            throw new PlayerEditException('Could not read the world spawn from level.dat.');
        }

        PlayerEdits::teleport($loaded['document']->root, $spawn['x'], $spawn['y'], $spawn['z'], $spawn['dimension']);
    }

    private function intSlot(mixed $slot): int
    {
        if (is_int($slot) || (is_string($slot) && preg_match('/^-?\d{1,4}$/D', $slot) === 1)) {
            return (int) $slot;
        }

        throw new HttpException(422, 'Unknown slot.');
    }

    /**
     * @return array<string, mixed>
     */
    private function view(Request $request, Server $server, string $player): array
    {
        $loaded = $this->files->load($server, $player);
        $state = $this->files->serverState($server);
        $manage = $request->user()->can('ext.mc-playerdata.manage', $server);

        return [
            'state' => $state,
            'player' => ['uuid' => $loaded['uuid'], 'name' => $loaded['name']],
            'hash' => $loaded['hash'],
            'saved' => $loaded['modified'],
            'size' => $loaded['size'],
            'world' => $loaded['world'],
            'folder' => ltrim($loaded['directory'], '/'),
            'backups' => $loaded['backups'],
            'can_manage' => $manage,
            'can_edit' => $manage && $state === 'offline',
            // Why the screen is read-only, when it is.
            'read_only' => match (true) {
                ! $manage => 'You can view this data but not change it.',
                $state === 'offline' => null,
                $state === 'running' => 'The server is running, so this is read-only. It keeps online players in memory and saves them again when they leave, which would undo an edit, and what you see is the last saved copy. Stop the server to edit.',
                default => 'The server is '.$state.', so this is read-only. Edits are possible once it has stopped.',
            },
            'data' => PlayerData::summarize($loaded['document']->root),
        ];
    }

    private function authorizeRead(Request $request, Server $server): void
    {
        abort_unless(MinecraftServer::isJava($server), 404);
        abort_unless($request->user()->can('ext.mc-playerdata.read', $server), 403, 'You do not have permission to view player data on this server.');
    }
}
