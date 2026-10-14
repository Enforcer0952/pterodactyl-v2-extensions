<?php

use CrashGuard\Support\Enforcement;
use CrashGuard\Support\Limits;
use PHPUnit\Framework\TestCase;

class EnforcementTest extends TestCase
{
    private const int NOW = 1_800_000_000;

    public function test_an_offline_server_needs_nothing(): void
    {
        $this->assertSame(Enforcement::NOTHING, Enforcement::decide('offline', null, null, self::NOW, false));
        $this->assertSame(Enforcement::NOTHING, Enforcement::decide('offline', self::NOW - 100, self::NOW - 100, self::NOW, true));
    }

    public function test_a_stop_gets_time_before_the_kill(): void
    {
        $this->assertSame(Enforcement::NOTHING, Enforcement::decide('running', self::NOW - 10, null, self::NOW, false));
        $this->assertSame(Enforcement::NOTHING, Enforcement::decide('running', self::NOW - 29, null, self::NOW, false));
        $this->assertSame(Enforcement::KILL, Enforcement::decide('running', self::NOW - 30, null, self::NOW, false));
        $this->assertSame(Enforcement::KILL, Enforcement::decide('stopping', self::NOW - 60, null, self::NOW, false));
        $this->assertSame(Enforcement::NOTHING, Enforcement::decide('stopping', self::NOW - 5, null, self::NOW, false));
    }

    public function test_a_server_that_comes_back_up_alone_is_watched_then_stopped(): void
    {
        $this->assertSame(Enforcement::WATCH, Enforcement::decide('starting', null, null, self::NOW, false));
        $this->assertSame(Enforcement::NOTHING, Enforcement::decide('running', null, self::NOW - 60, self::NOW, false));
        $this->assertSame(Enforcement::STOP, Enforcement::decide('running', null, self::NOW - 90, self::NOW, false));
    }

    public function test_a_user_start_ends_the_trip(): void
    {
        $this->assertSame(Enforcement::RELEASE, Enforcement::decide('running', null, self::NOW - 10, self::NOW, true));
        $this->assertSame(Enforcement::RELEASE, Enforcement::decide('starting', null, null, self::NOW, true));
        // Even while a stop of ours is pending: the user's start came after the trip.
        $this->assertSame(Enforcement::RELEASE, Enforcement::decide('running', self::NOW - 40, null, self::NOW, true));
    }

    public function test_a_server_already_stopping_is_not_stopped_again(): void
    {
        $this->assertSame(Enforcement::NOTHING, Enforcement::decide('stopping', null, null, self::NOW, false));
    }

    public function test_limits_are_clamped(): void
    {
        $this->assertSame(3, Limits::crashes(null));
        $this->assertSame(3, Limits::crashes('abc'));
        $this->assertSame(2, Limits::crashes(1));
        $this->assertSame(2, Limits::crashes(-5));
        $this->assertSame(20, Limits::crashes(99));
        $this->assertSame(7, Limits::crashes('7'));
        $this->assertSame(7, Limits::crashes(7.9));
        $this->assertSame(3, Limits::crashes(NAN));
        $this->assertSame(3, Limits::crashes(['x']));
        $this->assertSame(10, Limits::minutes(null));
        $this->assertSame(1, Limits::minutes(0));
        $this->assertSame(120, Limits::minutes(100000));
        $this->assertSame(45, Limits::minutes('45'));
        $this->assertSame(10, Limits::minutes('4e1'));
    }
}
