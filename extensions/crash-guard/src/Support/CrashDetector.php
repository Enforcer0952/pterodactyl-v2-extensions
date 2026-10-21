<?php

namespace CrashGuard\Support;

/**
 * Decides, from two readings a minute or so apart, whether a server crashed. It never
 * touches the panel: readings and the times of power actions come in as plain values.
 *
 * What is a crash: the server was running (or starting) and now it is offline, or a
 * fresh run began (the uptime is shorter than the time that passed, or the state fell
 * back to "starting") while nobody asked for it. Whoever asked is found by the caller
 * (power buttons and API calls in the activity log, schedules) and passed to explained().
 */
final class CrashDetector
{
    public const string VIA_OFFLINE = 'went-offline';

    public const string VIA_UPTIME = 'uptime-drop';

    public const string VIA_RESTARTED = 'restarted';

    /** Readings can be up to 20 seconds old (the panel caches them), so allow 30 s of drift in the uptime. */
    public const int UPTIME_SLACK_MS = 30_000;

    /** Further apart than this and the readings say nothing about what happened in between. */
    public const int MAX_GAP_SECONDS = 900;

    /** A power action logged this long before the older reading still explains a change. */
    public const int GRACE_BEFORE = 30;

    /** A log entry can carry a time slightly after the reading it explains. */
    public const int GRACE_AFTER = 5;

    /** Wings reports its activity in batches, so wait this long for power actions to be logged. */
    public const int SETTLE_SECONDS = 120;

    /** Minimum guarded servers on one node that look crashed at once before it is called a node event. */
    public const int NODE_EVENT_MIN = 2;

    public static function observe(?Sample $previous, Sample $current): ?Candidate
    {
        if (! $previous instanceof Sample) {
            return null;
        }

        $elapsed = $current->at - $previous->at;
        if ($elapsed <= 0 || $elapsed > self::MAX_GAP_SECONDS) {
            return null;
        }

        // A stop that was asked for, or nothing running to lose: no crash to find.
        if (! in_array($previous->state, ['running', 'starting'], true) || $current->state === 'stopping') {
            return null;
        }

        $before = $previous->uptimeMs > 0 ? $previous->uptimeMs : null;

        if ($current->state === 'offline') {
            return new Candidate(self::VIA_OFFLINE, $current->at, $previous->at, $before);
        }

        // Running went back to starting: only a new run does that.
        if ($previous->state === 'running' && $current->state === 'starting') {
            return new Candidate(self::VIA_RESTARTED, $current->at, $previous->at, $before);
        }

        // The run is younger than the time between the readings: it began in between.
        if ($previous->uptimeMs > 0 && $current->uptimeMs > 0 && $current->uptimeMs + self::UPTIME_SLACK_MS < $previous->uptimeMs + $elapsed * 1000) {
            return new Candidate(self::VIA_UPTIME, $current->at, $previous->at, $before);
        }

        return null;
    }

    /**
     * Whether one of these power actions (unix seconds) accounts for the candidate.
     *
     * @param  list<int>  $actionTimes  power actions by users, the API and schedules
     */
    public static function explained(Candidate $candidate, array $actionTimes): bool
    {
        foreach ($actionTimes as $time) {
            if ($time >= $candidate->since - self::GRACE_BEFORE && $time <= $candidate->at + self::GRACE_AFTER) {
                return true;
            }
        }

        return false;
    }

    /** Whether the activity log has had time to report the actions around a candidate. */
    public static function settled(int $candidateAt, int $now): bool
    {
        return $now - $candidateAt >= self::SETTLE_SECONDS;
    }

    /**
     * Many servers on one node looking crashed in the same minute is the node restarting
     * (or Wings being updated), not the games. Those are not counted.
     */
    /**
     * @param  int  $crashed  servers that looked crashed this tick
     * @param  int  $sampled  servers that were running or starting at the previous tick
     */
    public static function nodeEvent(int $crashed, int $sampled): bool
    {
        return $crashed >= self::NODE_EVENT_MIN && $crashed * 2 >= $sampled;
    }
}
