<?php

namespace CrashGuard\Services;

use Carbon\CarbonImmutable;
use CrashGuard\Support\Candidate;
use CrashGuard\Support\CrashDetector;
use CrashGuard\Support\CrashWindow;
use CrashGuard\Support\Enforcement;
use CrashGuard\Support\Sample;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Contracts\Servers\ReadsServerState;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Facades\Daemon;
use Pterodactyl\Models\Server;
use Throwable;

/**
 * The once-a-minute check. For every guarded server it reads the power state, compares
 * it with the reading from the minute before and records a crash when the server went
 * down or restarted with nobody asking. A crash only counts after the activity log has had
 * time to show whether a person or a schedule caused it. Too many counted crashes in
 * the window trip the guard: the server is stopped and kept off.
 */
class Watcher
{
    /** Seconds after which a tick stops starting new servers, so it never runs into the next minute. */
    private const int BUDGET_SECONDS = 45;

    /** A server running this long (ms) when the limit is reached is not crash looping. */
    private const int HEALTHY_UPTIME_MS = 120_000;

    /** Seconds after a node was last unreachable during which its servers' restarts are not counted. */
    private const int NODE_QUIET_SECONDS = 240;

    public function __construct(
        private readonly Guard $guard,
        private readonly ReadsServerState $state,
        private readonly PowerHistory $history,
    ) {}

    public function tick(): void
    {
        // A tick that is still running (slow nodes) is never joined by a second one.
        $lock = Cache::lock('ext-crash-guard:tick', 120);
        if (! $lock->get()) {
            return;
        }

        try {
            $this->run();
        } finally {
            $lock->release();
        }
    }

    private function run(): void
    {
        $started = microtime(true);

        foreach (['prune', 'settle', 'releaseUnguarded'] as $phase) {
            try {
                $this->{$phase}();
            } catch (Throwable $throwable) {
                report($throwable); // one failing phase must not stop crash detection
            }
        }

        $defaults = $this->guard->defaults();
        $down = [];
        $sampled = [];
        $fresh = [];

        $this->guarded($defaults)->chunkById(100, function ($servers) use ($started, $defaults, &$down, &$sampled, &$fresh): bool {
            $rows = DB::table(Guard::SERVERS)->whereIn('server_id', $servers->pluck('id'))->get()->keyBy('server_id');

            foreach ($servers as $server) {
                if (microtime(true) - $started > self::BUDGET_SECONDS) {
                    return false;
                }

                try {
                    $this->inspect($server, $rows->get($server->id), $defaults, $down, $sampled, $fresh);
                } catch (Throwable $throwable) {
                    // One broken server must not stop the others.
                    report($throwable);
                }
            }

            return true;
        });

        // Many servers on one node going down together is the node, not the games.
        foreach ($fresh as $node => $ids) {
            if (CrashDetector::nodeEvent(count($ids), $sampled[$node] ?? 0)) {
                DB::table(Guard::EVENTS)->whereIn('id', $ids)->update(['status' => 'dismissed', 'note' => 'Its node restarted.', 'updated_at' => CarbonImmutable::now()]);
            }
        }
    }

    /** @param  array{enabled: bool, max_crashes: int, window_minutes: int}  $defaults */
    private function guarded(array $defaults): \Illuminate\Database\Eloquent\Builder
    {
        $query = Server::query()->whereNull('status')->with('node');

        if ($defaults['enabled']) {
            return $query->whereNotIn('id', DB::table(Guard::SERVERS)->where('enabled', false)->select('server_id'));
        }

        return $query->whereIn('id', DB::table(Guard::SERVERS)->where('enabled', true)->select('server_id'));
    }

