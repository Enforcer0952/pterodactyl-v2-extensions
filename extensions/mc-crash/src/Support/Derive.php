<?php

namespace McCrash\Support;

/**
 * Small calculations a known-pattern rule needs after its regex matched: class file version to Java version,
 * the dependency named in a Fabric message, the memory hint for an OutOfMemoryError. They only add variables
 * that the rule's verdict and fix text can use.
 */
final class Derive
{
    /**
     * @param  array<string, string>  $vars
     * @return array<string, string>
     */
    public static function apply(string $name, array $vars): array
    {
        return match ($name) {
            'classfile' => self::classFile($vars),
            'fabric-requires' => self::fabricRequires($vars),
            'forge-actual' => self::forgeActual($vars),
            'api-version' => self::apiVersion($vars),
            'mixin' => self::mixin($vars),
            'oom' => self::oom($vars),
            'chunk' => self::chunk($vars),
            'watchdog' => self::watchdog($vars),
            'ticking' => self::ticking($vars),
            'jvm' => self::jvm($vars),
            'plugin-exception' => self::pluginException($vars),
            'bind' => self::bind($vars),
            'missing-class' => self::missingClass($vars),
            default => $vars,
        };
    }

    /** Class file major version 52 is Java 8, 61 is Java 17, 65 is Java 21. */
    public static function javaForClassFile(int $major): ?int
    {
        return $major >= 45 && $major <= 120 ? $major - 44 : null;
    }

    /**
     * @param  array<string, string>  $vars
     * @return array<string, string>
     */
    private static function classFile(array $vars): array
    {
        $need = self::javaForClassFile((int) ($vars['major'] ?? 0));
        $have = isset($vars['have']) ? self::javaForClassFile((int) $vars['have']) : null;

        $vars['java'] = $need === null ? 'a newer version of' : (string) $need;
        $vars['have_java'] = $have === null ? '' : (string) $have;
        $vars['running'] = $have === null ? '' : ', the server runs Java '.$have;
        if (isset($vars['class'])) {
            $vars['class'] = str_replace('/', '.', $vars['class']);
        }

        return $vars;
    }

    /**
     * @param  array<string, string>  $vars
     * @return array<string, string>
     */
    private static function fabricRequires(array $vars): array
    {
        if (empty($vars['dep']) && ! empty($vars['req'])) {
            // "any version of fabric-api (fabric-api)", "version 0.5.3 or later of mod sodium", "fabric-api".
            if (preg_match('/(?:^|\s)(?:mod\s+)?([A-Za-z0-9_\-.]+)(?:\s+\(([\w\-.]+)\))?\s*$/', $vars['req'], $m) === 1) {
                $vars['dep'] = $m[2] ?? $m[1];
            }
        }
        $vars['dep'] ??= 'the mod it asks for';
        $vars['depver'] = isset($vars['depver']) && $vars['depver'] !== '' ? ' ('.trim($vars['depver']).')' : '';

        return $vars;
    }

    /**
     * @param  array<string, string>  $vars
     * @return array<string, string>
     */
    private static function forgeActual(array $vars): array
    {
        $actual = $vars['actual'] ?? ($vars['current'] ?? '[MISSING]');
        $missing = $actual === '[MISSING]' || $actual === '' || str_contains($actual, 'not installed');
        $vars['state'] = $missing ? 'it is not installed' : 'the installed version ('.trim($actual, '[]').') does not fit';
        $vars['range'] = isset($vars['range']) && $vars['range'] !== '' ? ' '.$vars['range'] : '';
        $vars['name'] ??= $vars['mod'] ?? 'A mod';
        $vars['mod'] ??= $vars['name'];

        return $vars;
    }

    /**
     * @param  array<string, string>  $vars
     * @return array<string, string>
     */
    private static function apiVersion(array $vars): array
    {
        $who = $vars['plugin'] ?? ($vars['jar'] ?? '');
        $vars['who'] = $who !== '' ? $who : 'A plugin';
        $vars['api'] = $vars['api'] ?? '';

        return $vars;
    }

    /**
     * @param  array<string, string>  $vars
     * @return array<string, string>
     */
    private static function mixin(array $vars): array
    {
        $target = $vars['target'] ?? '';
        $vars['in_target'] = $target !== '' ? ' in '.Text::simpleName($target) : '';

        $config = $vars['config'] ?? ($vars['cfg2'] ?? '');
        if ($config !== '') {
            $name = (string) preg_replace('/(\.mixins?)?\.json$/i', '', $config);
            $name = (string) preg_replace('/^mixins?\./i', '', $name);
            $vars['config_mod'] = $name;
        }
        $vars['who'] = $vars['from'] ?? ($vars['mod'] ?? ($vars['config_mod'] ?? 'A mod'));

        return $vars;
    }

