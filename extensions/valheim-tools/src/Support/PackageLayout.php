<?php

namespace ValheimTools\Support;

/**
 * Where the parts of an unpacked Thunderstore package go on a BepInEx server.
 *
 * Follows the convention the mod managers use for BepInEx games (r2modman, the Thunderstore
 * app): plugins/, patchers/ and monomod/ go to BepInEx/<that>/Owner-Name/, config/ goes to
 * BepInEx/config/, and loose files (the .dll, manifest.json) go to BepInEx/plugins/Owner-Name/.
 * README, CHANGELOG, LICENSE and icon.png are not needed on a server and are left out.
 * core/ is never installed: BepInEx's own files are not touched by this extension.
 *
 * Some packages were zipped on Windows and keep backslashes in the names ("plugins\Mod.dll").
 * Those arrive as one flat name and are split here.
 */
final class PackageLayout
{
    /** Folders that get their own Owner-Name subfolder. */
    private const array OWNED = ['plugins', 'patchers', 'monomod'];

    private const string IGNORED = '/^(readme|changelog|license|licence|icon|thirdpartynotices?)(\.[a-z0-9]+)?$/iD';

    /**
     * @param  list<array{name: string, file: bool}>  $entries  top level of the unpacked package
     * @param  string  $folder  "Owner-Name"
     * @return array{moves: list<array{from: string, to: string, dir: bool, merge: bool}>, skipped: list<string>, error: ?string}
     */
    public static function plan(array $entries, string $folder): array
    {
        $moves = [];
        $skipped = [];

        if (Thunderstore::parseFolder($folder) === null) {
            return ['moves' => [], 'skipped' => [], 'error' => 'The package name is not valid.'];
        }

        foreach ($entries as $entry) {
            $name = $entry['name'];
            $segments = self::segments($name);
            if ($segments === null) {
                return ['moves' => [], 'skipped' => [], 'error' => 'The package has a file name that is not safe to install: '.Valheim::text($name, 60).'.'];
            }

            $first = strtolower($segments[0]);
            if ($first === 'bepinex') {
                return ['moves' => [], 'skipped' => [], 'error' => 'This package ships its own BepInEx folder. Install it by hand with the file manager.'];
            }

            if ($first[0] === '.') {
                $skipped[] = $name;

                continue;
            }

            if ($first === 'core') {
                // BepInEx's own files: never changed here.
                $skipped[] = $name;

                continue;
            }

            if (count($segments) === 1 && $entry['file'] && preg_match(self::IGNORED, $segments[0]) === 1) {
                $skipped[] = $name;

                continue;
            }

            if ($first === 'config') {
                $rest = array_slice($segments, 1);
                if ($rest === [] && ! $entry['file']) {
                    // The whole config folder: its contents are merged, one level down.
                    $moves[] = ['from' => $name, 'to' => 'config', 'dir' => true, 'merge' => true];
                } elseif ($rest !== []) {
                    $moves[] = ['from' => $name, 'to' => 'config/'.implode('/', $rest), 'dir' => ! $entry['file'], 'merge' => false];
                } else {
                    $skipped[] = $name;
                }

                continue;
            }

            if (in_array($first, self::OWNED, true)) {
                $rest = array_slice($segments, 1);
                if ($rest === []) {
                    // The folder itself becomes Owner-Name.
                    if ($entry['file']) {
                        $skipped[] = $name;
                    } else {
                        $moves[] = ['from' => $name, 'to' => $first.'/'.$folder, 'dir' => true, 'merge' => false];
                    }
                } else {
                    $moves[] = ['from' => $name, 'to' => $first.'/'.$folder.'/'.implode('/', $rest), 'dir' => ! $entry['file'], 'merge' => false];
                }

                continue;
            }

            // manifest.json, the .dll itself, or any other folder: next to the plugin.
            $moves[] = ['from' => $name, 'to' => 'plugins/'.$folder.'/'.implode('/', $segments), 'dir' => ! $entry['file'], 'merge' => false];
        }

        // A whole plugins/patchers/monomod folder first, so loose files land inside it.
        usort($moves, fn (array $a, array $b): int => self::rank($a) <=> self::rank($b));

        return [
            'moves' => $moves,
            'skipped' => $skipped,
            'error' => $moves !== [] ? null : 'The package has nothing to install on a server.',
        ];
    }

    /** @param array{to: string, dir: bool} $move */
    private static function rank(array $move): int
    {
        return $move['dir'] && preg_match('#^(plugins|patchers|monomod)/[^/]+$#D', $move['to']) === 1 ? 0 : 1;
    }

    /**
     * A name from the package as safe path segments. Backslashes split like slashes.
     * Null for anything that could leave the target folder.
     *
     * @return list<string>|null
     */
    public static function segments(string $name): ?array
    {
        if ($name === '' || strlen($name) > 255 || ! mb_check_encoding($name, 'UTF-8') || preg_match('/[\p{Cc}\p{Zl}\p{Zp}]/u', $name) === 1) {
            return null;
        }
        if (preg_match('#^([A-Za-z]:|[/\\\\])#', $name) === 1) {
            return null;
        }

        $segments = [];
        foreach (preg_split('#[/\\\\]+#', $name) ?: [] as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..' || str_contains($part, ':')) {
                return null;
            }
            $segments[] = $part;
        }

        return $segments === [] ? null : $segments;
    }
}
