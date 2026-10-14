<?php

namespace McCrash\Tests;

use McCrash\Support\CrashSections;
use McCrash\Support\Derive;
use McCrash\Support\MinecraftServer;
use McCrash\Support\ReportMeta;
use McCrash\Support\RuleEngine;
use McCrash\Support\Text;
use McCrash\Support\Times;
use PHPUnit\Framework\TestCase;

final class SupportTest extends TestCase
{
    public function test_text_clean_strips_control_characters_ansi_and_bad_bytes(): void
    {
        $dirty = "line one\r\nline\ttwo\x00\x07 \x1b[31mred\x1b[0m\r\nbad \xff\xfe bytes\u{2028}end";
        $clean = Text::clean($dirty);

        $this->assertStringNotContainsString("\r", $clean);
        $this->assertStringNotContainsString("\x00", $clean);
        $this->assertStringNotContainsString("\x1b", $clean);
        $this->assertStringContainsString("line\ttwo", $clean);
        $this->assertStringContainsString('red', $clean);
        $this->assertTrue(mb_check_encoding($clean, 'UTF-8'));
        $this->assertNotFalse(json_encode($clean));
    }

    public function test_text_caps(): void
    {
        $this->assertSame(10, strlen(Text::clean(str_repeat('a', 100), 10)));
        $this->assertSame("c\nd", Text::tail("a\nb\nc\nd", 4));
        $this->assertSame('whole', Text::tail('whole', 100));
        $this->assertSame("1\n2", Text::head("1\n2\n3\n4", 2));
        $this->assertSame('a b c', Text::oneLine("a\n  b\tc"));
        $this->assertSame(10, mb_strlen(Text::oneLine(str_repeat('x', 50), 10)));
        $this->assertSame('Foo', Text::simpleName('a.b.Foo$Bar'));
    }

    public function test_times(): void
    {
        $this->assertSame('2026-10-12T21:30:14', Times::fromListing('2026-10-12T21:30:14+02:00'));
        $this->assertNull(Times::fromListing('yesterday'));
        $this->assertSame('2026-10-12T21:30:14', Times::fromCrashName('crash-2026-10-12_21.30.14-server.txt'));
        $this->assertNull(Times::fromCrashName('crash-2026-13-45_21.30.14-server.txt'));
        $this->assertSame('2026-10-12', Times::dateFromLogName('2026-10-12-3.log.gz'));
        $this->assertNull(Times::dateFromLogName('latest.log'));
        $this->assertSame('2026-02-28', Times::previousDay('2026-03-01'));
        $this->assertSame('2027-01-01', Times::nextDay('2026-12-31'));
    }

    public function test_report_times_in_every_known_format(): void
    {
        $this->assertSame('2026-10-12T21:30:00', Times::fromReport("Time: 2026-10-12 21:30:00 CEST\n"));
        $this->assertSame('2021-06-12T21:41:00', Times::fromReport("Time: 6/12/21 9:41 PM\n"));
        $this->assertSame('2021-06-12T00:05:00', Times::fromReport("Time: 6/12/21 12:05 AM\n"));
        $this->assertSame('2026-10-12T22:41:09', Times::fromReport("Time: Mon Oct 12 22:41:09 2026 UTC elapsed time: 18902 seconds\n"));
        $this->assertNull(Times::fromReport("Time: whenever\n"));
        $this->assertNull(Times::fromReport('no time here'));
    }

    public function test_report_meta(): void
    {
        $meta = ReportMeta::parse(Jars::fixture('paper-crash-npe.txt'));
        $this->assertSame('crash', $meta['kind']);
        $this->assertSame('2026-10-12T21:30:14', $meta['time']);
        $this->assertSame('Exception in server tick loop', $meta['description']);
        $this->assertSame('1.21.1', $meta['minecraft']);
        $this->assertSame('21.0.4, Eclipse Adoptium', $meta['java']);

        $this->assertSame('jvm', ReportMeta::parse(Jars::fixture('hs_err_pid4242.log'))['kind']);
        $this->assertSame('log', ReportMeta::parse(Jars::fixture('port-bind.log'))['kind']);
        $this->assertSame(['create'], ReportMeta::parse(Jars::fixture('ticking-entity-forge.txt'))['suspected']);
    }

    public function test_java_versions_from_class_file_versions(): void
    {
        $this->assertSame(8, Derive::javaForClassFile(52));
        $this->assertSame(17, Derive::javaForClassFile(61));
        $this->assertSame(21, Derive::javaForClassFile(65));
        $this->assertSame(25, Derive::javaForClassFile(69));
        $this->assertNull(Derive::javaForClassFile(3));
        $this->assertNull(Derive::javaForClassFile(9999));
    }

    public function test_rules_fill_placeholders_and_tidy_spacing(): void
    {
        $this->assertSame('Needs Java 21.', RuleEngine::render('Needs Java {java} {missing}.', ['java' => '21']));
        $this->assertSame('a, b.', RuleEngine::render('a , b .', []));
    }

