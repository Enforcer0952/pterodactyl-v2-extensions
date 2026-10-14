<?php

namespace PalworldTools\Support;

/**
 * Which settings the startup command wins over the file for. On the common Palworld egg
 * a parser tool rewrites PalWorldSettings.ini from startup variables on every start
 * (SERVER_NAME, ADMIN_PASSWORD, ...), and the command line itself passes -servername,
 * -players, -adminpassword and more. An edit to the file alone would be undone, so the
 * Settings page changes the startup variable instead, or says why it cannot.
 *
 * Variable names: github.com/pelican-eggs/games-steamcmd (palworld) and its config parser tool.
 */
final class StartupOverrides
{
    /** Command line arguments that override a file setting. */
    private const array ARGUMENTS = [
        'servername' => 'ServerName',
        'players' => 'ServerPlayerMaxNum',
        'adminpassword' => 'AdminPassword',
        'serverpassword' => 'ServerPassword',
        'publicport' => 'PublicPort',
        'publicip' => 'PublicIP',
        'logformat' => 'LogFormatType',
    ];

    /** Variables the panel itself provides, so they are not startup variables a user can edit. */
    private const array BUILT_IN = ['SERVER_PORT', 'SERVER_IP', 'SERVER_MEMORY'];

    public static function usesParser(string $startup): bool
    {
        return stripos($startup, 'PalworldServerConfigParser') !== false;
    }

    /**
     * @param  array<string, string>  $envBySetting  setting key => environment variable name (from the schema)
     * @param  list<string>  $variables  startup variables the user can see
     * @return array<string, array{kind: string, env: ?string}> setting key => who decides it.
     *                                                         kind is "variable", "allocation" or "startup"
     */
    public static function locks(string $startup, array $envBySetting, array $variables): array
    {
        $locks = [];
        $visible = array_flip($variables);

        foreach (self::ARGUMENTS as $argument => $key) {
            if (preg_match('/(?<![A-Za-z0-9])-'.$argument.'=(.{0,120})/i', $startup, $match) !== 1) {
                continue;
            }
            $env = null;
            if (preg_match('/\{\{([A-Z0-9_]+)\}\}|\$\{([A-Z0-9_]+)\}/', $match[1], $name) === 1) {
                $env = $name[1] !== '' ? $name[1] : $name[2];
            }
            $locks[$key] = self::classify($env, $visible);
        }

        if (self::usesParser($startup)) {
            foreach ($envBySetting as $key => $env) {
                if (isset($locks[$key])) {
                    continue;
                }
                if (isset($visible[$env])) {
                    $locks[$key] = ['kind' => 'variable', 'env' => $env];
                } elseif (in_array($env, self::BUILT_IN, true)) {
                    $locks[$key] = ['kind' => 'allocation', 'env' => $env];
                }
            }
        }

        return $locks;
    }

    /**
     * @param  array<string, int>  $visible
     * @return array{kind: string, env: ?string}
     */
    private static function classify(?string $env, array $visible): array
    {
        if ($env !== null && isset($visible[$env])) {
            return ['kind' => 'variable', 'env' => $env];
        }
        if ($env !== null && in_array($env, self::BUILT_IN, true)) {
            return ['kind' => 'allocation', 'env' => $env];
        }

        return ['kind' => 'startup', 'env' => null];
    }
}
