<?php

use CrashGuard\Support\ScheduleTimes;
use PHPUnit\Framework\TestCase;

class ScheduleTimesTest extends TestCase
{
    private const int NOW = 1_800_000_000;

    /** @return array{sequence_id: int, action: string, payload: string, time_offset: int} */
    private function task(int $sequence, string $action, string $payload, int $offset = 0): array
    {
        return ['sequence_id' => $sequence, 'action' => $action, 'payload' => $payload, 'time_offset' => $offset];
    }

    public function test_a_schedule_without_power_tasks_explains_nothing(): void
    {
        $tasks = [$this->task(1, 'command', 'say hi'), $this->task(2, 'backup', '')];
        $this->assertSame([], ScheduleTimes::powerTimes($tasks, self::NOW - 10, false, self::NOW));
    }

    public function test_a_lone_restart_task_ran_when_the_schedule_finished(): void
    {
        $this->assertSame([self::NOW - 10], ScheduleTimes::powerTimes([$this->task(1, 'power', 'restart')], self::NOW - 10, false, self::NOW));
    }

    public function test_tasks_after_the_power_task_move_it_earlier(): void
    {
        // Warn, wait 5 minutes, restart, wait a minute, say it is done.
        $tasks = [
            $this->task(3, 'command', 'say done', 60),
            $this->task(1, 'command', 'say restarting in 5 minutes', 0),
            $this->task(2, 'power', 'restart', 300),
        ];
        $this->assertSame([self::NOW - 60], ScheduleTimes::powerTimes($tasks, self::NOW, false, self::NOW));
    }

    public function test_every_power_task_is_listed(): void
    {
        $tasks = [$this->task(1, 'power', 'stop', 0), $this->task(2, 'power', 'start', 30)];
        $this->assertSame([self::NOW - 30, self::NOW], ScheduleTimes::powerTimes($tasks, self::NOW, false, self::NOW));
    }

    public function test_a_running_schedule_counts_as_now(): void
    {
        $this->assertSame([self::NOW], ScheduleTimes::powerTimes([$this->task(1, 'power', 'kill')], null, true, self::NOW));
    }

    public function test_a_schedule_that_never_ran_explains_nothing(): void
    {
        $this->assertSame([], ScheduleTimes::powerTimes([$this->task(1, 'power', 'restart')], null, false, self::NOW));
    }

    public function test_payloads_are_matched_loosely_and_unknown_ones_ignored(): void
    {
        $this->assertCount(1, ScheduleTimes::powerTimes([$this->task(1, 'power', ' Restart ')], self::NOW, false, self::NOW));
        $this->assertSame([], ScheduleTimes::powerTimes([$this->task(1, 'power', 'explode')], self::NOW, false, self::NOW));
        $this->assertSame([], ScheduleTimes::powerTimes([], self::NOW, false, self::NOW));
    }

    public function test_negative_offsets_are_ignored(): void
    {
        $tasks = [$this->task(1, 'power', 'restart'), $this->task(2, 'command', 'x', -500)];
        $this->assertSame([self::NOW], ScheduleTimes::powerTimes($tasks, self::NOW, false, self::NOW));
    }
}
