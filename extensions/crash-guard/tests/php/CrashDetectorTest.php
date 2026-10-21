<?php

use CrashGuard\Support\Candidate;
use CrashGuard\Support\CrashDetector;
use CrashGuard\Support\CrashWindow;
use CrashGuard\Support\Sample;
use PHPUnit\Framework\TestCase;

class CrashDetectorTest extends TestCase
{
    private const int T0 = 1_800_000_000;

    private function s(string $state, int $uptimeSeconds, int $minute): Sample
    {
        return new Sample($state, $uptimeSeconds * 1000, self::T0 + $minute * 60);
    }

    /**
     * Plays readings one minute apart through the detector the way the tick does and
     * returns the crashes that count: not explained by an action, settled, in order.
     *
     * @param  list<Sample>  $samples
     * @param  list<int>  $actions  unix times of power actions by users or schedules
     * @return list<Candidate>
     */
    private function crashes(array $samples, array $actions = []): array
    {
        $found = [];
        $previous = null;
        foreach ($samples as $sample) {
            $candidate = CrashDetector::observe($previous, $sample);
            if ($candidate instanceof Candidate && ! CrashDetector::explained($candidate, $actions)) {
                $found[] = $candidate;
            }
            $previous = $sample;
        }

        return $found;
    }

    public function test_a_steady_server_has_no_crash(): void
    {
        $this->assertSame([], $this->crashes([
            $this->s('running', 600, 0),
            $this->s('running', 660, 1),
            $this->s('running', 720, 2),
        ]));
    }

    public function test_first_reading_has_nothing_to_compare(): void
    {
        $this->assertNull(CrashDetector::observe(null, $this->s('offline', 0, 0)));
    }

    public function test_clean_stop_by_a_user_is_not_a_crash(): void
    {
        $found = $this->crashes(
            [$this->s('running', 600, 0), $this->s('offline', 0, 1)],
            [self::T0 + 40],
        );
        $this->assertSame([], $found);
    }

    public function test_stop_seen_in_stopping_state_is_not_a_crash(): void
    {
        $this->assertSame([], $this->crashes([
            $this->s('running', 600, 0),
            $this->s('stopping', 650, 1),
            $this->s('offline', 0, 2),
        ]));
        $this->assertNull(CrashDetector::observe($this->s('stopping', 650, 1), $this->s('offline', 0, 2)));
    }

    public function test_scheduled_restart_with_a_fast_comeback_is_not_a_crash(): void
    {
        // The schedule ran at minute 0.5; the server is back with a young uptime.
        $found = $this->crashes(
            [$this->s('running', 3600, 0), $this->s('running', 25, 1)],
            [self::T0 + 30],
        );
        $this->assertSame([], $found);
    }

    public function test_user_restart_is_not_a_crash_even_when_the_log_entry_is_just_before_the_older_reading(): void
    {
        $found = $this->crashes(
            [$this->s('running', 3600, 0), $this->s('running', 40, 1)],
            [self::T0 - 10],
        );
        $this->assertSame([], $found);
    }

    public function test_crash_with_a_fast_automatic_restart_shows_as_an_uptime_drop(): void
    {
        $found = $this->crashes([$this->s('running', 600, 0), $this->s('running', 20, 1)]);

        $this->assertCount(1, $found);
        $this->assertSame(CrashDetector::VIA_UPTIME, $found[0]->via);
        $this->assertSame(600_000, $found[0]->uptimeBeforeMs);
        $this->assertSame(self::T0, $found[0]->since);
        $this->assertSame(self::T0 + 60, $found[0]->at);
    }

    public function test_uptime_drop_is_found_even_when_the_new_run_is_longer_than_the_old_uptime(): void
    {
        // Old run: 30 s up at the first reading. Crashed at 40 s, back 20 s later.
        $found = $this->crashes([$this->s('running', 30, 0), $this->s('running', 15, 1)]);
        $this->assertCount(1, $found);
        $this->assertSame(CrashDetector::VIA_UPTIME, $found[0]->via);
    }

    public function test_crash_that_stays_offline_is_found_once(): void
    {
        $found = $this->crashes([
            $this->s('running', 600, 0),
            $this->s('offline', 0, 1),
            $this->s('offline', 0, 2),
            $this->s('offline', 0, 3),
        ]);

        $this->assertCount(1, $found);
        $this->assertSame(CrashDetector::VIA_OFFLINE, $found[0]->via);
    }

