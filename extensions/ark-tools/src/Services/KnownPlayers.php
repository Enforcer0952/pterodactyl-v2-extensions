<?php

namespace ArkTools\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Models\Server;

/**
 * Names of players seen on a server, keyed by Steam id. ARK lists only Steam ids
 * in its ban list and whitelist, so this is how those get readable names.
 */
class KnownPlayers
{
    private const string TABLE = 'ext_ark_tools_players';

    /**
     * @param  list<array{name: string, steam_id: string}>  $players
     */
    public function remember(Server $server, array $players): void
    {
        if ($players === []) {
            return;
        }

        $now = CarbonImmutable::now();
        DB::table(self::TABLE)->upsert(
            array_map(fn (array $player): array => [
                'server_id' => $server->id,
                'steam_id' => $player['steam_id'],
                'name' => mb_substr($player['name'] === '' ? $player['steam_id'] : $player['name'], 0, 128),
                'last_seen_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ], $players),
            ['server_id', 'steam_id'],
            ['name', 'last_seen_at', 'updated_at'],
        );
    }

    /**
     * Most recently seen first.
     *
     * @return list<array{steam_id: string, name: string, last_seen: string}>
     */
    public function all(Server $server, int $limit = 500): array
    {
        return DB::table(self::TABLE)
            ->where('server_id', $server->id)
            ->orderByDesc('last_seen_at')
            ->limit($limit)
            ->get(['steam_id', 'name', 'last_seen_at'])
            ->map(fn (object $row): array => [
                'steam_id' => (string) $row->steam_id,
                'name' => (string) $row->name,
                'last_seen' => CarbonImmutable::parse($row->last_seen_at)->toAtomString(),
            ])
            ->all();
    }
}
