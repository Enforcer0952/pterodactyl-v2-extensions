<?php

namespace TerrariaTools\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TerrariaTools\Support\ServerConfig;

final class ServerConfigTest extends TestCase
{
    private static function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/fixtures/'.$name);
    }

    /** @return iterable<string, array{string}> */
    public static function samples(): iterable
    {
        yield 'plain' => [self::fixture('serverconfig.txt')];
        yield 'crlf with comments, no final newline' => [self::fixture('serverconfig-crlf.txt')];
        yield 'bom' => ["\xEF\xBB\xBFmaxplayers=8\nmotd=x\n"];
        yield 'mixed endings' => ["# a\nmaxplayers=8\r\nmotd=x\rpassword=y\n"];
        yield 'empty' => [''];
        yield 'only comments' => ["# nothing\n\n"];
        yield 'blank lines kept' => ["a=1\n\n\nb=2\n\n"];
        yield 'no trailing newline' => ['a=1'];
    }

    #[DataProvider('samples')]
    public function test_round_trips_byte_for_byte(string $contents): void
    {
        $this->assertSame($contents, ServerConfig::parse($contents)->render());
    }

    public function test_reads_values(): void
    {
        $config = ServerConfig::parse(self::fixture('serverconfig.txt'));

        $this->assertSame('8', $config->get('maxplayers'));
        $this->assertSame('', $config->get('seed'));
        $this->assertSame('secret', $config->get('password'));
        $this->assertNull($config->get('banlist'));
        $this->assertTrue($config->has('seed'));
        $this->assertFalse($config->has('banlist'));
    }

    public function test_keys_match_without_case_and_the_last_line_wins(): void
    {
        $config = ServerConfig::parse("MaxPlayers=4\nmaxplayers=8\n");

        $this->assertSame('8', $config->get('MAXPLAYERS'));
    }

    public function test_comments_do_not_count_as_keys(): void
    {
        $config = ServerConfig::parse(self::fixture('serverconfig-crlf.txt'));

        $this->assertNull($config->get('worldpath'));
        $this->assertSame('1', $config->get('secure'));
        // Values are read as written: the space after = is part of it.
        $this->assertSame(' keep me', $config->get('unknownkey'));
    }

    public function test_set_keeps_comments_unknown_keys_and_crlf(): void
    {
        $config = ServerConfig::parse(self::fixture('serverconfig-crlf.txt'));
        $config->set('maxplayers', '16');
        $config->set('motd', 'New message');

        $out = $config->render();
        $this->assertStringContainsString("# Terraria server config\r\n\r\n#worldpath=/old\r\n", $out);
        $this->assertStringContainsString("; secure\r\n", $out);
        $this->assertStringContainsString("unknownkey = keep me\r\n", $out);
        $this->assertStringContainsString("maxplayers=16\r\n", $out);
        $this->assertStringContainsString("motd=New message\r\n", $out);
        // Every line ending is still CRLF.
        $this->assertSame(0, preg_match('/(?<!\r)\n/', $out));
    }

    public function test_set_keeps_the_spelling_of_the_existing_key(): void
    {
        $config = ServerConfig::parse("WORLD=/a.wld\n");
        $config->set('world', '/b.wld');

        $this->assertSame("WORLD=/b.wld\n", $config->render());
    }

    public function test_set_updates_every_repeat(): void
    {
        $config = ServerConfig::parse("motd=a\nx=1\nmotd=b\n");
        $config->set('motd', 'c');

        $this->assertSame("motd=c\nx=1\nmotd=c\n", $config->render());
    }

    public function test_set_adds_a_missing_key_at_the_end_in_the_files_line_ending(): void
    {
        $config = ServerConfig::parse("a=1\r\nb=2");
        $config->set('seed', '123');
        $this->assertSame("a=1\r\nb=2\r\nseed=123\r\n", $config->render());

        $config = ServerConfig::parse('');
        $config->set('a', '1');
        $this->assertSame("a=1\n", $config->render());
    }

    public function test_a_value_cannot_start_a_new_line(): void
    {
        $config = ServerConfig::parse("motd=hi\n");
        $config->set('motd', "x\nautocreate=3\r\npassword=\u{2028}pwned");

        $out = $config->render();
        $this->assertSame(1, substr_count($out, "\n"));
        $this->assertNull($config->get('autocreate'));
        $this->assertSame('x autocreate=3 password= pwned', $config->get('motd'));
    }

    public function test_remove(): void
    {
        $config = ServerConfig::parse("# c\nseed=1\nmotd=a\nseed=2\n");

        $this->assertTrue($config->remove('SEED'));
        $this->assertFalse($config->remove('seed'));
        $this->assertSame("# c\nmotd=a\n", $config->render());
    }

    public function test_entries_list_key_lines_only(): void
    {
        $entries = ServerConfig::parse("# c\n\nmotd=a\nseed=\n")->entries();

        $this->assertSame([['key' => 'motd', 'value' => 'a', 'line' => 3], ['key' => 'seed', 'value' => '', 'line' => 4]], $entries);
    }

    public function test_values_with_equals_signs_survive(): void
    {
        $config = ServerConfig::parse("password=a=b=c\n");

        $this->assertSame('a=b=c', $config->get('password'));
    }

    public function test_masked_hides_the_password_and_leaves_everything_else(): void
    {
        $contents = self::fixture('serverconfig-crlf.txt');
        $masked = ServerConfig::parse($contents)->masked(['password'], '********');

        $this->assertStringContainsString('password=********', $masked);
        $this->assertStringNotContainsString('secret', $masked);
        $this->assertStringContainsString("# Terraria server config\r\n", $masked);
        // The original is untouched.
        $this->assertSame('secret', ServerConfig::parse($contents)->get('password'));
    }

    public function test_an_empty_password_is_not_masked(): void
    {
        $this->assertSame("password=\n", ServerConfig::parse("password=\n")->masked(['password'], '********'));
    }

    public function test_masking_covers_every_case_and_repeat(): void
    {
        $masked = ServerConfig::parse("Password=one\npassword=two\n")->masked(['password'], '********');

        $this->assertSame("Password=********\npassword=********\n", $masked);
    }
}
