<?php

namespace McCrash\Tests;

use McCrash\Support\GzTail;
use McCrash\Support\LogParser;
use McCrash\Support\LogScanner;
use McCrash\Support\RuleEngine;
use PHPUnit\Framework\TestCase;

final class LogScannerTest extends TestCase
{
    private static function scanner(): LogScanner
    {
        $rules = RuleEngine::fromFile(dirname(__DIR__, 2).'/resources/rules.json');

        return new LogScanner(new \McCrash\Support\Analyzer($rules), $rules);
    }

    /** @param list<array<string, mixed>> $groups */
    private static function find(array $groups, string $needle): array
    {
        foreach ($groups as $group) {
            if (str_contains((string) $group['id'], $needle) || str_contains((string) $group['title'], $needle)) {
                return $group;
            }
        }

        self::fail('no group for '.$needle.' in '.json_encode(array_column($groups, 'title')));
    }

    public function test_repeated_traces_become_one_group_with_count_and_times(): void
    {
        $result = self::scanner()->scan(Jars::fixture('latest.log'), Jars::map(), '2026-10-13', 'end');
        $groups = $result['groups'];

        $npe = self::find($groups, 'plugin-exception');
        $this->assertSame(3, $npe['count']);
        $this->assertSame('2026-10-12T23:58:44', $npe['first']);
        $this->assertSame('2026-10-13T00:01:05', $npe['last']);
        $this->assertSame('error', $npe['severity']);
        $this->assertSame('ChestShopLite-2.1.0.jar', $npe['culprit']['jars'][0]['file']);
        $this->assertSame('confirmed', $npe['culprit']['tier']);
        $this->assertStringContainsString('ChestShopLite threw', $npe['title']);
        $this->assertStringContainsString('Cannot invoke', $npe['sample']);
    }

    public function test_cant_keep_up_is_a_warning_with_the_worst_spike(): void
    {
        $groups = self::scanner()->scan(Jars::fixture('latest.log'), Jars::map(), '2026-10-13', 'end')['groups'];
        $lag = self::find($groups, 'cant-keep-up');

        $this->assertSame(2, $lag['count']);
        $this->assertSame('warning', $lag['severity']);
        $this->assertSame(['value' => 5120, 'label' => 'Worst spike: {value} ms behind'], $lag['stat']);
        $this->assertSame('Worst spike: 5120 ms behind', LogScanner::present([$lag])[0]['stat']);
    }

    public function test_noise_is_collapsed_and_sorted_last(): void
    {
        $groups = self::scanner()->scan(Jars::fixture('latest.log'), Jars::map())['groups'];
        $noise = self::find($groups, 'moved-too-quickly');

        $this->assertTrue($noise['noise']);
        $this->assertSame(2, $noise['count']);
        $this->assertTrue($groups[array_key_last($groups)]['noise']);
        $this->assertSame('23:59:10', $noise['first']);
    }

    public function test_an_unmatched_error_with_a_plugin_tag_is_a_guess_at_most(): void
    {
        $groups = self::scanner()->scan(Jars::fixture('latest.log'), Jars::map())['groups'];
        $io = self::find($groups, 'Could not write tile');

        $this->assertSame('DynmapLite-1.4.jar', $io['culprit']['jars'][0]['file']);
        $this->assertSame('confirmed', $io['culprit']['tier']);

        $warn = self::find($groups, 'Tile queue is 5000 long');
        $this->assertSame('warning', $warn['severity']);
        $this->assertSame('likely', $warn['culprit']['tier']);
    }

    public function test_dates_run_forward_for_a_rotated_log(): void
    {
        $groups = self::scanner()->scan(Jars::fixture('latest.log'), Jars::map(), '2026-10-12', 'start')['groups'];
        $npe = self::find($groups, 'plugin-exception');

        $this->assertSame('2026-10-12T23:58:44', $npe['first']);
        $this->assertSame('2026-10-13T00:01:05', $npe['last']);
    }

    public function test_merging_logs_adds_counts_and_keeps_the_widest_time_range(): void
    {
        $scanner = self::scanner();
        $a = $scanner->scan(Jars::fixture('latest.log'), Jars::map(), '2026-10-12', 'start')['groups'];
        $b = $scanner->scan(Jars::fixture('latest.log'), Jars::map(), '2026-10-14', 'start')['groups'];
        $merged = LogScanner::merge($a, $b);
        $npe = self::find($merged, 'plugin-exception');

        $this->assertSame(6, $npe['count']);
        $this->assertSame('2026-10-12T23:58:44', $npe['first']);
        $this->assertSame('2026-10-15T00:01:05', $npe['last']);
    }

