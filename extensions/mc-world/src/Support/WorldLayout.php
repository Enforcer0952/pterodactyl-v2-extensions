<?php

namespace McWorld\Support;

/**
 * Finds worlds in a server's root listing. A folder is a world when it holds a
 * level.dat. Paper and Spigot keep the nether and end in sibling folders
 * (world_nether, world_the_end); vanilla and Fabric keep them inside the world
 * (DIM-1, DIM1), so there is nothing extra to find.
 *
 * Entries are Wings file objects: name, file (false for a folder), symlink, modified.
 */
final class WorldLayout
{
    /** Folders to look inside for a level.dat; more than this are ignored. */
    public const int MAX_PROBES = 30;

    /**
     * Folders worth looking inside, the active one first.
     *
     * @param  list<array<string, mixed>>  $root
     * @return list<string>
     */
    public static function candidates(array $root, string $active): array
    {
        $folders = self::folders($root);
        $names = array_keys($folders);
        $candidates = [];
        foreach ($names as $name) {
            $parent = Names::dimensionOf($name);
            if (! Names::world($name) || ($parent !== null && isset($folders[$parent]))) {
                continue;
            }
            $candidates[] = $name;
        }
        usort($candidates, fn (string $a, string $b): int => [$a !== $active, $a] <=> [$b !== $active, $b]);

        return array_slice($candidates, 0, self::MAX_PROBES);
    }

    /**
     * @param  list<array<string, mixed>>  $root  the server root listing
     * @param  array<string, ?string>  $levelDats  folder => last modified of its level.dat; absent when it has none
     * @return list<array{name: string, active: bool, modified: ?string, folders: list<string>, layout: string}>
     */
    public static function detect(array $root, array $levelDats, string $active): array
    {
        $folders = self::folders($root);
        $worlds = [];
        foreach (array_keys($levelDats) as $name) {
            if (! isset($folders[$name]) || ! Names::world($name)) {
                continue;
            }
            $parent = Names::dimensionOf($name);
            if ($parent !== null && isset($folders[$parent])) {
                continue;
            }

            $own = [$name];
            foreach ([Names::nether($name), Names::end($name)] as $dimension) {
                if (isset($folders[$dimension])) {
                    $own[] = $dimension;
                }
            }
            $worlds[] = [
                'name' => $name,
                'active' => $name === $active,
                'modified' => $levelDats[$name],
                'folders' => $own,
                'layout' => count($own) > 1 ? 'split' : 'single',
            ];
        }

        usort($worlds, fn (array $a, array $b): int => [! $a['active'], $a['name']] <=> [! $b['active'], $b['name']]);

        return $worlds;
    }

    /**
     * Real folders in the listing (symbolic links are left alone), by name.
     *
     * @param  list<array<string, mixed>>  $root
     * @return array<string, array<string, mixed>>
     */
    public static function folders(array $root): array
    {
        $folders = [];
        foreach ($root as $entry) {
            $name = (string) ($entry['name'] ?? '');
            if ($name === '' || ($entry['file'] ?? true) !== false || ($entry['symlink'] ?? false) === true) {
                continue;
            }
            $folders[$name] = $entry;
        }

        return $folders;
    }
}
