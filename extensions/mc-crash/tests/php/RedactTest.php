<?php

use McCrash\Support\Redact;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RedactTest extends TestCase
{
    public function test_ipv4_addresses_are_hidden_with_their_ports_kept(): void
    {
        $result = Redact::apply("[Server thread/INFO]: Steve[/203.0.113.7:51234] logged in\nlistening on 0.0.0.0:25565\n", false);

        $this->assertSame("[Server thread/INFO]: Steve[/[ip hidden]:51234] logged in\nlistening on [ip hidden]:25565\n", $result['text']);
        $this->assertSame(2, $result['ips']);
    }

    public function test_versions_and_numbers_that_are_not_addresses_stay(): void
    {
        $text = "Minecraft 1.21.4 build 232 at 12:30:45\nPaper 1.20.6-148 (MC: 1.20.6)\nvalue 999.1.1.1 and 1.2.3.4.5 and v1.2.3\n";

        $result = Redact::apply($text, false);
        // 999.1.1.1 is not an address and 1.2.3.4.5 is a five-part number.
        $this->assertSame($text, $result['text']);
        $this->assertSame(0, $result['ips']);
    }

    #[DataProvider('ipv6')]
    public function test_ipv6_addresses_are_hidden(string $address): void
    {
        $result = Redact::apply("Player connected from $address now\n", false);

        $this->assertSame("Player connected from [ip hidden] now\n", $result['text'], $address);
        $this->assertSame(1, $result['ips']);
    }

    public static function ipv6(): array
    {
        return [
            'full' => ['2001:0db8:85a3:0000:0000:8a2e:0370:7334'],
            'compressed' => ['2001:db8::1'],
            'loopback' => ['::1'],
            'link local' => ['fe80::1ff:fe23:4567:890a'],
            'mapped' => ['::ffff:192.0.2.128'],
            'upper case' => ['2001:DB8::ABCD'],
        ];
    }

    public function test_ipv6_in_brackets_and_at_the_end_of_a_sentence(): void
    {
        $this->assertSame('connect [[ip hidden]]:25565', Redact::apply('connect [2001:db8::1]:25565', false)['text']);
        $this->assertSame('seen at [ip hidden].', Redact::apply('seen at 2001:db8::1.', false)['text']);
    }

    public function test_class_names_and_times_are_not_taken_for_ipv6(): void
    {
        $text = "at java.base/java.util.List::of\nstream::map\n10:15:30 and 1:2:3\n";

        $this->assertSame($text, Redact::apply($text, false)['text']);
    }

    #[DataProvider('commands')]
    public function test_login_commands_lose_their_arguments(string $command, string $expected): void
    {
        $line = "[12:00:00] [Server thread/INFO]: Steve issued server command: $command";
        $result = Redact::apply($line."\nnext line\n", false);

        $this->assertSame("[12:00:00] [Server thread/INFO]: Steve issued server command: $expected\nnext line\n", $result['text']);
        $this->assertSame(1, $result['commands']);
    }

    public static function commands(): array
    {
        return [
            'login' => ['/login hunter2', '/login [hidden]'],
            'register' => ['/register hunter2 hunter2', '/register [hidden]'],
            'short l' => ['/l hunter2', '/l [hidden]'],
            'reg' => ['/reg a a', '/reg [hidden]'],
            'changepassword' => ['/changepassword old new', '/changepassword [hidden]'],
            'cp' => ['/cp old new', '/cp [hidden]'],
            'upper' => ['/LOGIN hunter2', '/LOGIN [hidden]'],
            'mixed' => ['/ChangePassword a b', '/ChangePassword [hidden]'],
            'prefixed' => ['/authme:login hunter2', '/authme:login [hidden]'],
            'bare' => ['/login', '/login [hidden]'],
        ];
    }

    public function test_other_commands_stay_readable(): void
    {
        $text = "Steve issued server command: /gamemode creative\nSteve issued server command: /list\nSteve issued server command: /lobby\nSteve issued server command: /logout\nSteve issued server command: /cpu stats\n";

        $result = Redact::apply($text, false);
        $this->assertSame($text, $result['text']);
        $this->assertSame(0, $result['commands']);
    }

    public function test_jvm_options_lose_their_values_only_in_jvm_logs(): void
    {
        $text = "jvm_args: -Xmx4G -Dfml.queryResult=confirm -Ddb.password=s3cret -Dtoken=\"a b c\" -Dempty=\njava_command: server.jar nogui\n";

        $kept = Redact::apply($text, false);
        $this->assertSame($text, $kept['text']);

        $result = Redact::apply($text, true);
        $this->assertSame("jvm_args: -Xmx4G -Dfml.queryResult=[hidden] -Ddb.password=[hidden] -Dtoken=[hidden] -Dempty=\njava_command: server.jar nogui\n", $result['text']);
        $this->assertSame(3, $result['jvm_args']);
        $this->assertStringNotContainsString('s3cret', $result['text']);
    }

    public function test_hs_err_logs_are_recognised_by_path(): void
    {
        $this->assertTrue(Redact::isJvmLog('hs_err_pid4242.log'));
        $this->assertTrue(Redact::isJvmLog('crash/hs_err_pid1.log'));
        $this->assertFalse(Redact::isJvmLog('logs/latest.log'));
        $this->assertFalse(Redact::isJvmLog('crash-reports/hs_err_pid1.log.txt'));
    }

    public function test_the_hs_err_fixture_comes_out_clean(): void
    {
        $text = (string) file_get_contents(__DIR__.'/fixtures/hs_err_pid4242.log');
        $result = Redact::apply($text."\njvm_args: -Dsecret=abc 10.0.0.5\n", true);

        $this->assertStringNotContainsString('abc', $result['text']);
        $this->assertStringNotContainsString('10.0.0.5', $result['text']);
        $this->assertGreaterThanOrEqual(1, $result['ips']);
    }

    public function test_it_does_nothing_to_clean_text_and_survives_odd_input(): void
    {
        $this->assertSame(['text' => '', 'ips' => 0, 'commands' => 0, 'jvm_args' => 0], Redact::apply('', true));
        $odd = "caf\u{e9} \u{1F600} 日本語 ::: ... -D= -Dx\n";
        $this->assertSame($odd, Redact::apply($odd, true)['text']);
    }
}
