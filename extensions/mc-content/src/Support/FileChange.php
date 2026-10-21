<?php

namespace McContent\Support;

/**
 * Decides whether a recorded install still describes the jar on disk. A record
 * remembers the file's size and modification time; a jar replaced by hand under the
 * same name no longer matches and must be recognised again.
 */
final class FileChange
{
    public const string SAME = 'same';

    /** The record has no size or time yet (older records): take them from the file. */
    public const string ADOPT = 'adopt';

    public const string CHANGED = 'changed';

    /**
     * @param  int|null  $recordedSize  size stored with the install, if any
     * @param  int|null  $recordedTime  modification time (Unix seconds) stored, if any
     * @param  int  $size  size in the folder listing
     * @param  int|null  $time  modification time in the listing (Unix seconds), if known
     */
    public static function compare(?int $recordedSize, ?int $recordedTime, int $size, ?int $time): string
    {
        if ($recordedSize !== null && $recordedSize !== $size) {
            return self::CHANGED;
        }

        if ($recordedTime !== null && $time !== null && $recordedTime !== $time) {
            return self::CHANGED;
        }

        if ($recordedSize === null || ($recordedTime === null && $time !== null)) {
            return self::ADOPT;
        }

        return self::SAME;
    }

    /** A listing's modification time (ISO string or number) as Unix seconds. */
    public static function timestamp(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            $time = strtotime($value);

            return $time === false ? null : $time;
        }

        return null;
    }
}
