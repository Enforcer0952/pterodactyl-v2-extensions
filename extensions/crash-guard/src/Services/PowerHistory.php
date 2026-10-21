<?php

namespace CrashGuard\Services;

use Carbon\CarbonImmutable;
use CrashGuard\Support\ScheduleTimes;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Models\Server;

/**
 * Who started, stopped or restarted a server: the power entries in the activity log
 * (users, API keys and what Wings reports for the console buttons) and the schedules
 * that have a power task. Wings sends its entries in batches, so they can arrive a
 * minute or so late.
 */
class PowerHistory
{
    private const array EVENTS = ['server:power.start', 'server:power.stop', 'server:power.restart', 'server:power.kill'];

    /** Other actions that stop or restart a server on purpose. */
    private const array OTHER_EVENTS = ['server:backup.restore', 'server:backup.restore-complete', 'server:backup.restore-failed', 'server:reinstall'];

    /** Console commands that stop or restart a game (with or without a leading slash). */
    private const string STOP_COMMANDS = '/^\/?(stop|restart|end|exit|quit|shutdown|save-and-stop|doexit)\b/i';

    /**
     * Times (unix seconds) of power actions that may explain a change between $from and $to.
     *
     * @return list<int>
     */
    public function actions(Server $server, int $from, int $to): array
    {
        $times = [];

        $stamps = $this->logs($server)
            ->whereIn('a.event', [...self::EVENTS, ...self::OTHER_EVENTS])
            ->whereBetween('a.timestamp', [self::at($from), self::at($to)])
            ->pluck('a.timestamp');
        foreach ($stamps as $stamp) {
            $times[] = CarbonImmutable::parse($stamp)->getTimestamp();
        }

        // A stop typed in the console (by a user or the API) is on purpose too.
        $commands = $this->logs($server)
            ->where('a.event', 'server:console.command')
            ->whereBetween('a.timestamp', [self::at($from), self::at($to)])
            ->get(['a.timestamp', 'a.properties']);
        foreach ($commands as $entry) {
            if (self::isStopCommand($entry->properties)) {
                $times[] = CarbonImmutable::parse($entry->timestamp)->getTimestamp();
            }
        }

        $rows = DB::table('tasks as t')
            ->join('schedules as s', 's.id', '=', 't.schedule_id')
            ->where('s.server_id', $server->id)
            ->where('t.action', 'power')
            ->get(['t.schedule_id', 't.sequence_id', 't.action', 't.payload', 't.time_offset', 's.is_processing', 's.last_run_at']);
        $schedules = [];
        foreach ($rows as $row) {
            $schedules[$row->schedule_id]['processing'] = (bool) $row->is_processing;
            $schedules[$row->schedule_id]['last_run_at'] = $row->last_run_at === null ? null : CarbonImmutable::parse($row->last_run_at)->getTimestamp();
            $schedules[$row->schedule_id]['tasks'][] = [
                'sequence_id' => (int) $row->sequence_id,
                'action' => (string) $row->action,
                'payload' => (string) $row->payload,
                'time_offset' => (int) $row->time_offset,
            ];
        }
        // Tasks that are not power tasks only matter for their offsets.
        if ($schedules !== []) {
            $others = DB::table('tasks')->whereIn('schedule_id', array_keys($schedules))->where('action', '!=', 'power')
                ->get(['schedule_id', 'sequence_id', 'action', 'payload', 'time_offset']);
            foreach ($others as $row) {
                $schedules[$row->schedule_id]['tasks'][] = [
                    'sequence_id' => (int) $row->sequence_id,
                    'action' => (string) $row->action,
                    'payload' => (string) $row->payload,
                    'time_offset' => (int) $row->time_offset,
                ];
            }
        }
        foreach ($schedules as $schedule) {
            array_push($times, ...ScheduleTimes::powerTimes($schedule['tasks'], $schedule['last_run_at'], $schedule['processing'], $to));
        }

        return $times;
    }

    /** Whether a person started or restarted the server since the given time (unix seconds). */
    public function userStarted(Server $server, int $since): bool
    {
        return $this->logs($server)
            ->whereIn('a.event', ['server:power.start', 'server:power.restart'])
            ->whereNotNull('a.actor_id')
            ->where('a.timestamp', '>=', self::at($since))
            ->exists();
    }

    /** Whether an activity entry's properties hold a console command that stops the game. */
    public static function isStopCommand(mixed $properties): bool
    {
        $data = is_string($properties) ? json_decode($properties, true) : (array) $properties;
        $command = is_array($data) ? ($data['command'] ?? null) : null;

        return is_string($command) && preg_match(self::STOP_COMMANDS, trim($command)) === 1;
    }

    private function logs(Server $server): \Illuminate\Database\Query\Builder
    {
        return DB::table('activity_logs as a')
            ->join('activity_log_subjects as s', 's.activity_log_id', '=', 'a.id')
            ->where('s.subject_type', $server->getMorphClass())
            ->where('s.subject_id', $server->id);
    }

    public static function at(int $timestamp): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestamp($timestamp, date_default_timezone_get());
    }
}
