<?php

use PHPUnit\Framework\TestCase;
use ValheimTools\Support\DependencyPlan;

class DependencyPlanTest extends TestCase
{
    /**
     * @param  array<string, array{deps?: list<string>, version?: string, deprecated?: bool}>  $packages  "Owner-Name" => details
     */
    private function fetcher(array $packages, ?array &$calls = null): callable
    {
        $calls = [];

        return function (string $namespace, string $name, ?string $version) use ($packages, &$calls): array {
            $key = $namespace.'-'.$name;
            $calls[] = $key;
            if (! isset($packages[$key])) {
                throw new \RuntimeException('No such package '.$key);
            }
            $info = $packages[$key];
            $dependencies = [];
            foreach ($info['deps'] ?? [] as $dependency) {
                [$ns, $n] = explode('-', $dependency);
                $dependencies[] = ['namespace' => $ns, 'name' => $n, 'version' => '1.0.0'];
            }

            return [
                'namespace' => $namespace,
                'name' => $name,
                'version' => $info['version'] ?? '1.0.0',
                'dependencies' => $dependencies,
                'deprecated' => $info['deprecated'] ?? false,
            ];
        };
    }

    /** @return list<string> */
    private function names(array $result): array
    {
        return array_map(fn (array $p): string => $p['namespace'].'-'.$p['name'], $result['plan']);
    }

    public function test_dependencies_come_before_the_mod(): void
    {
        $result = DependencyPlan::build($this->fetcher([
            'A-Mod' => ['deps' => ['B-Lib', 'C-Lib']],
            'B-Lib' => ['deps' => ['C-Lib']],
            'C-Lib' => [],
        ]), 'A', 'Mod', null, [], 6, 25);

        $this->assertSame(['C-Lib', 'B-Lib', 'A-Mod'], $this->names($result));
        $this->assertSame([], $result['notes']);
    }

    public function test_bepinex_is_never_planned(): void
    {
        $result = DependencyPlan::build($this->fetcher([
            'A-Mod' => ['deps' => ['denikson-BepInExPack_Valheim']],
        ]), 'A', 'Mod', null, [], 6, 25);

        $this->assertSame(['A-Mod'], $this->names($result));
    }

    public function test_installed_dependencies_are_skipped_but_the_requested_mod_updates(): void
    {
        $result = DependencyPlan::build($this->fetcher([
            'A-Mod' => ['deps' => ['B-Lib'], 'version' => '2.0.0'],
            'B-Lib' => ['version' => '1.0.0'],
        ]), 'A', 'Mod', null, ['a-mod' => '1.0.0', 'b-lib' => '1.0.0'], 6, 25);

        $this->assertSame(['A-Mod'], $this->names($result));
    }

    public function test_a_two_mod_cycle_is_installed_once_with_a_note(): void
    {
        $calls = [];
        $result = DependencyPlan::build($this->fetcher([
            'A-One' => ['deps' => ['B-Two']],
            'B-Two' => ['deps' => ['A-One']],
        ], $calls), 'A', 'One', null, [], 6, 25);

        $this->assertSame(['B-Two', 'A-One'], $this->names($result));
        $this->assertSame(['A-One', 'B-Two'], $calls);
        $this->assertCount(1, $result['notes']);
        $this->assertStringContainsString('need each other', $result['notes'][0]);
    }

    public function test_a_longer_cycle_and_self_dependency_end(): void
    {
        $result = DependencyPlan::build($this->fetcher([
            'A-One' => ['deps' => ['B-Two', 'A-One']],
            'B-Two' => ['deps' => ['C-Three']],
            'C-Three' => ['deps' => ['A-One']],
        ]), 'A', 'One', null, [], 6, 25);

        $this->assertSame(['C-Three', 'B-Two', 'A-One'], $this->names($result));
        $this->assertNotSame([], $result['notes']);
    }

    public function test_a_deprecated_mod_that_was_asked_for_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('deprecated');

        DependencyPlan::build($this->fetcher(['A-Old' => ['deprecated' => true]]), 'A', 'Old', null, [], 6, 25);
    }

    public function test_a_deprecated_dependency_is_flagged_and_still_installed(): void
    {
        $result = DependencyPlan::build($this->fetcher([
            'A-Mod' => ['deps' => ['B-Old']],
            'B-Old' => ['deprecated' => true],
        ]), 'A', 'Mod', null, [], 6, 25);

        $this->assertSame(['B-Old', 'A-Mod'], $this->names($result));
        $this->assertStringContainsString('B-Old is marked as deprecated', $result['notes'][0]);
    }

    public function test_a_deprecated_dependency_that_is_installed_already_is_not_flagged(): void
    {
        $result = DependencyPlan::build($this->fetcher([
            'A-Mod' => ['deps' => ['B-Old']],
            'B-Old' => ['deprecated' => true],
        ]), 'A', 'Mod', null, ['b-old' => '1.0.0'], 6, 25);

        $this->assertSame(['A-Mod'], $this->names($result));
        $this->assertSame([], $result['notes']);
    }

    public function test_depth_and_package_limits(): void
    {
        $chain = [];
        foreach (range(1, 10) as $i) {
            $chain['A-M'.$i] = ['deps' => $i < 10 ? ['A-M'.($i + 1)] : []];
        }

        try {
            DependencyPlan::build($this->fetcher($chain), 'A', 'M1', null, [], 3, 25);
            $this->fail('expected the depth limit');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('too many layers', $exception->getMessage());
        }

        try {
            DependencyPlan::build($this->fetcher($chain), 'A', 'M1', null, [], 20, 5);
            $this->fail('expected the package limit');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('more than 5', $exception->getMessage());
        }
    }
}
