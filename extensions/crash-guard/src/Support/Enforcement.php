<?php

namespace CrashGuard\Support;

/**
 * What to do about a tripped server on each tick. The server is meant to stay off:
 * stop it if it is up, kill it if the stop did not work, and let it be when someone
 * started it on purpose.
 */
final class Enforcement
{
    public const string NOTHING = 'nothing';

    /** First time it is seen up with no stop sent: remember it, give the activity log time to show who started it. */
    public const string WATCH = 'watch';

    public const string STOP = 'stop';

    public const string KILL = 'kill';

    /** A user started the server after the trip: they know, so the trip is over. */
    public const string RELEASE = 'release';

    /** Seconds a stop gets before the server is killed. */
    public const int KILL_AFTER = 30;

    /** Seconds a server may stay up unexplained, so the activity log can show a user start. */
    public const int WATCH_SECONDS = 90;

    /**
     * @param  ?int  $stopSentAt  when the guard last sent a stop (unix seconds)
     * @param  ?int  $upSeenAt  when the server was first seen up with no stop sent
     * @param  bool  $userStarted  a user started or restarted the server after the trip
     */
    public static function decide(string $state, ?int $stopSentAt, ?int $upSeenAt, int $now, bool $userStarted): string
    {
        if ($state === 'offline') {
            return self::NOTHING;
        }

        if ($userStarted && in_array($state, ['running', 'starting'], true)) {
            return self::RELEASE;
        }

        if ($stopSentAt !== null) {
            // A stop is on its way. Give it time, then kill.
            return $now - $stopSentAt >= self::KILL_AFTER ? self::KILL : self::NOTHING;
        }

        if ($state === 'stopping') {
            return self::NOTHING;
        }

        if ($upSeenAt === null) {
            return self::WATCH;
        }

        return $now - $upSeenAt >= self::WATCH_SECONDS ? self::STOP : self::NOTHING;
    }
}
