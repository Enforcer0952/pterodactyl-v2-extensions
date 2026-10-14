<?php

namespace CrashGuard\Services;

use Carbon\CarbonImmutable;
use CrashGuard\Support\CrashWindow;
use CrashGuard\Support\Limits;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Models\Server;

/**
 * A server's guard settings and state: what is on, what the limits are, whether the
 * guard is holding the server off, and the log of what it saw.
 */
class Guard
{
    public const string SERVERS = 'ext_crash_guard_servers';

    public const string EVENTS = 'ext_crash_guard_events';

    public const int LOG_SIZE = 20;

    /** @return array{enabled: bool, max_crashes: int, window_minutes: int} */
    public function defaults(): array
    {
        $settings = app('ext.crash-guard.settings');

        return [
            'enabled' => (bool) $settings->get('default_enabled', false),
            'max_crashes' => Limits::crashes($settings->get('default_max_crashes', Limits::DEFAULT_CRASHES)),
            'window_minutes' => Limits::minutes($settings->get('default_window_minutes', Limits::DEFAULT_MINUTES)),
        ];
    }

    public function row(Server $server): ?object
    {
        return DB::table(self::SERVERS)->where('server_id', $server->id)->first();
    }

    /**
     * @param  array{enabled: bool, max_crashes: int, window_minutes: int}|null  $defaults
     * @return array{enabled: bool, max_crashes: int, window_minutes: int, custom: bool}
     */
    public function effective(?object $row, ?array $defaults = null): array
    {
        $defaults ??= $this->defaults();

        return [
            'enabled' => $row === null || $row->enabled === null ? $defaults['enabled'] : (bool) $row->enabled,
            'max_crashes' => $row === null || $row->max_crashes === null ? $defaults['max_crashes'] : Limits::crashes($row->max_crashes),
            'window_minutes' => $row === null || $row->window_minutes === null ? $defaults['window_minutes'] : Limits::minutes($row->window_minutes),
            'custom' => $row !== null && ($row->enabled !== null || $row->max_crashes !== null || $row->window_minutes !== null),
        ];
    }

    public function save(Server $server, bool $enabled, int $max, int $minutes): void
    {
        $now = CarbonImmutable::now();
        $row = $this->row($server);
        $was = $this->effective($row)['enabled'];

        DB::transaction(function () use ($server, $enabled, $max, $minutes, $now, $row, $was): void {
            if ($row === null) {
                DB::table(self::SERVERS)->insertOrIgnore(['server_id' => $server->id, 'created_at' => $now, 'updated_at' => $now]);
            }

            $values = ['enabled' => $enabled, 'max_crashes' => $max, 'window_minutes' => $minutes, 'updated_at' => $now];
            // A guard that was off has no history to compare with: start from the next reading.
            if (! $was && $enabled) {
                $values += ['last_state' => null, 'last_uptime' => null, 'last_sampled_at' => null, 'cleared_at' => $now];
            }
            DB::table(self::SERVERS)->where('server_id', $server->id)->update($values);

            if (! $enabled) {
                $this->release($server->id, 'The guard was turned off.');
            }
        });
    }

    /** Reset the crash counter and let a tripped server go. */
    public function clear(Server $server, string $note = 'Cleared by a user.'): void
    {
        $now = CarbonImmutable::now();

        DB::transaction(function () use ($server, $now, $note): void {
            DB::table(self::SERVERS)->insertOrIgnore(['server_id' => $server->id, 'created_at' => $now, 'updated_at' => $now]);
            DB::table(self::SERVERS)->where('server_id', $server->id)->update([
                'cleared_at' => $now,
                'tripped_at' => null,
                'trip_crashes' => null,
                'trip_minutes' => null,
                'stop_sent_at' => null,
                'up_seen_at' => null,
                'last_state' => null,
                'last_uptime' => null,
                'last_sampled_at' => null,
                'updated_at' => $now,
            ]);
            // A crash still waiting for the activity log must not trip the guard again.
            DB::table(self::EVENTS)->where('server_id', $server->id)->where('status', 'pending')->update(['status' => 'dismissed', 'updated_at' => $now]);
            $this->event($server->id, 'cleared', null, null, $note, null, $now);
        });
    }

