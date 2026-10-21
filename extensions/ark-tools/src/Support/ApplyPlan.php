<?php

namespace ArkTools\Support;

/**
 * Small decisions around settings that wait for a server to stop. Kept free of
 * the panel so they can be tested on their own.
 */
final class ApplyPlan
{
    /**
     * Whether the server is started again after the write. Settings that already
     * wait keep their wish for a start, even when the server is stopping by now.
     */
    public static function startAfter(?bool $pending, string $state): bool
    {
        return ($pending ?? false) || $state === 'running';
    }

    /**
     * Whether every hash is a 40 character hex digest, and there is at least one.
     *
     * @param  array<mixed>  $hashes
     */
    public static function validHashes(array $hashes): bool
    {
        if ($hashes === []) {
            return false;
        }
        foreach ($hashes as $file => $hash) {
            if (! is_string($file) || $file === '' || ! is_string($hash) || preg_match('/^[0-9a-f]{40}$/D', $hash) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * Files whose contents no longer match the hash the settings were queued with.
     *
     * @param  array<string, ?string>  $hashes  file name => hash when queued
     * @param  array<string, ?string>  $current  file name => current hash
     * @return list<string>
     */
    public static function changedFiles(array $hashes, array $current): array
    {
        $changed = [];
        foreach ($hashes as $file => $hash) {
            if (array_key_exists($file, $current) && $current[$file] !== $hash) {
                $changed[] = (string) $file;
            }
        }

        return $changed;
    }

    /**
     * The hashes worth checking when the settings are written: whole files that are
     * replaced would lose somebody else's change, while single edits are merged into
     * whatever is on disk. Files the game rewrites on its own are left out.
     *
     * @param  array<string, string>  $hashes
     * @param  list<string|int>  $replaced  files that are replaced as a whole
     * @param  list<string>  $ignore
     * @return array<string, string>
     */
    public static function guarded(array $hashes, array $replaced, array $ignore): array
    {
        return array_filter(
            $hashes,
            fn (string $file): bool => in_array($file, $replaced, true) && ! in_array($file, $ignore, true),
            ARRAY_FILTER_USE_KEY,
        );
    }
}
