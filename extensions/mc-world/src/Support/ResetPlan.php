<?php

namespace McWorld\Support;

use InvalidArgumentException;

/**
 * The steps of a world reset, in the order they must run: back up, set the seed,
 * delete, start. Nothing is deleted before the backup step, the new seed is saved before
 * anything is deleted, and the server only starts once the world is gone. Runs nothing itself.
 */
final class ResetPlan
{
    /**
     * @param  list<string>  $folders  the world folder first, then its nether and end folders that exist
     * @return list<array{type: string, world?: string, folders?: list<string>, archive?: string, value?: string}>
     *
     * @throws InvalidArgumentException
     */
    public static function build(string $world, array $folders, ?string $seed, bool $backup, bool $start, string $stamp): array
    {
        if (! Names::world($world) || ! Names::validStamp($stamp)) {
            throw new InvalidArgumentException('That is not a valid world name.');
        }
        $allowed = [$world, Names::nether($world), Names::end($world)];
        if ($folders === [] || $folders[0] !== $world || array_diff($folders, $allowed) !== [] || count(array_unique($folders)) !== count($folders)) {
            throw new InvalidArgumentException('The folders to delete do not belong to this world.');
        }

        $steps = [];
        if ($backup) {
            $steps[] = ['type' => 'backup', 'world' => $world, 'folders' => $folders, 'archive' => Names::BACKUP_DIR.'/'.Names::backupFile($world, $stamp)];
        }
        $steps[] = ['type' => 'seed', 'value' => $seed ?? ''];
        $steps[] = ['type' => 'delete', 'folders' => $folders];
        if ($start) {
            $steps[] = ['type' => 'start'];
        }

        return $steps;
    }
}
