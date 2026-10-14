<?php

namespace PalworldTools\Http\Controllers;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use PalworldTools\Http\Controllers\Concerns\PalworldServer;
use PalworldTools\Services\ServerFiles;
use PalworldTools\Support\BanList;
use PalworldTools\Support\Palworld;
use Pterodactyl\Contracts\Servers\ReadsServerState;
use Pterodactyl\Models\Server;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The game's local ban list, Pal/Saved/SaveGames/banlist.txt. Players can be banned
 * with the Players card while the server runs; removing entries from the file needs
 * the server stopped, because the game keeps the list in memory and writes it back.
 */
class BansController extends Controller
{
    use PalworldServer;

    private const int MAX_BYTES = 1024 * 1024;

    public function __construct(
        private readonly ServerFiles $files,
        private readonly ReadsServerState $state,
    ) {}

    public function show(Request $request, Server $server): JsonResponse
    {
        $this->authorizeBans($request, $server, ['file.read']);

        return new JsonResponse($this->payload($server));
    }

    public function remove(Request $request, Server $server): JsonResponse
    {
        $this->authorizeBans($request, $server, ['file.read', 'file.update']);
        $data = $request->validate(['user_id' => ['required', 'string', 'max:64', 'regex:'.Palworld::USER_ID]]);
        $this->ensureStopped($this->state, $server, 'Stop the server before removing a ban.');

        try {
            Cache::lock('ext:palworld-tools:bans:'.$server->uuid, 60)->block(5, function () use ($server, $data): void {
                [$directory, $name] = $this->location();
                $current = $this->files->read($server, $directory, $name, self::MAX_BYTES);
                if ($current === null || ! in_array($data['user_id'], BanList::ids($current), true)) {
                    throw new HttpException(404, 'That player is not on the ban list.');
                }
                $this->files->write($server, $directory, $name, BanList::remove($current, $data['user_id']));
            });
        } catch (LockTimeoutException) {
            throw new HttpException(409, 'The ban list is being changed. Try again in a moment.');
        }

        return new JsonResponse($this->payload($server));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Server $server): array
    {
        [$directory, $name] = $this->location();
        $text = $this->files->read($server, $directory, $name, self::MAX_BYTES);

        return [
            'state' => $this->serverState($this->state, $server),
            'exists' => $text !== null,
            'path' => Palworld::BAN_LIST,
            'ids' => $text === null ? [] : BanList::ids($text),
        ];
    }

    /** @return array{0: string, 1: string} */
    private function location(): array
    {
        return ['/'.dirname(Palworld::BAN_LIST), basename(Palworld::BAN_LIST)];
    }

    /**
     * @param  list<string>  $core
     */
    private function authorizeBans(Request $request, Server $server, array $core): void
    {
        $this->ensurePalworld($server);
        $this->ensureCan($request, $server, ['ext.palworld-tools.saves', ...$core], 'You do not have permission to manage the ban list.');
    }
}
