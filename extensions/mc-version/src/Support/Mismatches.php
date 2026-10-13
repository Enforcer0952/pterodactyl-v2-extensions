<?php

namespace McVersion\Support;

/**
 * Differences between what a server is set up for (its egg and startup variables) and
 * what its files say it runs. Each one is a plain sentence for the page.
 */
final class Mismatches
{
    /**
     * @param  ?string  $egg  the software the egg is made for, if it says
     * @param  array{software: ?string, version: ?string}  $detected
     * @param  array<string, ?string>  $variables  startup variable values by name
     * @return list<array{code: string, severity: string, message: string}>
     */
    public static function find(?string $egg, array $detected, array $variables, bool $jarFound, string $jar): array
    {
        $found = [];

        if (! $jarFound) {
            $found[] = ['code' => 'jar_missing', 'severity' => 'warning', 'message' => $jar.' was not found in the server files. Switching version downloads it again.'];
        }

        $software = $detected['software'];
        if ($egg !== null && $software !== null && $egg !== $software) {
            $sameFamily = Software::family($egg) === 'plugins' && Software::family($software) === 'plugins';
            if (! $sameFamily) {
                $found[] = [
                    'code' => 'egg_software',
                    'severity' => 'warning',
                    'message' => 'The files look like '.Software::label($software).', but this server\'s egg is made for '.Software::label($egg).'. Reinstalling the server from the egg would put '.Software::label($egg).' back.',
                ];
            }
        }

        $configured = $variables['MINECRAFT_VERSION'] ?? null;
        $version = $detected['version'];
        if ($configured !== null && $version !== null && self::isConcrete($configured) && $configured !== $version) {
            $found[] = [
                'code' => 'version_variable',
                'severity' => 'warning',
                'message' => 'MINECRAFT_VERSION says '.$configured.', but the server last ran '.$version.'. Switching version below sets the variable to the version you pick.',
            ];
        }

        return $found;
    }

    /** "latest" and empty values do not name a version. */
    private static function isConcrete(string $value): bool
    {
        return Versions::isValid(trim($value));
    }
}
