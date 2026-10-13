<?php

namespace McVersion\Support;

/**
 * What a switch can break, worked out before anything is changed. "danger" and
 * "warning" need the user's confirmation; "info" is only shown.
 */
final class Warnings
{
    /**
     * @param  bool  $stableTarget  the chosen build is a release build
     * @return list<array{code: string, severity: string, message: string}>
     */
    public static function for(?string $fromSoftware, ?string $fromVersion, string $toSoftware, string $toVersion, bool $stableTarget = true): array
    {
        $warnings = [];
        $add = function (string $code, string $severity, string $message) use (&$warnings): void {
            $warnings[] = ['code' => $code, 'severity' => $severity, 'message' => $message];
        };

        $to = Software::label($toSoftware).' '.$toVersion;

        if ($fromSoftware === null || $fromVersion === null) {
            $add('unknown_current', 'info', 'The current software or version could not be detected, so nothing is compared. Make sure '.$to.' is what you want.');
        }

        if (Versions::isDowngrade($fromVersion, $toVersion)) {
            $add('downgrade', 'danger', 'This is a downgrade from '.$fromVersion.' to '.$toVersion.'. A world saved by a newer version often cannot be opened by an older one, and chunks that can may be damaged. Restore a backup of the world from that version, or expect to lose it.');
        } elseif ($fromVersion !== null && (Versions::compare($toVersion, $fromVersion) ?? 0) > 0) {
            $add('upgrade', 'info', 'The world and plugin data are converted to '.$toVersion.' on the first start and cannot be converted back.');
        }

        $before = Software::family($fromSoftware);
        $after = Software::family($toSoftware);
        if ($before !== null && $after !== null && $before !== $after) {
            $message = match ($before.'>'.$after) {
                'plugins>mods' => 'Plugins do not run on '.Software::label($toSoftware).', and mods do not run on '.Software::label($fromSoftware).'. Your plugins stay in the plugins folder but will not load, and you need mods built for '.Software::label($toSoftware).'.',
                'mods>plugins' => 'Mods do not run on '.Software::label($toSoftware).'. Your mods stay in the mods folder but will not load, and you need plugins built for '.Software::label($toSoftware).'. Worlds that use modded blocks or items lose them.',
                'vanilla>plugins', 'vanilla>mods' => Software::label($toSoftware).' loads '.($after === 'mods' ? 'mods' : 'plugins').' from its own folder. Worlds work as they are, but the server creates its own config files on the first start.',
                default => Software::label($toSoftware).' cannot load '.($before === 'mods' ? 'mods' : 'plugins').'. Yours stay in their folder but will not load, and worlds that use content they added lose it.',
            };
            $add('family_switch', $before === 'vanilla' ? 'warning' : 'danger', $message);
        } elseif ($fromSoftware !== null && $fromSoftware !== $toSoftware) {
            $add('fork_switch', 'info', Software::label($toSoftware).' reads the same plugins and worlds as '.Software::label($fromSoftware).'. It adds its own config files on the first start.');
        }

        if (! $stableTarget) {
            $add('experimental', 'warning', $to.' is a snapshot or an experimental build. It can crash, and a world opened by it may not open in a release again.');
        }

        if ($fromSoftware === $toSoftware && $fromVersion === $toVersion) {
            $add('same', 'info', 'The server already runs '.$to.'. This downloads the newest build again.');
        }

        return $warnings;
    }

    /**
     * @param  list<array{code: string, severity: string, message: string}>  $warnings
     */
    public static function needsConfirmation(array $warnings): bool
    {
        foreach ($warnings as $warning) {
            if ($warning['severity'] !== 'info') {
                return true;
            }
        }

        return false;
    }
}
