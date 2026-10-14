<?php

namespace McCrash\Support;

/**
 * Decides which plugin or mod is behind a problem, and how sure that is.
 *
 * A rule lists strategies, tried in order: "class:var" (the class held in a regex variable),
 * "plugin:var", "mod:var", "jar:var", "trace" (the stack trace), and "~mod:var" for a name that is only
 * derived from a file name, which can never be better than "likely".
 */
final class Culprit
{
    /**
     * @param  list<string>  $strategies
     * @param  array<string, string>  $vars
     * @param  list<array<string, mixed>>  $chains
     * @param  array{suspected?: list<string>, tag?: ?string}  $extra
     * @return array{tier: string, jars: list<array<string, mixed>>, basis: string}|null
     */
    public static function resolve(array $strategies, array $vars, array $chains, JarMap $map, array $extra = []): ?array
    {
        foreach ($strategies as $strategy) {
            $found = self::strategy($strategy, $vars, $chains, $map);
            if ($found !== null) {
                return $found;
            }
        }

        if ($strategies === []) {
            return null;
        }

        // The loader's own guess, then the plugin that wrote the log line.
        foreach (($extra['suspected'] ?? []) as $id) {
            $found = $map->forName($id, 'mod');
            if ($found !== null) {
                return self::guess($found, 'The mod loader lists this mod as suspected. This is a guess.');
            }
        }

        $tag = $extra['tag'] ?? null;
        if ($tag !== null && $tag !== '') {
            $found = $map->forName($tag, 'plugin');
            if ($found !== null) {
                return self::guess($found, 'The message was logged by '.$tag.'. That does not prove it caused the problem.');
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $vars
     * @param  list<array<string, mixed>>  $chains
     * @return array{tier: string, jars: list<array<string, mixed>>, basis: string}|null
     */
    private static function strategy(string $strategy, array $vars, array $chains, JarMap $map): ?array
    {
        if ($strategy === 'trace') {
            return self::fromTrace($chains, $map);
        }

        $weak = str_starts_with($strategy, '~');
        $strategy = ltrim($strategy, '~');
        if (! str_contains($strategy, ':')) {
            return null;
        }

        [$kind, $var] = explode(':', $strategy, 2);
        $value = $vars[$var] ?? '';
        if ($value === '') {
            return null;
        }

        $found = match ($kind) {
            'class' => Frames::isPlatform($value) ? null : $map->forClass($value),
            'plugin' => $map->forName($value, 'plugin'),
            'mod' => $map->forName($value, 'mod'),
            'jar' => $map->forJarFile($value),
            default => null,
        };

        if ($found === null) {
            return null;
        }

        return $weak ? self::guess($found, 'The mixin file is called '.$value.', which is usually the mod id. This is a guess.') : $found;
    }

    /**
     * Frames are tried from the root cause outwards. A jar named in the trace or the only jar holding a
     * class wins over a guess, even when the guess sits higher in the trace.
     *
     * @param  list<array<string, mixed>>  $chains
     * @return array{tier: string, jars: list<array<string, mixed>>, basis: string}|null
     */
    public static function fromTrace(array $chains, JarMap $map): ?array
    {
        $likely = null;

        foreach ($chains as $chain) {
            foreach (TraceParser::rootFirst($chain) as $node) {
                foreach ($node['frames'] as $frame) {
                    // Java, the game and the loaders are skipped even when the trace names their jar.
                    if (! Frames::isInteresting((string) $frame['class'])) {
                        continue;
                    }

                    $found = ! empty($frame['jar']) ? $map->forJarFile((string) $frame['jar']) : $map->forClass((string) $frame['class']);
                    if ($found === null) {
                        continue;
                    }
                    if ($found['tier'] === 'confirmed') {
                        return $found;
                    }
                    $likely ??= $found;
                }
            }
        }

        return $likely;
    }

    /**
     * @param  array{tier: string, jars: list<array<string, mixed>>, basis: string}  $found
     * @return array{tier: string, jars: list<array<string, mixed>>, basis: string}
     */
    private static function guess(array $found, string $basis): array
    {
        return ['tier' => 'likely', 'jars' => $found['jars'], 'basis' => $basis];
    }
}