    /**
     * @param  array{enabled: bool, max_crashes: int, window_minutes: int}  $defaults
     * @param  array<int, true>  $down
     * @param  array<int, int>  $sampled
     * @param  array<int, list<int>>  $fresh
     */
    private function inspect(Server $server, ?object $row, array $defaults, array &$down, array &$sampled, array &$fresh): void
    {
        $node = (int) $server->node_id;
        if (isset($down[$node])) {
            return;
        }

        try {
            Cache::forget('resources:'.$server->uuid); // the panel caches state for 20 s
            $reading = $this->state->read($server);
        } catch (DaemonConnectionException $exception) {
            if (self::unreachable($exception)) {
                // The node cannot be reached: no reading, so no crash. Skip its other servers too,
                // and do not count crashes on it until it has been back for a few minutes.
                $down[$node] = true;
                Cache::put('ext-crash-guard:node-down:'.$node, time(), 900);
            }

            // Any other Wings error is about this one server: skip it, keep checking the rest.
            return;
        }

        $now = CarbonImmutable::now();
        if ($reading->isSuspended || ! Sample::valid($reading->state)) {
            $this->remember($server, $row, null, null, 0, $now);

            return;
        }

        $sample = new Sample($reading->state, max(0, $reading->uptime), $now->getTimestamp());

        if ($row?->tripped_at !== null) {
            $this->enforce($server, $row, $sample);
            $this->remember($server, $row, $sample->state, $sample->uptimeMs, $sample->at, $now);

            return;
        }

        $previous = null;
        if ($row !== null && $row->last_state !== null && $row->last_sampled_at !== null) {
            $previous = new Sample((string) $row->last_state, (int) $row->last_uptime, CarbonImmutable::parse($row->last_sampled_at)->getTimestamp());
        }

        // Only servers that were up can look crashed, so only they count towards a node restart.
        if ($previous !== null && in_array($previous->state, ['running', 'starting'], true)) {
            $sampled[$node] = ($sampled[$node] ?? 0) + 1;
        }
        $candidate = CrashDetector::observe($previous, $sample);
        $nodeBackAt = Cache::get('ext-crash-guard:node-down:'.$node);
        $recentlyDown = is_int($nodeBackAt) && time() - $nodeBackAt < self::NODE_QUIET_SECONDS;
        if ($candidate instanceof Candidate && ! $recentlyDown) {
            $fresh[$node][] = $this->guard->event(
                $server->id, 'crash', $candidate->via, $candidate->uptimeBeforeMs, null,
                PowerHistory::at($candidate->since), PowerHistory::at($candidate->at), 'pending',
            );
        }

        $this->remember($server, $row, $sample->state, $sample->uptimeMs, $sample->at, $now);
    }

    private function remember(Server $server, ?object $row, ?string $state, ?int $uptimeMs, int $at, CarbonImmutable $now): void
    {
        if ($row === null) {
            DB::table(Guard::SERVERS)->insertOrIgnore(['server_id' => $server->id, 'created_at' => $now, 'updated_at' => $now]);
        }

        DB::table(Guard::SERVERS)->where('server_id', $server->id)->update([
            'last_state' => $state,
            'last_uptime' => $uptimeMs,
            'last_sampled_at' => $state === null ? null : PowerHistory::at($at),
            'updated_at' => $now,
        ]);
    }

    /** Crashes that have waited long enough: count them, or drop them when someone caused them. */
    private function settle(): void
    {
        $now = CarbonImmutable::now();
        $pending = DB::table(Guard::EVENTS)
            ->where('status', 'pending')
            ->where('occurred_at', '<=', PowerHistory::at($now->getTimestamp() - CrashDetector::SETTLE_SECONDS))
            ->orderBy('occurred_at')
            ->get()
            ->groupBy('server_id');

        foreach ($pending as $serverId => $events) {
            try {
                $server = Server::query()->find($serverId);
                if (! $server instanceof Server) {
                    DB::table(Guard::EVENTS)->whereIn('id', $events->pluck('id'))->update(['status' => 'dismissed']);

                    continue;
                }

                $this->settleServer($server, $events->all(), $now);
            } catch (Throwable $throwable) {
                report($throwable);
            }
        }
    }

