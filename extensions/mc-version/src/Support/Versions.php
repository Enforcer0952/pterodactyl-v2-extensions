<?php

namespace McVersion\Support;

/**
 * Minecraft version ids: releases (1.21.4, 26.1.2), pre-releases (1.21.11-rc3,
 * 26.4-snapshot-4) and the older weekly snapshots (24w14a). Ordered by hand,
 * because PHP's version_compare() does not know that "rc" comes before the release.
 */
final class Versions
{
    /** A release: only numbers, such as 1.21.4 or 26.1. */
    private const string RELEASE = '/^(\d{1,3})\.(\d{1,3})(?:\.(\d{1,3}))?$/D';

    /** Anything this page accepts as a version id from a user or an API. */
    private const string VALID = '/^(?:\d{1,3}\.\d{1,3}(?:\.\d{1,3})?(?:-[A-Za-z0-9.]{1,24}(?:-[A-Za-z0-9.]{1,24})?)?|\d{2}w\d{2}[a-z])$/D';

    private const string PRE = '/^(\d{1,3})\.(\d{1,3})(?:\.(\d{1,3}))?-(snapshot|pre|rc)-?(\d{1,3})$/D';

    private const string WEEKLY = '/^(\d{2})w(\d{2})([a-z])$/D';

    public static function isValid(string $version): bool
    {
        return strlen($version) <= 40 && preg_match(self::VALID, $version) === 1;
    }

    /** A plain release, not a snapshot, pre-release or release candidate. */
    public static function isRelease(string $version): bool
    {
        return preg_match(self::RELEASE, $version) === 1;
    }

    /**
     * The numbers of a version, with the pre-release kind: [major, minor, patch, rank].
     * The rank puts snapshots before pre-releases before candidates before the release.
     * Weekly snapshots return null: they cannot be placed against numbered versions.
     *
     * @return array{0: int, 1: int, 2: int, 3: array{0: int, 1: int}}|null
     */
    public static function parse(string $version): ?array
    {
        if (preg_match(self::RELEASE, $version, $match) === 1) {
            return [(int) $match[1], (int) $match[2], (int) ($match[3] ?? 0), [3, 0]];
        }

        if (preg_match(self::PRE, $version, $match) === 1) {
            $kind = ['snapshot' => 0, 'pre' => 1, 'rc' => 2][$match[4]];

            return [(int) $match[1], (int) $match[2], (int) ($match[3] === '' ? 0 : $match[3]), [$kind, (int) $match[5]]];
        }

        return null;
    }

    /** The release a pre-release belongs to: "1.21.11-rc3" gives "1.21.11". Null when unknown. */
    public static function base(string $version): ?string
    {
        $parsed = self::parse($version);

        return $parsed === null ? null : $parsed[0].'.'.$parsed[1].'.'.$parsed[2];
    }

    /**
     * Negative when $a is older than $b, positive when newer, 0 when equal; null when
     * the two cannot be compared (a weekly snapshot against anything but another one).
     */
    public static function compare(string $a, string $b): ?int
    {
        $left = self::parse($a);
        $right = self::parse($b);

        if ($left !== null && $right !== null) {
            return [$left[0], $left[1], $left[2], $left[3][0], $left[3][1]] <=> [$right[0], $right[1], $right[2], $right[3][0], $right[3][1]];
        }

        if (preg_match(self::WEEKLY, $a, $x) === 1 && preg_match(self::WEEKLY, $b, $y) === 1) {
            return [(int) $x[1], (int) $x[2], $x[3]] <=> [(int) $y[1], (int) $y[2], $y[3]];
        }

        return $a === $b ? 0 : null;
    }

    /** True only when both versions are known and $to is older than $from. */
    public static function isDowngrade(?string $from, string $to): bool
    {
        if ($from === null) {
            return false;
        }

        $order = self::compare($to, $from);

        return $order !== null && $order < 0;
    }

    /**
     * Newest first. Versions that cannot be compared keep their place relative to
     * each other, after the ones that can.
     *
     * @param  list<string>  $versions
     * @return list<string>
     */
    public static function sortNewestFirst(array $versions): array
    {
        $indexed = array_map(null, array_keys($versions), $versions);
        usort($indexed, function (array $a, array $b): int {
            $order = self::compare($b[1], $a[1]);
            if ($order === null) {
                $left = self::parse($a[1]) !== null;
                $right = self::parse($b[1]) !== null;

                return $left === $right ? $a[0] <=> $b[0] : ($left ? -1 : 1);
            }

            return $order ?: $a[0] <=> $b[0];
        });

        return array_values(array_map(fn (array $pair): string => $pair[1], $indexed));
    }

    /**
     * The Java release a server needs to run this version, from the version's own rules:
     * preferred majors in order of preference, and the oldest one that still works.
     *
     * @return array{preferred: list<int>, minimum: int}|null null for ids that are not understood
     */
    public static function javaRequirement(string $version): ?array
    {
        if (preg_match(self::WEEKLY, $version, $weekly) === 1) {
            $key = (int) $weekly[1] * 100 + (int) $weekly[2];

            return match (true) {
                $key >= 2414 => ['preferred' => [21], 'minimum' => 21],
                $key >= 2144 => ['preferred' => [17], 'minimum' => 17],
                $key >= 2119 => ['preferred' => [16], 'minimum' => 16],
                default => ['preferred' => [11, 8], 'minimum' => 8],
            };
        }

        $parsed = self::parse($version);
        if ($parsed === null) {
            return null;
        }

        [$major, $minor, $patch] = $parsed;

        if ($major >= 26) {
            return ['preferred' => [25], 'minimum' => 25];
        }
        if ($major !== 1) {
            return null;
        }

        return match (true) {
            $minor <= 16 => ['preferred' => [11, 8], 'minimum' => 8],
            $minor === 17 => ['preferred' => [16], 'minimum' => 16],
            $minor <= 19, $minor === 20 && $patch <= 4 => ['preferred' => [17], 'minimum' => 17],
            default => ['preferred' => [21], 'minimum' => 21],
        };
    }
}
