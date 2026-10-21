<?php

namespace McContent\Support;

/**
 * Rules for the file names of jars this extension writes, replaces or deletes. Normal
 * names from Modrinth ("Plugin-1.0+mc1.21.jar", "My%20Mod.jar", "[1.21] Thing.jar")
 * are accepted as they are; anything that could leave the folder is not.
 */
final class JarName
{
    private const int MAX_LENGTH = 200;

    /** A plain jar name, optionally with the ".disabled" suffix. */
    public static function isSafe(string $name, bool $allowDisabled = true): bool
    {
        $pattern = $allowDisabled ? '/\.jar(\.disabled)?$/Di' : '/\.jar$/Di';

        if ($name === '' || mb_strlen($name) > self::MAX_LENGTH || preg_match($pattern, $name) !== 1) {
            return false;
        }

        // No separators, control or line characters, no ".." and no hidden files.
        if (preg_match('/[\/\\\\\x{0}-\x{1F}\x{7F}\p{Cc}\p{Cf}\p{Zl}\p{Zp}:*?"<>|]/u', $name) === 1 || ! mb_check_encoding($name, 'UTF-8')) {
            return false;
        }

        return ! str_contains($name, '..') && $name[0] !== '.' && trim($name) === $name;
    }

    /**
     * A safe name for a file called $name by the download source, falling back to the
     * version id when nothing usable is left.
     */
    public static function normalize(string $name, string $fallback): string
    {
        $name = str_replace('\\', '/', $name);
        $name = basename($name);

        if (self::isSafe($name, false)) {
            return $name;
        }

        $stem = (string) preg_replace('/\.jar$/i', '', $name);
        $stem = (string) preg_replace('/[^A-Za-z0-9._+\-]+/', '_', $stem);
        $stem = trim(str_replace('..', '.', $stem), '._');
        $stem = mb_substr($stem, 0, self::MAX_LENGTH - 4);
        $fallback = (string) preg_replace('/[^A-Za-z0-9]/', '', $fallback);

        return ($stem !== '' ? $stem : ($fallback !== '' ? $fallback : 'download')).'.jar';
    }
}
