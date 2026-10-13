<?php

namespace ArkTools\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Contracts\Schedules\CreatesSchedules;
use Pterodactyl\Contracts\Schedules\CreatesScheduleTasks;
use Pterodactyl\Contracts\Schedules\DeletesSchedules;
use Pterodactyl\Contracts\Schedules\ProcessesSchedules;
use Pterodactyl\Models\Schedule;
use Pterodactyl\Models\Server;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Safe restarts built on the Panel's own schedules, so they run on the queue worker
 * and show up on the Schedules page: in-game countdown broadcasts, a world save, then
 * a restart. "Restart now" uses an inactive schedule that is run once by hand; the
 * daily restart is an active schedule with a cron time.
 */
class Restarts
{
    public const string NOW = 'ARK Tools: safe restart';

    public const string DAILY = 'ARK Tools: daily restart';

    /** Countdown that stops the server, so new settings can be written while it is off. */
    public const string APPLY = 'ARK Tools: apply settings';

    /** Countdown warnings, in minutes before the restart. */
    private const array WARNINGS = [30, 15, 10, 5, 1];

    public const array MINUTES = [0, 1, 5, 10, 15, 30];

    public function __construct(
        private readonly CreatesSchedules $schedules,
        private readonly CreatesScheduleTasks $tasks,
        private readonly DeletesSchedules $deletes,
        private readonly ProcessesSchedules $processor,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function status(Server $server): array
    {
        $now = $this->find($server, self::NOW);
        $daily = $this->find($server, self::DAILY);
        $apply = $this->find($server, self::APPLY);
        $running = collect([$now, $daily, $apply])->first(fn (?Schedule $schedule): bool => $schedule !== null && $schedule->is_processing);

        return [
            'running' => $running === null ? null : [
                'kind' => match ($running->name) {
                    self::DAILY => 'daily',
                    self::APPLY => 'apply',
                    default => 'now',
                },
                'restart_at' => $this->restartAt($running)?->toAtomString(),
            ],
            'daily' => $daily === null ? null : [
                'enabled' => $daily->is_active,
                'time' => $this->restartTime($daily),
                'minutes' => $this->warningMinutes($daily),
                'next_run_at' => $daily->is_active ? $daily->next_run_at?->toAtomString() : null,
            ],
            'timezone' => config('app.timezone'),
            'minutes' => self::MINUTES,
        ];
    }

    /** Start a countdown restart right away. */
    public function start(Server $server, int $minutes): void
    {
        $this->runOnce($server, self::NOW, $minutes, 'restart');
    }

    /** Count down, save the world and stop the server, so new settings can be written. */
    public function stopToApply(Server $server, int $minutes): void
    {
        $this->runOnce($server, self::APPLY, $minutes, 'stop');
    }

    /**
     * Remove the settings countdown, so a server that is started again after the
     * settings were written is not stopped by the countdown's last task.
     */
    public function dropApplySchedule(Server $server): void
    {
        $schedule = $this->find($server, self::APPLY);
        if ($schedule !== null) {
            $this->deletes->delete($schedule);
        }
    }

    /** Whether a settings countdown is under way. */
    public function applying(Server $server): bool
    {
        return (bool) $this->find($server, self::APPLY)?->is_processing;
    }

    private function runOnce(Server $server, string $name, int $minutes, string $power): void
    {
        $this->assertIdle($server);

        $existing = $this->find($server, $name);
        if ($existing !== null) {
            $this->deletes->delete($existing);
        }

        $schedule = DB::transaction(function () use ($server, $name, $minutes, $power): Schedule {
            // Inactive, so the cron never picks it up; it only runs when started here.
            $schedule = $this->schedules->create($server, [
                'name' => $name,
                'is_active' => false,
                'minute' => '0', 'hour' => '4', 'day_of_month' => '*', 'month' => '*', 'day_of_week' => '*',
                'only_when_online' => true,
            ]);
            $this->addTasks($schedule, $minutes, $power);

            return $schedule;
        });

        $this->processor->process($schedule->refresh(), true);
    }

    /** Stop a countdown that is under way. Deleting the schedule drops its queued tasks. */
    public function cancel(Server $server): bool
    {
        $cancelled = false;

        foreach ([self::NOW, self::APPLY] as $name) {
            $once = $this->find($server, $name);
            if ($once !== null && $once->is_processing) {
                $this->deletes->delete($once);
                $cancelled = true;
            }
        }

        // A cancelled settings countdown drops the settings waiting for it too.
        if ($cancelled) {
            DB::table(ConfigApply::TABLE)->where('server_id', $server->id)->delete();
        }

        $daily = $this->find($server, self::DAILY);
        if ($daily !== null && $daily->is_processing) {
            // Recreate it, so the daily restart itself stays set up.
            $settings = ['time' => $this->restartTime($daily), 'minutes' => $this->warningMinutes($daily)];
            $this->deletes->delete($daily);
            $this->daily($server, true, $settings['time'], $settings['minutes']);
            $cancelled = true;
        }

        return $cancelled;
    }

    /** Set up, change or turn off the daily restart. */
    public function daily(Server $server, bool $enabled, string $time, int $minutes): void
    {
        $existing = $this->find($server, self::DAILY);
        if ($existing !== null && $existing->is_processing) {
            throw new HttpException(409, 'The daily restart is counting down right now. Cancel it or wait until it is done.');
        }

        [$hour, $minute] = array_map('intval', explode(':', $time));

        DB::transaction(function () use ($server, $existing, $enabled, $hour, $minute, $minutes): void {
            if ($existing !== null) {
                $this->deletes->delete($existing);
            }

            // The countdown runs before the restart, so the schedule starts that much earlier.
            $start = CarbonImmutable::today()->setTime($hour, $minute)->subMinutes($minutes);

            $schedule = $this->schedules->create($server, [
                'name' => self::DAILY,
                'is_active' => $enabled,
                'minute' => (string) $start->minute,
                'hour' => (string) $start->hour,
                'day_of_month' => '*', 'month' => '*', 'day_of_week' => '*',
                'only_when_online' => true,
            ]);
            $this->addTasks($schedule, $minutes);
        });
    }

    private function addTasks(Schedule $schedule, int $minutes, string $power = 'restart'): void
    {
        $steps = [];
        $warnings = array_values(array_filter(self::WARNINGS, fn (int $warning): bool => $warning <= $minutes));
        $previous = null;
        foreach ($warnings as $warning) {
            $steps[] = [
                'command',
                'Broadcast Server restart in '.$warning.' minute'.($warning === 1 ? '' : 's').'. Get somewhere safe!',
                $previous === null ? 0 : ($previous - $warning) * 60,
            ];
            $previous = $warning;
        }

        if ($previous !== null) {
            $steps[] = ['command', 'Broadcast Server restarting in 10 seconds. Saving the world now.', $previous * 60 - 10];
            $steps[] = ['command', 'SaveWorld', 0];
            $steps[] = ['power', $power, 10];
        } else {
            $steps[] = ['command', 'Broadcast Server restarting now. Saving the world.', 0];
            $steps[] = ['command', 'SaveWorld', 0];
            $steps[] = ['power', $power, 5];
        }

        foreach ($steps as [$action, $payload, $offset]) {
            $this->tasks->create($schedule, [
                'action' => $action,
                'payload' => $payload,
                'time_offset' => $offset,
                'sequence_id' => null,
                // Keep going if a broadcast fails, so the restart still happens.
                'continue_on_failure' => true,
            ]);
        }
    }

    /** The time of day the restart itself happens: the cron time plus the countdown. */
    private function restartTime(Schedule $schedule): string
    {
        return CarbonImmutable::today()
            ->setTime((int) $schedule->cron_hour, (int) $schedule->cron_minute)
            ->addMinutes($this->warningMinutes($schedule))
            ->format('H:i');
    }

    private function restartAt(Schedule $schedule): ?CarbonImmutable
    {
        $total = (int) $schedule->tasks()->sum('time_offset');

        return CarbonImmutable::instance($schedule->updated_at)->addSeconds($total);
    }

    /** The first warning's length, read back from the schedule's tasks. */
    private function warningMinutes(Schedule $schedule): int
    {
        $first = $schedule->tasks()->orderBy('sequence_id')->first();
        if ($first !== null && preg_match('/in (\d+) minute/', $first->payload, $match) === 1) {
            return (int) $match[1];
        }

        return 0;
    }

    private function assertIdle(Server $server): void
    {
        foreach ([self::NOW, self::DAILY, self::APPLY] as $name) {
            if ($this->find($server, $name)?->is_processing) {
                throw new HttpException(409, 'A restart is already counting down.');
            }
        }
    }

    private function find(Server $server, string $name): ?Schedule
    {
        $schedule = $server->schedules()->where('name', $name)->latest('id')->first();

        return $schedule instanceof Schedule ? $schedule : null;
    }
}