    public function test_server_that_fell_back_to_starting_crashed(): void
    {
        $found = $this->crashes([$this->s('running', 600, 0), $this->s('starting', 0, 1)]);

        $this->assertCount(1, $found);
        $this->assertSame(CrashDetector::VIA_RESTARTED, $found[0]->via);
    }

    public function test_crash_while_starting_is_found(): void
    {
        $found = $this->crashes([$this->s('starting', 40, 0), $this->s('offline', 0, 1)]);

        $this->assertCount(1, $found);
        $this->assertSame(CrashDetector::VIA_OFFLINE, $found[0]->via);
    }

    public function test_a_slow_start_is_not_a_crash(): void
    {
        $this->assertSame([], $this->crashes([
            $this->s('starting', 5, 0),
            $this->s('starting', 65, 1),
            $this->s('starting', 125, 2),
            $this->s('starting', 185, 3),
            $this->s('running', 245, 4),
        ]));
    }

    public function test_a_start_from_offline_is_not_a_crash(): void
    {
        $this->assertSame([], $this->crashes([
            $this->s('offline', 0, 0),
            $this->s('starting', 10, 1),
            $this->s('running', 70, 2),
        ]));
    }

    public function test_starting_without_a_reported_uptime_is_not_compared(): void
    {
        $this->assertSame([], $this->crashes([$this->s('starting', 0, 0), $this->s('starting', 0, 1), $this->s('running', 5, 2)]));
    }

    public function test_cached_readings_up_to_twenty_seconds_old_do_not_look_like_a_restart(): void
    {
        // The newer reading came from a cache 20 s old: uptime 20 s short of the time that passed.
        $this->assertSame([], $this->crashes([$this->s('running', 600, 0), new Sample('running', 640_000, self::T0 + 60)]));
        // An older cached first reading is no problem either.
        $this->assertSame([], $this->crashes([new Sample('running', 580_000, self::T0), new Sample('running', 660_000, self::T0 + 60)]));
    }

    public function test_node_unreachable_leaves_no_reading_so_no_crash(): void
    {
        // The tick skips unreachable nodes: the readings around the outage are compared directly.
        $found = $this->crashes([$this->s('running', 600, 0), $this->s('running', 3000, 40)]);
        $this->assertSame([], $found);
    }

    public function test_after_a_long_gap_nothing_is_concluded(): void
    {
        $this->assertNull(CrashDetector::observe($this->s('running', 600, 0), $this->s('offline', 0, 16)));
        $this->assertNull(CrashDetector::observe($this->s('running', 600, 0), $this->s('running', 10, 16)));
        $this->assertNotNull(CrashDetector::observe($this->s('running', 600, 0), $this->s('running', 10, 15)));
    }

    public function test_readings_out_of_order_are_ignored(): void
    {
        $this->assertNull(CrashDetector::observe($this->s('running', 600, 5), $this->s('offline', 0, 5)));
        $this->assertNull(CrashDetector::observe($this->s('running', 600, 5), $this->s('offline', 0, 4)));
    }

    public function test_a_server_that_is_stopping_now_is_left_alone(): void
    {
        $this->assertNull(CrashDetector::observe($this->s('running', 600, 0), $this->s('stopping', 660, 1)));
    }

    public function test_explained_window_edges(): void
    {
        $candidate = new Candidate(CrashDetector::VIA_OFFLINE, self::T0 + 60, self::T0, 600_000);

        $this->assertTrue(CrashDetector::explained($candidate, [self::T0 - 30]));
        $this->assertFalse(CrashDetector::explained($candidate, [self::T0 - 31]));
        $this->assertTrue(CrashDetector::explained($candidate, [self::T0 + 65]));
        $this->assertFalse(CrashDetector::explained($candidate, [self::T0 + 66]));
        $this->assertFalse(CrashDetector::explained($candidate, []));
        $this->assertTrue(CrashDetector::explained($candidate, [1, self::T0 + 10, 99]));
    }

    public function test_an_action_long_before_does_not_explain_a_crash(): void
    {
        $found = $this->crashes(
            [$this->s('running', 600, 0), $this->s('running', 20, 1)],
            [self::T0 - 3600],
        );
        $this->assertCount(1, $found);
    }

    public function test_settling_waits_for_the_activity_report(): void
    {
        $this->assertFalse(CrashDetector::settled(self::T0, self::T0 + 119));
        $this->assertTrue(CrashDetector::settled(self::T0, self::T0 + 120));
    }

