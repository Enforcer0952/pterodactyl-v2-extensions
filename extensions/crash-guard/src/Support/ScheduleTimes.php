<?php

namespace CrashGuard\Support;

/**
 * When a schedule's power tasks ran. The panel records no activity for them, only
 * when the schedule last finished, and every task waits its own offset before it runs.
 * So a power task ran at: finish time, minus the offsets of the tasks after it.
 */
final class ScheduleTimes
{
    public const array POWER = ['start', 'stop', 'restart', 'kill'];

    /**
     * @param  list<array{sequence_id: int, action: string, payload: string, time_offset: int}>  $tasks  one schedule
     * @param  ?int  $lastRunAt  when the schedule last finished (unix seconds)
     * @param  bool  $processing  the schedule is running right now
     * @param  int  $now  unix seconds
     * @return list<int>
     */
    public static function powerTimes(array $tasks, ?int $lastRunAt, bool $processing, int $now): array
    {
        usort($tasks, fn (array $a, array $b) => $a['sequence_id'] <=> $b['sequence_id']);

        $power = [];
        foreach ($tasks as $index => $task) {
            if ($task['action'] === 'power' && in_array(mb_strtolower(trim($task['payload'])), self::POWER, true)) {
                $power[] = $index;
            }
        }
        if ($power === []) {
            return [];
        }

        // Still running: when the power task comes is unknown, so say it is happening now.
        if ($processing) {
            return [$now];
        }
        if ($lastRunAt === null) {
            return [];
        }

        $times = [];
        foreach ($power as $index) {
            $after = 0;
            for ($next = $index + 1; $next < count($tasks); $next++) {
                $after += max(0, (int) $tasks[$next]['time_offset']);
            }
            $times[] = $lastRunAt - $after;
        }

        return $times;
    }
}
