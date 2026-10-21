<?php

namespace PalworldTools\Support;

/**
 * Waits for the game to finish a save by watching when a save file was last changed.
 */
final class SaveWait
{
    /**
     * @param  callable(): ?string  $modified  the file's current modified time, null when it is missing
     * @param  callable(): void  $pause  waits between two looks
     * @return bool whether the time changed from $before within $tries looks
     */
    public static function changed(?string $before, callable $modified, callable $pause, int $tries): bool
    {
        for ($i = 0; $i < $tries; $i++) {
            $pause();
            $now = $modified();
            if ($now !== null && $now !== $before) {
                return true;
            }
        }

        return false;
    }
}
