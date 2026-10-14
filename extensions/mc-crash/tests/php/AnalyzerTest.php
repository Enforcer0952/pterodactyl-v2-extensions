<?php

namespace McCrash\Tests;

use McCrash\Support\Analyzer;
use McCrash\Support\RuleEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AnalyzerTest extends TestCase
{
    private static function analyzer(): Analyzer
    {
        return new Analyzer(RuleEngine::fromFile(dirname(__DIR__, 2).'/resources/rules.json'));
    }

    /**
     * fixture, rule, severity, culprit jar (or null), tier, text the verdict must contain, text the fix must contain
     *
     * @return iterable<string, array{string, ?string, string, ?string, ?string, string, string}>
     */
    public static function fixtures(): iterable
    {
        yield 'Paper crash report with a null pointer in a plugin' => [
            'paper-crash-npe.txt', null, 'crash', 'ChestShopLite-2.1.0.jar', 'confirmed', 'NullPointerException', 'Update ChestShopLite',
        ];
        yield 'Fabric mixin crash names the mod id' => [
            'fabric-mixin-crash.txt', 'mixin-failure', 'crash', 'sodium-extra-fabric-0.5.4+mc1.20.1.jar', 'confirmed', 'Mixin failure: sodium-extra', 'Update it',
        ];
        yield 'Forge missing mods screen in debug.log' => [
            'forge-missing-mods-debug.log', 'missing-dep-forge', 'error', null, null, 'bosses_of_mass_destruction needs geckolib', 'geckolib',
        ];
        yield 'UnsupportedClassVersionError' => [
            'unsupported-class-version.log', 'java-version', 'error', 'FancyHolograms-2.4.0.jar', 'confirmed', 'needs Java 21 or newer, the server runs Java 17', 'Java 21',
        ];
        yield 'OutOfMemoryError in a crash report' => [
            'oom-crash.txt', 'out-of-memory', 'crash', null, null, 'ran out of memory (Java heap space)', 'more RAM',
        ];
        yield 'Port bind failure' => [
            'port-bind.log', 'port-in-use', 'error', null, null, 'port is already in use', 'Network page',
        ];
        yield 'hs_err header' => [
            'hs_err_pid4242.log', 'jvm-crash', 'crash', 'RegionCompress-0.9.jar', 'confirmed', 'crashed (SIGSEGV) in libzstd-jni-1.5.6-4.so', 'newest Java image',
        ];
        yield 'Paper missing dependency' => [
            'paper-missing-dep.log', 'missing-dep-paper', 'error', 'ShopGUIPlus-1.99.jar', 'confirmed', 'ShopGUIPlus needs the plugin Vault, PlaceholderAPI', 'Install Vault, PlaceholderAPI',
        ];
        yield 'EULA' => ['eula.log', 'eula', 'error', null, null, 'EULA is not accepted', 'eula=true'];
        yield 'Corrupt chunk' => ['corrupt-chunk.log', 'corrupt-chunk', 'error', null, null, 'near X -192, Z 544', 'r.-1.1.mca'];
        yield 'Mixin conflict' => [
            'mixin-conflict.log', 'mixin-conflict', 'error', 'modernfix-forge-5.19.jar', 'likely', 'Two mods change the same game code: modernfix', 'one of the two mods',
        ];
        yield 'Watchdog thread dump' => [
            'watchdog.log', 'watchdog', 'error', 'ChestShopLite-2.1.0.jar', 'confirmed', 'watchdog (a tick took 60.00 seconds)', 'thread dump',
        ];
        yield 'Ticking entity names the mod from the trace' => [
            'ticking-entity-forge.txt', 'ticking', 'crash', 'create-1.20.1-0.5.1.f.jar', 'confirmed', 'while updating an entity (create:stationary_contraption at 104, 71, -234)', 'broken entity',
        ];
        yield 'Cannot keep up is a warning, not a crash' => [
            'cant-keep-up.log', 'cant-keep-up', 'warning', null, null, 'lagging', 'spark',
        ];
    }

    #[DataProvider('fixtures')]
    public function test_fixture_verdicts(string $file, ?string $rule, string $severity, ?string $jar, ?string $tier, string $verdict, string $fix): void
    {
        $result = self::analyzer()->analyze(Jars::fixture($file), Jars::map());

        $this->assertSame($rule, $result['rule'], json_encode($result));
        $this->assertStringContainsString($verdict, $result['verdict']);
        $this->assertStringContainsString($fix, $result['fix']);

        if ($jar === null) {
            $this->assertNull($result['culprit'], json_encode($result['culprit']));
        } else {
            $this->assertNotNull($result['culprit'], 'no culprit for '.$file);
            $this->assertSame($tier, $result['culprit']['tier']);
            $this->assertSame($jar, $result['culprit']['jars'][0]['file']);
        }
    }
}