    /** @param  list<object>  $events */
    private function settleServer(Server $server, array $events, CarbonImmutable $now): void
    {
        $candidates = [];
        foreach ($events as $event) {
            $at = CarbonImmutable::parse($event->occurred_at)->getTimestamp();
            $candidates[$event->id] = new Candidate(
                (string) $event->via, $at,
                $event->since === null ? $at - 60 : CarbonImmutable::parse($event->since)->getTimestamp(),
                $event->uptime_before === null ? null : (int) $event->uptime_before,
            );
        }

        $from = min(array_map(fn (Candidate $c) => $c->since, $candidates)) - CrashDetector::GRACE_BEFORE;
        $to = max(array_map(fn (Candidate $c) => $c->at, $candidates)) + CrashDetector::GRACE_AFTER;
        $actions = $this->history->actions($server, $from, $to);

        $counted = false;
        foreach ($candidates as $id => $candidate) {
            $explained = CrashDetector::explained($candidate, $actions);
            DB::table(Guard::EVENTS)->where('id', $id)->where('status', 'pending')->update([
                'status' => $explained ? 'dismissed' : 'confirmed',
                'note' => $explained ? 'A user or schedule did this.' : null,
                'updated_at' => $now,
            ]);
            $counted = $counted || ! $explained;
        }

        if ($counted) {
            $this->evaluate($server, $now);
        }
    }

    /** Trip the guard when the counted crashes reach the limit. */
    private function evaluate(Server $server, CarbonImmutable $now): void
    {
        $row = $this->guard->row($server);
        $config = $this->guard->effective($row);
        if (! $config['enabled'] || $row?->tripped_at !== null) {
            return;
        }

        $times = DB::table(Guard::EVENTS)
            ->where('server_id', $server->id)->where('kind', 'crash')->where('status', 'confirmed')
            ->where('occurred_at', '>=', $now->subMinutes($config['window_minutes']))
            ->pluck('occurred_at')
            ->map(fn ($time) => CarbonImmutable::parse($time)->getTimestamp())
            ->all();
        $clearedAt = $row?->cleared_at === null ? null : CarbonImmutable::parse($row->cleared_at)->getTimestamp();

        if (! CrashWindow::tripped($times, $now->getTimestamp(), $config['max_crashes'], $config['window_minutes'], $clearedAt)) {
            return;
        }

        // Crashes are only confirmed a couple of minutes after they happen. If someone
        // started the server since, or it has been running fine for a while, it is not
        // looping any more: never stop a healthy server.
        $lastCrash = max($times);
        $healthy = $this->history->userStarted($server, $lastCrash);
        if (! $healthy) {
            try {
                Cache::forget('resources:'.$server->uuid);
                $reading = $this->state->read($server);
                $healthy = $reading->state === 'running' && $reading->uptime > self::HEALTHY_UPTIME_MS;
            } catch (DaemonConnectionException) {
                $healthy = true; // Cannot tell: do nothing now, the next crash re-checks.
            }
        }
        if ($healthy) {
            DB::table(Guard::EVENTS)
                ->where('server_id', $server->id)->where('kind', 'crash')->where('status', 'confirmed')
                ->where('occurred_at', '>=', $now->subMinutes($config['window_minutes']))
                ->update(['status' => 'dismissed', 'note' => 'It was running normally again when the limit was reached.', 'updated_at' => $now]);

            return;
        }

        $this->trip($server, CrashWindow::count($times, $now->getTimestamp(), $config['window_minutes'], $clearedAt), $config['window_minutes'], $now);
    }

    private function trip(Server $server, int $crashes, int $minutes, CarbonImmutable $now): void
    {
        $sent = false;
        try {
            Daemon::server($server)->power('stop');
            $sent = true;
        } catch (DaemonConnectionException) {
            // The node is down. The next tick stops the server as soon as it can be reached.
        }

        DB::table(Guard::SERVERS)->where('server_id', $server->id)->update([
            'tripped_at' => $now,
            'trip_crashes' => $crashes,
            'trip_minutes' => $minutes,
            'stop_sent_at' => $sent ? $now : null,
            // Without a stop on its way, the server is stopped on the next tick.
            'up_seen_at' => $sent ? null : $now->subSeconds(Enforcement::WATCH_SECONDS),
            'updated_at' => $now,
        ]);
        $this->guard->event($server->id, 'trip', null, null, $crashes.' crashes in '.$minutes.' minutes.', null, $now);

        $this->log($server, 'server:crash-guard.trip', ['crashes' => $crashes, 'minutes' => $minutes]);
    }

