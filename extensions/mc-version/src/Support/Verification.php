<?php

namespace McVersion\Support;

/**
 * Checks on a downloaded or stored server jar that need no node: how big it must be,
 * whether it starts like a zip archive, and what the plan can promise about it.
 */
final class Verification
{
    /** Anything smaller is an error page or a cut-off download, not a server jar. */
    public const int MIN_JAR_BYTES = 10_000;

    /** Jars up to this size are read back to check their header and checksum. */
    public const int READ_MAX_BYTES = 24 * 1024 * 1024;

    /**
     * Why a jar of this size cannot be right, or null.
     *
     * @param  ?int  $expected  the size the source (or its Content-Length) announced
     */
    public static function sizeProblem(int $actual, ?int $expected): ?string
    {
        if ($actual < self::MIN_JAR_BYTES) {
            return 'The file is only '.$actual.' bytes, so it is not a server jar.';
        }
        if ($expected !== null && $expected > 0 && $actual !== $expected) {
            return 'The file is '.$actual.' bytes but should be '.$expected.'.';
        }

        return null;
    }

    /** Why this content is not a jar (a zip archive starts with "PK\x03\x04"), or null. */
    public static function headerProblem(string $content): ?string
    {
        return str_starts_with($content, "PK\x03\x04") ? null : 'The file is not a jar.';
    }

    /**
     * What a download is checked for, in words for the plan.
     *
     * @param  ?int  $size  the size the source announces, null when it does not
     * @return array{content: bool, note: string}
     */
    public static function describe(?int $size, bool $hasChecksum): array
    {
        if ($size !== null && $size > self::READ_MAX_BYTES) {
            return [
                'content' => false,
                'note' => 'Not verified: this jar is too large to read back, so only its size is checked. The download is over HTTPS from the project\'s own host.',
            ];
        }

        return [
            'content' => true,
            'note' => $hasChecksum
                ? 'After the download the size, the jar header and the checksum are checked.'
                : 'After the download the size and the jar header are checked. There is no published checksum.',
        ];
    }

    /**
     * The Content-Length of a HEAD response, or null when it is missing or not a plain number.
     *
     * @param  array<string, mixed>  $headers  header name => value or list of values
     */
    public static function contentLength(array $headers): ?int
    {
        foreach ($headers as $name => $value) {
            if (strtolower((string) $name) !== 'content-length') {
                continue;
            }
            $value = is_array($value) ? ($value[0] ?? null) : $value;

            return is_string($value) && preg_match('/^\d{1,12}$/D', $value) === 1 && (int) $value > 0 ? (int) $value : null;
        }

        return null;
    }

    /**
     * Whether a switch must not start before the user says the world is backed up: a
     * downgrade, or a move between plugins and mods.
     *
     * @param  list<array{code: string, severity: string, message: string}>  $warnings
     */
    public static function needsBackup(array $warnings): bool
    {
        foreach ($warnings as $warning) {
            if ($warning['code'] === 'downgrade' || ($warning['code'] === 'family_switch' && $warning['severity'] === 'danger')) {
                return true;
            }
        }

        return false;
    }
}
