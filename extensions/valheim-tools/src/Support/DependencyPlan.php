<?php

namespace ValheimTools\Support;

/**
 * Works out which Thunderstore packages to install for one mod: its dependencies first,
 * then the mod. Dependencies that are installed in the same or a newer version are
 * skipped, BepInExPack never installs, and a package that needs itself through other
 * packages is installed once. Deprecated dependencies are installed (the mod needs
 * them) with a note; a deprecated mod that was asked for is refused.
 */
final class DependencyPlan
{
    /**
     * @param  callable(string, string, ?string): array{namespace: string, name: string, version: string, dependencies: list<array{namespace: string, name: string, version: string}>, deprecated: bool}  $fetch  one package from Thunderstore
     * @param  array<string, string>  $installed  lowercase Owner-Name => version
     * @return array{plan: list<array<string, mixed>>, notes: list<string>}
     *
     * @throws \RuntimeException with a message for the person
     */
    public static function build(callable $fetch, string $namespace, string $name, ?string $version, array $installed, int $maxDepth, int $maxPackages): array
    {
        $plan = [];
        $notes = [];
        $stack = [];
        $keys = [];

        $resolve = function (string $namespace, string $name, ?string $version, bool $requested, int $depth) use (&$resolve, &$plan, &$notes, &$stack, &$keys, $fetch, $installed, $maxDepth, $maxPackages): void {
            $key = strtolower($namespace.'-'.$name);
            if (isset($keys[$key])) {
                return;
            }
            if (isset($stack[$key])) {
                // A needs B and B needs A: the one that is already being worked out installs once.
                $parent = (string) array_key_last($stack);
                $notes[] = $stack[$parent].' and '.$namespace.'-'.$name.' need each other. Each is installed once.';

                return;
            }
            if ($depth > $maxDepth) {
                throw new \RuntimeException('This mod needs too many layers of other mods.');
            }

            $package = $fetch($namespace, $name, $version);
            if ($package['deprecated'] && $requested) {
                throw new \RuntimeException($name.' is marked as deprecated on Thunderstore.');
            }

            $current = $installed[$key] ?? null;
            if (! $requested && $current !== null && Thunderstore::compareVersions($current, $package['version']) >= 0) {
                return;
            }
            if ($package['deprecated']) {
                $notes[] = $namespace.'-'.$name.' is marked as deprecated on Thunderstore. It is installed because another mod needs it.';
            }

            $stack[$key] = $namespace.'-'.$name;
            foreach ($package['dependencies'] as $dependency) {
                if (Thunderstore::isBepInEx($dependency['namespace'], $dependency['name'])) {
                    continue;
                }
                $resolve($dependency['namespace'], $dependency['name'], $dependency['version'], false, $depth + 1);
            }
            unset($stack[$key]);

            $keys[$key] = true;
            $plan[] = $package;
            if (count($plan) > $maxPackages) {
                throw new \RuntimeException('This mod needs more than '.$maxPackages.' other mods. Install them one at a time.');
            }
        };

        $resolve($namespace, $name, $version, true, 0);

        return ['plan' => $plan, 'notes' => array_values(array_unique($notes))];
    }
}