    /**
     * @param  array<string, string>  $vars
     * @return array<string, string>
     */
    private static function oom(array $vars): array
    {
        $kind = $vars['kind'] ?? 'native memory';
        $lower = strtolower($kind);
        $vars['kind'] = $kind;

        if (str_contains($lower, 'metaspace') || str_contains($lower, 'compressed class')) {
            $vars['hint'] = 'Too many classes were loaded. Restart the server regularly, and remove plugins that reload other plugins or themselves.';
        } elseif (str_contains($lower, 'native thread')) {
            $vars['hint'] = 'The server hit a limit on threads or has no memory left for them. Give it more RAM and remove plugins that start many threads.';
        } elseif (str_contains($lower, 'direct buffer')) {
            $vars['hint'] = 'Netty or a plugin used up off-heap memory. Give the server more RAM and keep the Java heap (Xmx) at most 80 percent of it.';
        } elseif ($kind === 'native memory') {
            $vars['hint'] = 'The server has less memory than Java was told to use. Set the Java heap (the MEMORY or Xmx startup value) clearly below the memory limit of this server, because Java needs extra memory beyond the heap.';
        } else {
            $vars['hint'] = 'Give the server more RAM, or lower view-distance and simulation-distance in server.properties. Remove plugins or mods that keep a lot of data in memory.';
        }

        return $vars;
    }

    /**
     * @param  array<string, string>  $vars
     * @return array<string, string>
     */
    private static function chunk(array $vars): array
    {
        $region = $vars['region'] ?? '';
        $where = '';
        $hint = 'the region file that holds the broken chunk';

        if ($region !== '') {
            $where = ' ('.$region.')';
            $hint = 'world/region/'.$region.' (the Nether and End have their own region folders)';
        } elseif (isset($vars['cx'], $vars['cz'])) {
            $x = (int) $vars['cx'] * 16;
            $z = (int) $vars['cz'] * 16;
            $where = ' near X '.$x.', Z '.$z;
            $rx = (int) floor((int) $vars['cx'] / 32);
            $rz = (int) floor((int) $vars['cz'] / 32);
            $hint = 'world/region/r.'.$rx.'.'.$rz.'.mca';
        }

        $vars['where'] = $where;
        $vars['region_hint'] = $hint;

        return $vars;
    }

    /**
     * @param  array<string, string>  $vars
     * @return array<string, string>
     */
    private static function watchdog(array $vars): array
    {
        $vars['took'] = isset($vars['secs']) && $vars['secs'] !== '' ? ' (a tick took '.$vars['secs'].' seconds)' : '';

        return $vars;
    }

    /**
     * @param  array<string, string>  $vars
     * @return array<string, string>
     */
    private static function ticking(array $vars): array
    {
        $details = '';
        if (! empty($vars['etype'])) {
            $details = $vars['etype'];
            if (isset($vars['x'], $vars['z'])) {
                $details .= ' at '.(int) floor((float) $vars['x']).', '.(int) floor((float) ($vars['y'] ?? 0)).', '.(int) floor((float) $vars['z']);
            }
        } elseif (! empty($vars['btype'])) {
            $details = $vars['btype'];
            if (isset($vars['bx'], $vars['bz'])) {
                $details .= ' at '.$vars['bx'].', '.($vars['by'] ?? '?').', '.$vars['bz'];
            }
        }
        $vars['details'] = $details !== '' ? ' ('.$details.')' : '';
        $vars['what'] = isset($vars['what']) ? strtolower($vars['what']) : 'entity';
        $vars['what_a'] = ($vars['what'] === 'entity' ? 'an ' : 'a ').$vars['what'];

        return $vars;
    }

    /**
     * @param  array<string, string>  $vars
     * @return array<string, string>
     */
    private static function jvm(array $vars): array
    {
        $frame = $vars['frame'] ?? '';
        $short = $frame;
        if (preg_match('/\[([^\]+\s]+)(?:\+0x[0-9a-f]+)?\]/i', $frame, $m) === 1) {
            $short = $m[1];
        } elseif (preg_match('/^([\w$.]+)\(/', $frame, $m) === 1) {
            $short = $m[1];
        }
        $vars['frame_short'] = $short !== '' ? $short : 'native code';
        $vars['sig'] ??= 'fatal error';

        return $vars;
    }

    /**
     * @param  array<string, string>  $vars
     * @return array<string, string>
     */
    private static function pluginException(array $vars): array
    {
        $vars['event_text'] = isset($vars['event']) && $vars['event'] !== '' ? ' while handling '.$vars['event'] : (isset($vars['task']) && $vars['task'] !== '' ? ' in a scheduled task' : '');

        return $vars;
    }

    /**
     * @param  array<string, string>  $vars
     * @return array<string, string>
     */
    private static function bind(array $vars): array
    {
        $vars['port_text'] = isset($vars['port']) && $vars['port'] !== '' ? ' (port '.$vars['port'].')' : '';

        return $vars;
    }

    /**
     * @param  array<string, string>  $vars
     * @return array<string, string>
     */
    private static function missingClass(array $vars): array
    {
        $class = str_replace('/', '.', $vars['class'] ?? '');
        $vars['class'] = $class;
        $vars['class_short'] = $class !== '' ? Text::simpleName($class) : 'a class';

        return $vars;
    }
}
