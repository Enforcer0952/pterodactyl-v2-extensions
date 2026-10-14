<?php

namespace CrashGuard\Support;

/**
 * Something that looks like a crash, found by comparing two readings. It only counts
 * once CrashDetector::explained() has found no power action that accounts for it.
 */
final readonly class Candidate
{
    public function __construct(
        /** One of the CrashDetector::VIA_* values. */
        public string $via,
        /** When the change was seen (the newer reading). */
        public int $at,
        /** When the server was last seen fine (the older reading). */
        public int $since,
        /** How long the run that ended had lasted at the older reading, in milliseconds. */
        public ?int $uptimeBeforeMs,
    ) {}
}
