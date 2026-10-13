<?php

namespace TerrariaTools\Tests;

use PHPUnit\Framework\TestCase;
use TerrariaTools\Support\BanList;

final class BanListTest extends TestCase
{
    private static function fixture(): string
    {
        return (string) file_get_contents(__DIR__.'/fixtures/banlist.txt');
    }

    public function test_parses_names_and_identifiers(): void
    {
        $this->assertSame(
            [['name' => 'Griefer', 'id' => '192.168.1.30'], ['name' => 'Another Player', 'id' => '10.0.0.7']],
            BanList::parse(self::fixture()),
        );
    }

    public function test_handles_crlf_blank_lines_and_a_missing_name(): void
    {
        $entries = BanList::parse("//Griefer\r\n192.168.1.30\r\n\r\n10.0.0.9\r\n");

        $this->assertSame([['name' => 'Griefer', 'id' => '192.168.1.30'], ['name' => null, 'id' => '10.0.0.9']], $entries);
    }

    public function test_an_empty_or_comment_only_file_has_no_bans(): void
    {
        $this->assertSame([], BanList::parse(''));
        $this->assertSame([], BanList::parse("//Nobody\n\n"));
    }

    public function test_a_bom_is_ignored(): void
    {
        $this->assertSame([['name' => 'A', 'id' => '1.2.3.4']], BanList::parse("\xEF\xBB\xBF//A\n1.2.3.4\n"));
    }

    public function test_names_are_cleaned_to_one_line(): void
    {
        $entries = BanList::parse("//Bad\tName\n1.2.3.4\n");

        $this->assertSame('Bad Name', $entries[0]['name']);
    }

    public function test_removes_a_ban_with_its_name_comment(): void
    {
        $this->assertSame("//Another Player\n10.0.0.7\n", BanList::remove(self::fixture(), '192.168.1.30'));
        $this->assertSame("//Griefer\n192.168.1.30\n", BanList::remove(self::fixture(), '10.0.0.7'));
    }

    public function test_remove_keeps_everything_else_byte_for_byte(): void
    {
        $contents = "\xEF\xBB\xBF//Griefer\r\n192.168.1.30\r\n\r\n//Other\r\n10.0.0.7";

        $this->assertSame("\xEF\xBB\xBF\r\n//Other\r\n10.0.0.7", BanList::remove($contents, '192.168.1.30'));
    }

    public function test_remove_takes_every_line_of_the_identifier(): void
    {
        $contents = "//One\n1.2.3.4\n//Two\n1.2.3.4\n//Three\n5.6.7.8\n";

        $this->assertSame("//Three\n5.6.7.8\n", BanList::remove($contents, '1.2.3.4'));
    }

    public function test_remove_keeps_the_neighbouring_bans_and_a_blank_line(): void
    {
        $contents = "//First\n1.1.1.1\n//Second\n\n2.2.2.2\n//Third\n3.3.3.3\n";

        $this->assertSame("//First\n1.1.1.1\n\n//Third\n3.3.3.3\n", BanList::remove($contents, '2.2.2.2'));
    }

    public function test_remove_reports_when_nothing_matches(): void
    {
        $this->assertNull(BanList::remove(self::fixture(), '8.8.8.8'));
        $this->assertNull(BanList::remove('', '8.8.8.8'));
    }

    public function test_a_comment_line_cannot_be_removed_as_an_identifier(): void
    {
        $this->assertNull(BanList::remove(self::fixture(), '//Griefer'));
        $this->assertFalse(BanList::validId('//Griefer'));
    }

    public function test_identifier_rules(): void
    {
        $this->assertTrue(BanList::validId('192.168.1.30'));
        $this->assertTrue(BanList::validId('IP:10.0.0.1'));
        $this->assertFalse(BanList::validId(''));
        $this->assertFalse(BanList::validId("1.2.3.4\n5.6.7.8"));
        $this->assertFalse(BanList::validId("1.2.3.4\x00"));
        $this->assertFalse(BanList::validId(str_repeat('a', 201)));
    }
}