    public function test_many_servers_crashing_at_once_is_a_node_event(): void
    {
        $this->assertFalse(CrashDetector::nodeEvent(1, 1));
        $this->assertTrue(CrashDetector::nodeEvent(2, 2)); // two of two running servers: the node;
        $this->assertFalse(CrashDetector::nodeEvent(2, 5));
        $this->assertTrue(CrashDetector::nodeEvent(3, 3));
        $this->assertTrue(CrashDetector::nodeEvent(3, 6));
        $this->assertFalse(CrashDetector::nodeEvent(3, 7));
        $this->assertFalse(CrashDetector::nodeEvent(0, 0));
    }

    /* Threshold window */

    /** @return list<int> */
    private function times(int ...$minutesAgo): array
    {
        return array_map(fn (int $m) => self::T0 - $m * 60, $minutesAgo);
    }

    public function test_three_crashes_in_ten_minutes_trip(): void
    {
        $this->assertTrue(CrashWindow::tripped($this->times(9, 5, 0), self::T0, 3, 10));
        $this->assertSame(3, CrashWindow::count($this->times(9, 5, 0), self::T0, 10));
    }

    public function test_two_crashes_are_below_the_limit(): void
    {
        $this->assertFalse(CrashWindow::tripped($this->times(5, 0), self::T0, 3, 10));
    }

    public function test_crash_exactly_at_the_window_edge_is_outside(): void
    {
        $this->assertFalse(CrashWindow::tripped($this->times(10, 5, 0), self::T0, 3, 10));
        $this->assertSame(2, CrashWindow::count($this->times(10, 5, 0), self::T0, 10));
        // One second younger than the edge is inside.
        $this->assertTrue(CrashWindow::tripped([self::T0 - 599, self::T0 - 300, self::T0], self::T0, 3, 10));
    }

    public function test_old_crashes_age_out(): void
    {
        $this->assertFalse(CrashWindow::tripped($this->times(40, 30, 20, 0), self::T0, 3, 10));
    }

    public function test_crashes_after_now_are_ignored(): void
    {
        $this->assertSame(1, CrashWindow::count([self::T0 + 5, self::T0 - 5], self::T0, 10));
    }

    public function test_clearing_starts_the_count_over(): void
    {
        $crashes = $this->times(8, 6, 4);
        $this->assertTrue(CrashWindow::tripped($crashes, self::T0, 3, 10));

        $clearedAt = self::T0 - 2 * 60;
        $this->assertFalse(CrashWindow::tripped($crashes, self::T0, 3, 10, $clearedAt));

        // After the clear, three new crashes trip again.
        $again = [...$crashes, self::T0 - 60, self::T0 - 30, self::T0];
        $this->assertTrue(CrashWindow::tripped($again, self::T0, 3, 10, $clearedAt));
        $this->assertSame(3, CrashWindow::count($again, self::T0, 10, $clearedAt));
    }

    public function test_a_crash_at_the_moment_of_clearing_does_not_count(): void
    {
        $this->assertSame(0, CrashWindow::count([self::T0], self::T0, 10, self::T0));
    }

    public function test_single_crash_per_minute_pair_counts_as_one(): void
    {
        // Two quick crashes inside one minute leave one uptime drop: one event.
        $found = $this->crashes([$this->s('running', 600, 0), $this->s('running', 5, 1), $this->s('running', 65, 2)]);
        $this->assertCount(1, $found);
    }

    /* A whole crash loop, the way the tick sees it */

    public function test_crash_loop_trips_on_the_third_crash(): void
    {
        // Runs of about a minute, each ending in a crash and an automatic restart.
        $samples = [
            $this->s('running', 600, 0),
            $this->s('running', 15, 1),  // crash 1
            $this->s('running', 75, 2),
            $this->s('running', 10, 3),  // crash 2
            $this->s('running', 70, 4),
            $this->s('running', 20, 5),  // crash 3
        ];

        $times = array_map(fn (Candidate $c) => $c->at, $this->crashes($samples));
        $this->assertCount(3, $times);
        $this->assertFalse(CrashWindow::tripped(array_slice($times, 0, 2), $times[1], 3, 10));
        $this->assertTrue(CrashWindow::tripped($times, $times[2], 3, 10));
    }

    public function test_crashes_spread_over_hours_never_trip(): void
    {
        $times = [self::T0, self::T0 + 3600, self::T0 + 7200, self::T0 + 10800];
        foreach ($times as $now) {
            $this->assertFalse(CrashWindow::tripped(array_filter($times, fn ($t) => $t <= $now), $now, 3, 10));
        }
    }
}
