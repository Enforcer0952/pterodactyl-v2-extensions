<?php

namespace ValheimTools\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Models\Server;
use ValheimTools\Support\PlayerIds;

/**
 * Players seen on a server: when they first and last joined. Valheim's lists hold only
 * IDs, so this is also where the names next to the IDs come from.
 */
class KnownPlayers
{
    private const string TABLE = 'ext_valheim_tools_players';

    /**
     * A joined player. Players without an ID the lists take are kept under their name.
     *
     * @param  list<array{name: string, id: ?string}>  $players
     */
    public function seen(Server $server, array $players): void
    {
        $now = CarbonImmutable::now();
        foreach ($players as $player) {
            $key = $this->key($player['id'], $player['name']);
            $row = DB::table(self::TABLE)->where('server_id', $server->id)->where('player_id', $key)->first();
            if ($row === null) {
                DB::table(self::TABLE)->insert([
                    'server_id' => $server->id,
                    'player_id' => $key,
                    'name' => mb_substr($player['name'], 0, 128),
                    'first_seen_at' => $now,
                    'last_seen_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                DB::table(self::TABLE)->where('id', $row->id)->update([
                    'name' => mb_substr($player['name'], 0, 128),
                    'last_seen_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    /**
     * Most recently seen first.
     *
     * @return list<array{id: ?string, name: string, first_seen: string, last_seen: string}>
     */
    public function recent(Server $server, int $limit = 50): array
    {
        return DB::table(self::TABLE)
            ->where('server_id', $server->id)
            ->orderByDesc('last_seen_at')
            ->limit($limit)
            ->get(['player_id', 'name', 'first_seen_at', 'last_seen_at'])
            ->map(fn (object $row): array => [
                'id' => str_starts_with((string) $row->player_id, '~') ? null : (string) $row->player_id,
                'name' => (string) $row->name,
                'first_seen' => CarbonImmutable::parse($row->first_seen_at)->toAtomString(),
                'last_seen' => CarbonImmutable::parse($row->last_seen_at)->toAtomString(),
            ])
            ->all();
    }

    /**
     * Name and last seen time by player key, for the names next to list entries.
     *
     * @return array<string, array{name: string, last_seen: string}>
     */
    public function names(Server $server): array
    {
        $names = [];
        foreach (DB::table(self::TABLE)->where('server_id', $server->id)->where('player_id', 'not like', '~%')->get(['player_id', 'name', 'last_seen_at']) as $row) {
            $names[(string) $row->player_id] = ['name' => (string) $row->name, 'last_seen' => CarbonImmutable::parse($row->last_seen_at)->toAtomString()];
        }

        return $names;
    }

    /** The key a player is stored under: the Steam ID or platform ID, else the name. */
    private function key(?string $id, string $name): string
    {
        return $id !== null && $id !== '' ? mb_substr(PlayerIds::key($id), 0, 64) : '~'.mb_substr($name, 0, 60);
    }
}
