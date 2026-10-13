<?php

namespace ArkTools\Http\Controllers;

use ArkTools\Http\Controllers\Concerns\ArkServer;
use ArkTools\Services\StartupVariables;
use ArkTools\Services\SteamWorkshop;
use ArkTools\Support\Ark;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Pterodactyl\Contracts\Files\DeletesFiles;
use Pterodactyl\Contracts\Files\ListsDirectories;
use Pterodactyl\Contracts\Servers\ReadsServerState;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Models\Server;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The server's Steam Workshop mods: the MOD_ID startup variable (load order),
 * matched against what has been downloaded to ShooterGame/Content/Mods and the
 * latest version on the Workshop.
 */
class ModsController extends Controller
{
    use ArkServer;

    private const string VARIABLE = 'MOD_ID';

    private const int MAX_MODS = 60;

    public function __construct(
        private readonly StartupVariables $variables,
        private readonly SteamWorkshop $workshop,
        private readonly ListsDirectories $directories,
        private readonly DeletesFiles $deletes,
        private readonly ReadsServerState $state,
    ) {}

    public function index(Request $request, Server $server): JsonResponse
    {
        $this->ensureArk($server);
        $this->ensureCan($request, $server, 'startup.read', 'You do not have permission to see this server\'s startup settings.');

        return new JsonResponse($this->payload($request, $server));
    }

    /**
     * Replace the mod list (and with it, the load order).
     */
    public function update(Request $request, Server $server): JsonResponse
    {
        $this->ensureArk($server);
        $this->ensureCan($request, $server, 'startup.update', 'You do not have permission to change this server\'s startup settings.');

        $data = $request->validate([
            'ids' => ['present', 'array', 'max:'.self::MAX_MODS],
            'ids.*' => ['string', 'regex:'.Ark::MOD_ID],
        ]);

        $ids = array_values(array_unique($data['ids']));
        $this->variables->set($server, self::VARIABLE, implode(',', $ids));

        return new JsonResponse($this->payload($request, $server));
    }

    /**
     * Look up Workshop items before they are added, so typos show up straight away.
     */
    public function lookup(Request $request, Server $server): JsonResponse
    {
        $this->ensureArk($server);
        $this->ensureCan($request, $server, 'startup.read', 'You do not have permission to see this server\'s startup settings.');

        $data = $request->validate(['ids' => ['required', 'array', 'max:20'], 'ids.*' => ['string', 'regex:'.Ark::MOD_ID]]);

        return new JsonResponse(['items' => array_values($this->workshop->details(array_values(array_unique($data['ids']))))]);
    }

    /**
     * Delete a mod's downloaded files. Only for mods that are not in the list, so a
     * running server never loses files it has loaded.
     */
    public function deleteFiles(Request $request, Server $server, string $mod): JsonResponse
    {
        $this->ensureArk($server);
        $this->ensureCan($request, $server, 'file.delete', 'You do not have permission to delete files on this server.');
        abort_unless(preg_match(Ark::MOD_ID, $mod) === 1, 404);

        if (in_array($mod, Ark::modIds($this->variables->get($server, self::VARIABLE)), true)) {
            throw new HttpException(409, 'Remove the mod from the list before deleting its files.');
        }

        $present = array_column($this->listMods($server), 'name');
        $files = array_values(array_intersect([$mod, $mod.'.mod'], $present));
        abort_if($files === [], 404, 'Those files are already gone.');

        $this->deletes->delete($server, '/'.Ark::MODS_DIR, $files);

        return new JsonResponse($this->payload($request, $server));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request, Server $server): array
    {
        $value = $this->variables->get($server, self::VARIABLE);
        $ids = Ark::modIds($value);

        // What has been downloaded: <id>.mod written when the download finishes, <id>/ the content.
        $downloaded = [];
        foreach ($this->listMods($server) as $entry) {
            $name = (string) ($entry['name'] ?? '');
            if (preg_match('/^(\d{5,12})(\.mod)?$/', $name, $match) !== 1) {
                continue;
            }
            $id = $match[1];
            $downloaded[$id] ??= ['installed_at' => null, 'files' => true];
            if (isset($match[2]) && is_string($entry['modified'] ?? null)) {
                $downloaded[$id]['installed_at'] = CarbonImmutable::parse($entry['modified'])->getTimestamp();
            }
        }

        // 111111111 is ARK's own Primitive+ package, shipped with the server.
        unset($downloaded['111111111']);

        $extra = array_values(array_diff(array_keys($downloaded), $ids));
        $details = $this->workshop->details([...$ids, ...$extra]);

        $describe = function (string $id, bool $listed) use ($details, $downloaded): array {
            $item = $details[$id];
            $installedAt = $downloaded[$id]['installed_at'] ?? null;
            $status = match (true) {
                ! isset($downloaded[$id]) => 'not_downloaded',
                $installedAt === null => 'downloading',
                $item['updated_at'] !== null && $item['updated_at'] > $installedAt => 'update_available',
                default => 'up_to_date',
            };

            return [
                ...$item,
                'listed' => $listed,
                'downloaded' => isset($downloaded[$id]),
                'installed_at' => $installedAt,
                'status' => $status,
                'wrong_game' => $item['app_id'] !== null && ! in_array($item['app_id'], [346110, 0], true),
            ];
        };

        return [
            'state' => $this->serverState($this->state, $server),
            'raw' => $value,
            'editable' => $this->variables->editable($server, self::VARIABLE),
            'can_delete_files' => $request->user()->can('file.delete', $server),
            'mods' => array_map(fn (string $id): array => $describe($id, true), $ids),
            'unlisted' => array_map(fn (string $id): array => $describe($id, false), $extra),
            'max' => self::MAX_MODS,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listMods(Server $server): array
    {
        try {
            return $this->directories->list($server, '/'.Ark::MODS_DIR);
        } catch (DaemonConnectionException) {
            // Missing folder (no mods downloaded yet); some Wings versions answer with a 500.
            return [];
        }
    }
}
