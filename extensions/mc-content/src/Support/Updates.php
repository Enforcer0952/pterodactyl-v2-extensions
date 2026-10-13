<?php

namespace McContent\Support;

/**
 * Decides which version, if any, counts as an update for an installed file.
 * Modrinth's bulk update lookup has no release-type filter and can answer with a
 * build for another Minecraft version when the server's version is unknown, so
 * every candidate is checked here.
 */
final class Updates
{
    /**
     * @param  array<string, mixed>|null  $installed  summary of the installed version, when known
     * @param  list<array<string, mixed>>  $candidates  version summaries, newest first
     * @return array<string, mixed>|null the version to offer, or null
     */
    public static function pick(?array $installed, array $candidates, bool $allowPrereleases, ?string $gameVersion): ?array
    {
        $installedDate = is_string($installed['date_published'] ?? null) ? strtotime($installed['date_published']) : null;
        $installedType = (string) ($installed['version_type'] ?? 'release');
        // Someone running a beta is fine with betas; everyone else only gets releases.
        $stableOnly = ! $allowPrereleases && $installedType === 'release';

        foreach ($candidates as $candidate) {
            if (($candidate['id'] ?? null) === ($installed['id'] ?? null)) {
                return null; // Reached the installed version: nothing newer qualifies.
            }
            if ($stableOnly && ($candidate['version_type'] ?? 'release') !== 'release') {
                continue;
            }
            if ($gameVersion !== null && ! in_array($gameVersion, (array) ($candidate['game_versions'] ?? []), true)) {
                continue;
            }
            $date = is_string($candidate['date_published'] ?? null) ? strtotime($candidate['date_published']) : null;
            // Only versions published after the installed one, never a backport.
            if ($installedDate !== null && ($date === null || $date <= $installedDate)) {
                continue;
            }

            return $candidate;
        }

        return null;
    }

    /** Whether the bulk lookup's answer can be used as is, without fetching the version list. */
    public static function acceptable(?array $installed, array $latest, bool $allowPrereleases, ?string $gameVersion): bool
    {
        return self::pick($installed, [$latest], $allowPrereleases, $gameVersion) !== null
            || ($latest['id'] ?? null) === ($installed['id'] ?? null);
    }
}
