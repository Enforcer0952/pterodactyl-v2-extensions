<?php

namespace PalworldTools\Support;

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

    /** A hash is a 40 character hex digest; an empty or odd one never counts as "unchanged". */
    public static function validHash(string $hash): bool
    {
        return preg_match('/^[0-9a-f]{40}$/D', $hash) === 1;
    }

    /**
     * Whether the file was changed by somebody else while the settings waited. Only a
     * whole-file replacement is checked: single edits are merged into what is on disk.
     */
    public static function changedWhileWaiting(?string $raw, ?string $queuedHash, string $currentHash): bool
    {
        return $raw !== null && $queuedHash !== null && $queuedHash !== $currentHash;
    }

    /**
     * An edit sent with the clear flag means an empty text (the panel turns an empty
     * string in a request into null, so the page sends a flag instead).
     *
     * @param  array{key: string, value: ?string, clear?: bool}  $edit
     * @return array{key: string, value: ?string}
     */
    public static function normalizeEdit(array $edit): array
    {
        $value = $edit['value'] ?? null;

        return ['key' => $edit['key'], 'value' => $value === null && ($edit['clear'] ?? false) ? '' : $value];
    }
}
