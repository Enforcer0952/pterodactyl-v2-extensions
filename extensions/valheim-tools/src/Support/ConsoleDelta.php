<?php

namespace ValheimTools\Support;

/**
 * The console log only ever shows the last lines. This finds what was added between two
 * reads, so a line is never counted twice when the same lines are read again.
 */
final class ConsoleDelta
{
    /**
     * @param  list<string>  $before  lines read earlier, oldest first
     * @param  list<string>  $after  lines read now
     * @return list<string> lines that arrived in between
     */
    public static function since(array $before, array $after): array
    {
        $before = array_values($before);
        $after = array_values($after);
        $max = min(count($before), count($after));

        // The longest tail of the old read that starts the new read: the log scrolled by that much.
        for ($overlap = $max; $overlap > 0; $overlap--) {
            if (array_slice($before, -$overlap) === array_slice($after, 0, $overlap)) {
                return array_slice($after, $overlap);
            }
        }

        // Nothing in common: the whole buffer was replaced (or the log was empty).
        return $after;
    }
}
