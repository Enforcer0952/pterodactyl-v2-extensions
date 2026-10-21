<?php

namespace McPlayerData\Support;

/**
 * Which of a player's backup files to remove. At least the newest few are always
 * kept, and so is anything from the last days, so a burst of edits cannot push out
 * the copy that was there before the first one.
 */
final class Backups
{
    public const int KEEP_AT_LEAST = 10;

    public const int KEEP_DAYS = 7;

    /**
     * @param  list<string>  $names  backup file names, newest first
     * @return list<string> names that can be deleted
     */
    public static function expired(array $names, \DateTimeImmutable $now, int $keepAtLeast = self::KEEP_AT_LEAST, int $keepDays = self::KEEP_DAYS): array
    {
        $limit = $now->modify('-'.$keepDays.' days');
        $delete = [];

        foreach (array_values($names) as $index => $name) {
            if ($index < $keepAtLeast) {
                continue;
            }

            $time = self::time($name);
            // A name whose time cannot be read is kept: better an extra file than a lost one.
            if ($time !== null && $time < $limit) {
                $delete[] = $name;
            }
        }

        return $delete;
    }

    /** The time a backup was made, from "<uuid>.dat.mcpd-bak-20260101-120000[-2]". */
    public static function time(string $name): ?\DateTimeImmutable
    {
        if (preg_match('/\.mcpd-bak-(\d{4})(\d{2})(\d{2})-(\d{2})(\d{2})(\d{2})(?:-\d+)?$/D', $name, $m) !== 1) {
            return null;
        }

        if (! checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || (int) $m[4] > 23 || (int) $m[5] > 59 || (int) $m[6] > 59) {
            return null;
        }

        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->setDate((int) $m[1], (int) $m[2], (int) $m[3])->setTime((int) $m[4], (int) $m[5], (int) $m[6]);
    }
}
