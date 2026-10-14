<?php

namespace CrashGuard\Support;

/**
 * One reading of a server: its power state, how long the current run has lasted
 * (milliseconds, as Wings reports it) and when the reading was taken (unix seconds).
 */
final readonly class Sample
{
    public const array STATES = ['running', 'starting', 'stopping', 'offline'];

    public function __construct(
        public string $state,
        public int $uptimeMs,
        public int $at,
    ) {}

    /** A reading Wings gave a state this extension knows nothing about is not a reading at all. */
    public static function valid(string $state): bool
    {
        return in_array($state, self::STATES, true);
    }
}
