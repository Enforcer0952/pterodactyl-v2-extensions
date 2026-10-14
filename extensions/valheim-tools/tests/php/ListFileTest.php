<?php

use PHPUnit\Framework\TestCase;
use ValheimTools\Support\ListFile;

class ListFileTest extends TestCase
{
    private const A = '76561198051014133';

    private const B = '76561198000000001';

    public function test_round_trip_is_lossless(): void
    {
        foreach ([
            "// List admin players ID  ONE per line\n76561198051014133\n",
            "// header\r\nSteam_76561198051014133\r\n\r\n// note\r\n",
            "76561198051014133",
            "\n\n  76561198051014133  \n\n",
            '',
        ] as $text) {
            $this->assertSame($text, ListFile::parse($text)->render(), json_encode($text));
        }
    }

    public function test_bom_is_dropped(): void
    {
        $this->assertSame(["V_".self::A], ListFile::parse("\xEF\xBB\xBFV_".self::A."\n")->entries());
    }

    public function test_entries_skip_comments_and_blank_lines(): void
    {
        $file = ListFile::parse("// c\n\nV_".self::A."\n  Steam_".self::B."  \n");
        $this->assertSame(['V_'.self::A, 'Steam_'.self::B], $file->entries());
    }

    public function test_add_keeps_the_rest(): void
    {
        $file = ListFile::parse("// header\n// more\nV_".self::A."\n");
        $this->assertTrue($file->add('V_'.self::B));
        $this->assertSame("// header\n// more\nV_".self::A."\nV_".self::B."\n", $file->render());
    }

    public function test_add_to_file_without_trailing_newline(): void
    {
        $file = ListFile::parse('V_'.self::A);
        $file->add('V_'.self::B);
        $this->assertSame("V_".self::A."\nV_".self::B."\n", $file->render());
    }

    public function test_add_keeps_crlf(): void
    {
        $file = ListFile::parse("// h\r\nV_".self::A."\r\n");
        $file->add('V_'.self::B);
        $this->assertSame("// h\r\nV_".self::A."\r\nV_".self::B."\r\n", $file->render());
    }

    public function test_add_refuses_duplicates_in_any_spelling(): void
    {
        $file = ListFile::parse('Steam_'.self::A."\n");
        $this->assertFalse($file->add('V_'.self::A));
        $this->assertFalse($file->add(self::A));
        $this->assertSame("Steam_".self::A."\n", $file->render());
    }

    public function test_add_refuses_invalid_ids_and_injection(): void
    {
        $file = ListFile::empty('banned');
        foreach (["V_".self::A."\nV_".self::B, '// x', 'Bob', '', '../x'] as $bad) {
            $this->assertFalse($file->add($bad), json_encode($bad));
        }
        $this->assertSame(ListFile::HEADERS['banned']."\n", $file->render());
    }

    public function test_remove_takes_every_spelling_and_keeps_comments(): void
    {
        $file = ListFile::parse("// keep\nV_".self::A."\nSteam_".self::A."\n".self::B."\n");
        $this->assertSame(2, $file->remove('V_'.self::A));
        $this->assertSame("// keep\n".self::B."\n", $file->render());
        $this->assertSame(0, $file->remove('V_'.self::A));
    }

    public function test_remove_does_not_touch_comment_with_id(): void
    {
        $file = ListFile::parse('// V_'.self::A."\nV_".self::A."\n");
        $file->remove(self::A);
        $this->assertSame('// V_'.self::A."\n", $file->render());
    }

    public function test_dedupe(): void
    {
        $file = ListFile::parse("V_".self::A."\nSteam_".self::A."\n// x\nV_".self::B."\n");
        $this->assertSame(1, $file->dedupe());
        $this->assertSame(['V_'.self::A, 'V_'.self::B], $file->entries());
    }

    public function test_empty_list_has_header(): void
    {
        $this->assertSame("// List admin players ID  ONE per line\n", ListFile::empty('admin')->render());
        $this->assertSame('', ListFile::empty('other')->render());
    }

    public function test_has(): void
    {
        $file = ListFile::parse('V_'.self::A."\n");
        $this->assertTrue($file->has('Steam_'.self::A));
        $this->assertFalse($file->has(self::B));
    }
}