    /** End a trip without touching the counter's history; used when the guard is turned off or a user started the server. */
    public function release(int $serverId, string $note, string $kind = 'cleared'): void
    {
        $row = DB::table(self::SERVERS)->where('server_id', $serverId)->first();
        if ($row === null || $row->tripped_at === null) {
            return;
        }

        $now = CarbonImmutable::now();
        DB::table(self::SERVERS)->where('server_id', $serverId)->update([
            'cleared_at' => $now,
            'tripped_at' => null,
            'trip_crashes' => null,
            'trip_minutes' => null,
            'stop_sent_at' => null,
            'up_seen_at' => null,
            'updated_at' => $now,
        ]);
        $this->event($serverId, $kind, null, null, $note, null, $now);
    }

    public function event(int $serverId, string $kind, ?string $via, ?int $uptimeBeforeMs, ?string $note, ?CarbonImmutable $since, CarbonImmutable $at, string $status = 'confirmed'): int
    {
        return (int) DB::table(self::EVENTS)->insertGetId([
            'server_id' => $serverId,
            'kind' => $kind,
            'status' => $status,
            'via' => $via,
            'uptime_before' => $uptimeBeforeMs,
            'note' => $note === null ? null : mb_substr($note, 0, 191),
            'since' => $since,
            'occurred_at' => $at,
            'created_at' => CarbonImmutable::now(),
            'updated_at' => CarbonImmutable::now(),
        ]);
    }

    /**
     * Everything the page needs about one server.
     *
     * @return array<string, mixed>
     */
    public function status(Server $server): array
    {
        $row = $this->row($server);
        $effective = $this->effective($row);
        $defaults = $this->defaults();
        $now = CarbonImmutable::now();

        $tripped = $effective['enabled'] && $row?->tripped_at !== null;
        $state = ! $effective['enabled'] ? 'off' : ($tripped ? 'tripped' : 'watching');

        $times = [];
        if ($effective['enabled']) {
            $times = DB::table(self::EVENTS)
                ->where('server_id', $server->id)->where('kind', 'crash')->where('status', 'confirmed')
                ->where('occurred_at', '>=', $now->subMinutes($effective['window_minutes']))
                ->pluck('occurred_at')
                ->map(fn ($time) => CarbonImmutable::parse($time)->getTimestamp())
                ->all();
        }
        $clearedAt = $row?->cleared_at === null ? null : CarbonImmutable::parse($row->cleared_at)->getTimestamp();

        return [
            'state' => $state,
            'enabled' => $effective['enabled'],
            'max_crashes' => $effective['max_crashes'],
            'window_minutes' => $effective['window_minutes'],
            'defaults' => $defaults,
            'recent_crashes' => CrashWindow::count($times, $now->getTimestamp(), $effective['window_minutes'], $clearedAt),
            'tripped' => $tripped ? [
                'at' => CarbonImmutable::parse($row->tripped_at)->toAtomString(),
                'crashes' => (int) $row->trip_crashes,
                'minutes' => (int) $row->trip_minutes,
            ] : null,
            'events' => $this->events($server->id),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function events(int $serverId): array
    {
        return DB::table(self::EVENTS)
            ->where('server_id', $serverId)
            ->where('status', '!=', 'dismissed')
            ->orderByDesc('occurred_at')->orderByDesc('id')
            ->limit(self::LOG_SIZE)
            ->get()
            ->map(fn (object $event): array => [
                'id' => (int) $event->id,
                'kind' => $event->kind,
                'status' => $event->status,
                'via' => $event->via,
                'uptime_before' => $event->uptime_before === null ? null : (int) $event->uptime_before,
                'note' => $event->note,
                'occurred_at' => CarbonImmutable::parse($event->occurred_at)->toAtomString(),
            ])
            ->all();
    }
}