    public function test_header_formats(): void
    {
        $this->assertSame(['time' => '21:30:00', 'level' => 'ERROR', 'message' => 'boom'], LogParser::header('[21:30:00 ERROR]: boom'));
        $this->assertSame(['time' => '21:30:00', 'level' => 'WARN', 'message' => 'x'], LogParser::header('[21:30:00] [Server thread/WARN]: x'));
        $this->assertSame(['time' => '20:11:05', 'level' => 'ERROR', 'message' => 'Failed'], LogParser::header('[12Oct2026 20:11:05.220] [main/ERROR] [net.minecraftforge.fml.loading.ModSorter/LOADING]: Failed'));
        $this->assertSame(['time' => '21:30:00', 'level' => 'ERROR', 'message' => 'bad'], LogParser::header('21:30:00 [SEVERE] bad'));
        $this->assertSame(['time' => '21:30:00', 'level' => 'INFO', 'message' => 'ok'], LogParser::header('2020-01-02 21:30:00 [INFO] ok'));
        $this->assertSame('ERROR', LogParser::header('[21:30:00] [main/FATAL]: x')['level']);
        $this->assertNull(LogParser::header("\tat com.foo.Bar.baz(Bar.java:1)"));
        $this->assertNull(LogParser::header(''));
    }

    public function test_only_warnings_and_errors_are_kept_and_long_entries_are_capped(): void
    {
        $text = "[10:00:00 INFO]: hello\n[10:00:01 ERROR]: big\n".str_repeat("\tat com.foo.Bar.baz(Bar.java:1)\n", 400)."[10:00:02 INFO]: bye\n";
        $entries = iterator_to_array(LogParser::entries($text), false);

        $this->assertCount(1, $entries);
        $this->assertLessThanOrEqual(150, substr_count($entries[0]['body'], "\n") + 1);
    }

    public function test_output_before_the_first_header_counts_when_it_looks_like_an_error(): void
    {
        $text = "Error: Unable to access jarfile server.jar\nsome other text\n[10:00:00 INFO]: Starting\n";
        $entries = iterator_to_array(LogParser::entries($text), false);

        $this->assertCount(1, $entries);
        $this->assertNull($entries[0]['time']);
    }

    public function test_gzip_logs_are_unpacked_and_only_the_end_is_kept(): void
    {
        $log = '';
        for ($i = 0; $i < 20000; $i++) {
            $log .= sprintf("[10:%02d:%02d INFO]: line %d of a long log file\n", intdiv($i, 60) % 60, $i % 60, $i);
        }
        $gz = gzencode($log, 6);

        $all = GzTail::tail($gz, strlen($log) + 10);
        $this->assertSame($log, $all['text']);
        $this->assertFalse($all['cut']);

        $tail = GzTail::tail($gz, 10000);
        $this->assertTrue($tail['cut']);
        $this->assertLessThanOrEqual(10000, strlen($tail['text']));
        $this->assertStringEndsWith("line 19999 of a long log file\n", $tail['text']);
        $this->assertStringStartsWith('[10:', $tail['text'], 'the tail starts on a whole line');
    }

    public function test_gzip_limit_and_damaged_input(): void
    {
        $gz = gzencode(str_repeat("[10:00:00 INFO]: padding padding padding\n", 50000), 9);

        $limited = GzTail::tail($gz, 1000, 100000);
        $this->assertTrue($limited['truncated']);
        $this->assertLessThanOrEqual(1000, strlen($limited['text']));

        $notGz = GzTail::tail('this is plain text, not gzip at all', 100);
        $this->assertNotNull($notGz['error']);
        $this->assertSame('', $notGz['text']);

        $busy = '';
        for ($i = 0; $i < 5000; $i++) {
            $busy .= sprintf("[10:00:00 INFO]: line %d %s\n", $i, md5((string) $i));
        }
        $busyGz = gzencode($busy, 6);

        $damaged = GzTail::tail(substr($busyGz, 0, 3000).str_repeat("\xff", 50).substr($busyGz, 3050), 1000);
        $this->assertSame('The file is damaged.', $damaged['error']);
        $this->assertNotSame('', $damaged['text'], 'what was unpacked before the damage is kept');

        $short = GzTail::tail(substr($busyGz, 0, 3000), 1000);
        $this->assertSame('The file ends early.', $short['error']);
    }
}
