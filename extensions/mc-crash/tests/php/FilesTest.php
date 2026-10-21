<?php

use McCrash\Services\Files;
use PHPUnit\Framework\TestCase;

final class FilesTest extends TestCase
{
    public function test_symbolic_links_are_not_plain_files(): void
    {
        $this->assertTrue(Files::isPlainFile(['name' => 'a.txt', 'file' => true, 'symlink' => false]));
        $this->assertTrue(Files::isPlainFile(['name' => 'a.txt']));
        $this->assertFalse(Files::isPlainFile(['name' => 'a.txt', 'file' => true, 'symlink' => true]));
        $this->assertFalse(Files::isPlainFile(['name' => 'dir', 'file' => false]));
        $this->assertTrue(Files::isLink(['name' => 'x', 'symlink' => true]));
        $this->assertFalse(Files::isLink(['name' => 'x']));
    }

    public function test_a_listed_link_is_not_found_by_name(): void
    {
        $entries = [
            ['name' => 'latest.log', 'file' => true, 'symlink' => true],
            ['name' => 'debug.log', 'file' => true, 'symlink' => false, 'size' => 5],
        ];

        $this->assertNull(Files::file($entries, 'latest.log'));
        $this->assertSame(5, Files::file($entries, 'debug.log')['size'] ?? null);
    }

    public function test_cutting_to_a_size_never_splits_a_character(): void
    {
        $text = str_repeat("\u{e9}", 10); // two bytes each
        $this->assertSame(str_repeat("\u{e9}", 2), mb_strcut($text, 0, 5, 'UTF-8'));
        $this->assertTrue(mb_check_encoding(mb_strcut($text, 0, 5, 'UTF-8'), 'UTF-8'));
        $this->assertFalse(mb_check_encoding(substr($text, 0, 5), 'UTF-8'));
    }
}
