<?php

namespace TerrariaTools\Tests;

use PHPUnit\Framework\TestCase;
use TerrariaTools\Support\FileCopy;

final class FileCopyTest extends TestCase
{
    public function test_finds_the_copy_next_to_the_original(): void
    {
        $before = ['My World.wld', 'Other.wld'];

        $this->assertSame('My World copy.wld', FileCopy::find('My World.wld', $before, [...$before, 'My World copy.wld']));
    }

    public function test_finds_a_numbered_copy_when_the_first_name_was_taken(): void
    {
        $before = ['W.wld', 'W copy.wld'];

        $this->assertSame('W copy 1.wld', FileCopy::find('W.wld', $before, [...$before, 'W copy 1.wld']));
    }

    public function test_ignores_other_files_that_appear_at_the_same_time(): void
    {
        $before = ['W.wld'];
        $after = ['W.wld', 'W.wld.bak', 'W copy.wld', 'tmp123'];

        $this->assertSame('W copy.wld', FileCopy::find('W.wld', $before, $after));
    }

    public function test_the_original_is_never_the_copy(): void
    {
        $this->assertNull(FileCopy::find('W.wld', [], ['W.wld']));
    }

    public function test_an_old_copy_is_not_a_new_copy(): void
    {
        $this->assertNull(FileCopy::find('W.wld', ['W.wld', 'W copy.wld'], ['W.wld', 'W copy.wld']));
    }

    public function test_a_copy_of_another_file_is_not_matched(): void
    {
        $this->assertNull(FileCopy::find('W.wld', ['W.wld'], ['W.wld', 'Other copy.wld', 'W copy.twld']));
    }

    public function test_two_possible_copies_are_not_guessed(): void
    {
        $this->assertNull(FileCopy::find('W.wld', ['W.wld'], ['W.wld', 'W copy.wld', 'W copy 1.wld']));
    }

    public function test_names_with_regex_characters_and_no_extension(): void
    {
        $this->assertSame('a.b (1) copy.wld', FileCopy::find('a.b (1).wld', [], ['a.b (1) copy.wld']));
        $this->assertSame('notes copy', FileCopy::find('notes', [], ['notes copy']));
        $this->assertNull(FileCopy::find('a+b.wld', [], ['aab copy.wld']));
    }

    public function test_tar_archives_keep_their_double_extension(): void
    {
        $this->assertSame('x copy.tar.gz', FileCopy::find('x.tar.gz', [], ['x copy.tar.gz']));
    }
}