    private function enforce(Server $server, object $row, Sample $sample): void
    {
        $now = CarbonImmutable::now();

        if ($sample->state === 'offline') {
            if ($row->stop_sent_at !== null || $row->up_seen_at !== null) {
                DB::table(Guard::SERVERS)->where('server_id', $server->id)->update(['stop_sent_at' => null, 'up_seen_at' => null, 'updated_at' => $now]);
            }

            return;
        }

        $trippedAt = CarbonImmutable::parse($row->tripped_at)->getTimestamp();
        $action = Enforcement::decide(
            $sample->state,
            $row->stop_sent_at === null ? null : CarbonImmutable::parse($row->stop_sent_at)->getTimestamp(),
            $row->up_seen_at === null ? null : CarbonImmutable::parse($row->up_seen_at)->getTimestamp(),
            $sample->at,
            $this->history->userStarted($server, $trippedAt),
        );

        switch ($action) {
            case Enforcement::WATCH:
                DB::table(Guard::SERVERS)->where('server_id', $server->id)->update(['up_seen_at' => $now, 'updated_at' => $now]);
                break;
            case Enforcement::STOP:
            case Enforcement::KILL:
                $signal = $action === Enforcement::STOP ? 'stop' : 'kill';
                try {
                    Daemon::server($server)->power($signal);
                } catch (DaemonConnectionException) {
                    return; // Try again next tick.
                }
                DB::table(Guard::SERVERS)->where('server_id', $server->id)->update(['stop_sent_at' => $now, 'updated_at' => $now]);
                $this->guard->event(
                    $server->id, 'blocked', null, null,
                    $signal === 'stop' ? 'It came back up on its own and was stopped again.' : 'It did not stop in time and was killed.',
                    null, $now,
                );
                $this->log($server, 'server:crash-guard.'.$signal, []);
                break;
            case Enforcement::RELEASE:
                $this->guard->release($server->id, 'Started by a user. The guard is watching again.', 'release');
                break;
        }
    }

    /** A tripped server whose guard has since been turned off (or the default changed) is let go. */
    private function releaseUnguarded(): void
    {
        $tripped = DB::table(Guard::SERVERS)->whereNotNull('tripped_at')->get();
        if ($tripped->isEmpty()) {
            return;
        }

        $defaults = $this->guard->defaults();
        foreach ($tripped as $row) {
            if (! $this->guard->effective($row, $defaults)['enabled']) {
                $this->guard->release((int) $row->server_id, 'The guard was turned off.');
            }
        }
    }

    private function prune(): void
    {
        // Once an hour is plenty.
        if (! Cache::add('ext-crash-guard:pruned', true, 3600)) {
            return;
        }

        $days = max(1, min(365, (int) app('ext.crash-guard.settings')->get('keep_days', 30)));
        $now = CarbonImmutable::now();

        DB::table(Guard::EVENTS)->where('occurred_at', '<', $now->subDays($days))->delete();
        DB::table(Guard::EVENTS)->whereIn('status', ['dismissed', 'pending'])->where('occurred_at', '<', $now->subDay())->delete();
    }

    /** Whether Wings itself could not be reached (as opposed to an error about one server). */
    private static function unreachable(DaemonConnectionException $exception): bool
    {
        for ($previous = $exception->getPrevious(); $previous !== null; $previous = $previous->getPrevious()) {
            if ($previous instanceof \Illuminate\Http\Client\ConnectionException || $previous instanceof \GuzzleHttp\Exception\ConnectException) {
                return true;
            }
            if ($previous instanceof \Illuminate\Http\Client\RequestException) {
                return false; // Wings answered with an error about this server.
            }
            if ($previous instanceof \GuzzleHttp\Exception\RequestException) {
                return $previous->getResponse() === null;
            }
        }

        // No cause attached: assume the node is unreachable (safe: nothing is counted).
        return true;
    }

    /** @param  array<string, mixed>  $properties */
    private function log(Server $server, string $event, array $properties): void
    {
        try {
            Activity::event($event)->subject($server)->property($properties)->log();
        } catch (Throwable $throwable) {
            report($throwable);
        }
    }
}