    public function test_the_rules_file_is_valid(): void
    {
        $data = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/resources/rules.json'), true);
        $ids = [];

        foreach ($data['rules'] as $rule) {
            $this->assertNotContains($rule['id'], $ids, 'duplicate rule id');
            $ids[] = $rule['id'];
            foreach (['id', 'severity', 'title', 'match', 'verdict', 'fix'] as $key) {
                $this->assertArrayHasKey($key, $rule, $rule['id']);
            }
            $this->assertContains($rule['severity'], ['crash', 'error', 'warning', 'info'], $rule['id']);
            foreach (array_merge($rule['match'], $rule['extra'] ?? [], isset($rule['also']) ? [$rule['also']] : []) as $pattern) {
                $this->assertNotFalse(@preg_match('~'.str_replace('~', '\~', $pattern).'~mu', ''), $rule['id'].': '.$pattern);
            }
            $this->assertStringNotContainsString('!', $rule['verdict'].$rule['fix'], 'no exclamation marks in the UI text: '.$rule['id']);
        }

        foreach (['java-version', 'missing-dep-fabric', 'missing-dep-forge', 'missing-dep-paper', 'mixin-failure', 'mixin-conflict', 'out-of-memory', 'port-in-use', 'corrupt-chunk', 'cant-keep-up', 'eula', 'api-version'] as $required) {
            $this->assertContains($required, $ids);
        }
    }

    public function test_java_version_rule_reads_older_messages(): void
    {
        $rules = RuleEngine::fromFile(dirname(__DIR__, 2).'/resources/rules.json');
        $old = $rules->first("java.lang.UnsupportedClassVersionError: net/minecraft/bundler/Main : Unsupported major.minor version 61.0\n");

        $this->assertSame('java-version', $old['rule']);
        $this->assertSame('Wrong Java version: this needs Java 17 or newer.', $old['verdict']);
    }

    public function test_asm_error_is_not_the_wrong_java_rule(): void
    {
        $rules = RuleEngine::fromFile(dirname(__DIR__, 2).'/resources/rules.json');
        $found = $rules->first("java.lang.IllegalArgumentException: Unsupported class file major version 65\n");

        $this->assertSame('class-too-new', $found['rule']);
        $this->assertStringContainsString('Java 21', $found['verdict']);
    }

    public function test_oom_hints_differ_by_kind(): void
    {
        $rules = RuleEngine::fromFile(dirname(__DIR__, 2).'/resources/rules.json');

        $this->assertStringContainsString('more RAM', $rules->first('java.lang.OutOfMemoryError: Java heap space')['fix']);
        $this->assertStringContainsString('classes', $rules->first('java.lang.OutOfMemoryError: Metaspace')['fix']);
        $this->assertStringContainsString('threads', $rules->first('java.lang.OutOfMemoryError: unable to create native thread: possibly out of memory')['fix']);
        $this->assertStringContainsString('below the memory limit', $rules->first("# There is insufficient memory for the Java Runtime Environment to continue.\n")['fix']);
    }

    public function test_noise_patterns(): void
    {
        $rules = RuleEngine::fromFile(dirname(__DIR__, 2).'/resources/rules.json');

        $this->assertSame('moved-too-quickly', $rules->noise('Steve moved too quickly! 1,2,3')['id']);
        $this->assertNull($rules->noise('Steve joined the game'));
        $this->assertNull($rules->noise('NullPointerException in a plugin'));
    }

    public function test_rules_have_a_version_that_follows_their_content(): void
    {
        $a = new RuleEngine([['id' => 'x']]);
        $b = new RuleEngine([['id' => 'y']]);

        $this->assertNotSame($a->version(), $b->version());
        $this->assertSame($a->version(), (new RuleEngine([['id' => 'x']]))->version());
    }

    public function test_crash_section_of_a_long_debug_log(): void
    {
        $filler = '';
        for ($i = 0; $i < 3000; $i++) {
            $filler .= "[12Oct2026 19:00:00.000] [main/DEBUG] [net.minecraftforge.Foo/]: filler line number $i\n";
        }
        $log = $filler.Jars::fixture('forge-missing-mods-debug.log').$filler;
        $section = CrashSections::find($log);

        $this->assertNotNull($section);
        $this->assertStringContainsString('Missing or unsupported mandatory dependencies', $section['text']);
        $this->assertLessThan(strlen($log) / 3, strlen($section['text']));
        $this->assertSame('2026-10-12T19:00:00', $section['time']);
        $this->assertNull(CrashSections::find($filler));
    }

    public function test_egg_gating(): void
    {
        $this->assertTrue(MinecraftServer::matches(['minecraft'], []));
        $this->assertTrue(MinecraftServer::matches([], ['eula']));
        $this->assertTrue(MinecraftServer::matches([], [], 'Velocity'));
        $this->assertTrue(MinecraftServer::matches(['proxy'], []));
        $this->assertTrue(MinecraftServer::matches(['BungeeCord'], []));
        $this->assertFalse(MinecraftServer::matches(['minecraft', 'bedrock'], []));
        $this->assertFalse(MinecraftServer::matches(['ark'], ['steam']));
        $this->assertFalse(MinecraftServer::matches([], []));
    }
}
