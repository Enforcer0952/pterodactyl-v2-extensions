<?php

use McPlayerData\Support\Nbt\Limits;
use McPlayerData\Support\Nbt\Mutf8;
use McPlayerData\Support\Nbt\NbtDocument;
use McPlayerData\Support\Nbt\NbtException;
use McPlayerData\Support\Nbt\Reader;
use McPlayerData\Support\Nbt\Tag;
use McPlayerData\Support\Nbt\Writer;
use PHPUnit\Framework\TestCase;

final class NbtTest extends TestCase
{
    /** A compound with one of every tag type, plus nested and empty lists. */
    private function everything(): NbtDocument
    {
        $root = Tag::compound([
            'byte' => Tag::byte(-5),
            'short' => Tag::short(-1234),
            'int' => Tag::int(-2000000000),
            'long' => Tag::long(PHP_INT_MIN),
            'float' => Tag::float(1.5),
            'double' => Tag::double(-2.25),
            'bytes' => Tag::byteArray("\x00\x01\xFF\x80"),
            'string' => Tag::string("Zażółć \u{1F600} gęślą jaźń\0"),
            'ints' => Tag::intArray([1, -1, 2147483647, -2147483648]),
            'longs' => Tag::longArray([1, -1, PHP_INT_MAX, PHP_INT_MIN]),
            'empty_ints' => Tag::intArray([]),
            'empty_longs' => Tag::longArray([]),
            'empty_bytes' => Tag::byteArray(''),
            'list_byte' => Tag::list(Tag::BYTE, [1, -2, 127]),
            'list_short' => Tag::list(Tag::SHORT, [1, -2, 32767]),
            'list_int' => Tag::list(Tag::INT, [1, -2, 2147483647]),
            'list_long' => Tag::list(Tag::LONG, [1, -2, PHP_INT_MAX]),
            'list_float' => Tag::list(Tag::FLOAT, [0.5, -0.25]),
            'list_double' => Tag::list(Tag::DOUBLE, [0.1, -0.2]),
            'list_string' => Tag::list(Tag::STRING, ['a', Mutf8::encode('é')]),
            'list_bytearray' => Tag::list(Tag::BYTE_ARRAY, ["\x01\x02", '']),
            'list_intarray' => Tag::list(Tag::INT_ARRAY, [[1, 2], []]),
            'list_longarray' => Tag::list(Tag::LONG_ARRAY, [[PHP_INT_MIN], [3, 4]]),
            'list_compound' => Tag::list(Tag::COMPOUND, [Tag::compound(['a' => Tag::byte(1)]), Tag::compound()]),
            'list_list' => Tag::list(Tag::LIST, [Tag::list(Tag::INT, [1]), Tag::list(Tag::END)]),
            'empty_list' => Tag::list(Tag::END),
            'nested' => Tag::compound(['inner' => Tag::compound(['deep' => Tag::string('x')])]),
        ]);

        return new NbtDocument($root, 'Root name', false);
    }

    public function test_every_tag_type_round_trips_raw_and_gzip(): void
    {
        $document = $this->everything();

        foreach ([false, true] as $gzip) {
            $document->gzip = $gzip;
            $bytes = Writer::writeFile($document);
            $this->assertSame($gzip, str_starts_with($bytes, "\x1F\x8B"));

            $read = Reader::readFile($bytes);
            $this->assertSame($gzip, $read->gzip);
            $this->assertSame('Root name', $read->name);
            $this->assertEquals($document->root, $read->root);

            // Writing again gives the same NBT bytes.
            $this->assertSame(Writer::writeDocument($document), Writer::writeDocument($read));
        }
    }

    public function test_reads_the_hello_world_example_from_the_nbt_spec(): void
    {
        $bytes = hex2bin('0a000b68656c6c6f20776f726c640800046e616d65000942616e616e72616d6100');
        $document = Reader::readFile($bytes);

        $this->assertSame('hello world', $document->name);
        $this->assertSame('Bananrama', $document->root->get('name')->text());
        $this->assertFalse($document->gzip);
        $this->assertSame($bytes, Writer::writeFile($document));
    }

    public function test_hand_built_bytes_are_big_endian_and_byte_identical(): void
    {
        // compound "" { byte a=-1, short b=258, int c=-2, long d=1, float e=1.0, double f=2.0, list g<short>=[1,-1], longarray h=[5] }
        $hex = '0a0000'
            .'01000161ff'
            .'0200016201 02'
            .'03000163fffffffe'
            .'0400016400000000 00000001'
            .'05000165 3f800000'
            .'06000166 4000000000000000'
            .'09000167 02 00000002 0001 ffff'
            .'0c000168 00000001 0000000000000005'
            .'00';
        $bytes = hex2bin(str_replace(' ', '', $hex));
        $document = Reader::readDocument($bytes);
        $root = $document->root;

        $this->assertSame(-1, $root->get('a')->value);
        $this->assertSame(258, $root->get('b')->value);
        $this->assertSame(-2, $root->get('c')->value);
        $this->assertSame(1, $root->get('d')->value);
        $this->assertSame(1.0, $root->get('e')->value);
        $this->assertSame(2.0, $root->get('f')->value);
        $this->assertSame([1, -1], $root->get('g')->value);
        $this->assertSame([5], $root->get('h')->value);
        $this->assertSame($bytes, Writer::writeDocument($document));
    }

    public function test_an_empty_list_keeps_its_declared_type(): void
    {
        $bytes = hex2bin('0a0000'.'09000161'.'0a'.'00000000'.'09000162'.'00'.'00000000'.'00');
        $document = Reader::readDocument($bytes);

        $this->assertSame(Tag::COMPOUND, $document->root->get('a')->elementType);
        $this->assertSame(Tag::END, $document->root->get('b')->elementType);
        $this->assertSame($bytes, Writer::writeDocument($document));
    }

    public function test_tag_order_and_numeric_looking_names_are_kept(): void
    {
        $root = Tag::compound(['z' => Tag::byte(1), '10' => Tag::byte(2), 'a' => Tag::byte(3), '2' => Tag::byte(4)]);
        $bytes = Writer::writeDocument(new NbtDocument($root, '', false));
        $read = Reader::readDocument($bytes);

        $this->assertSame(['z', '10', 'a', '2'], array_map('strval', array_keys($read->root->value)));
        $this->assertSame($bytes, Writer::writeDocument($read));
    }

    public function test_strings_are_kept_as_the_exact_bytes(): void
    {
        // An unpaired surrogate is valid in modified UTF-8 and has no UTF-8 form.
        $raw = "\xED\xA0\x80 and \xC0\x80";
        $root = Tag::compound(['s' => Tag::rawString($raw)]);
        $bytes = Writer::writeDocument(new NbtDocument($root, '', false));
        $read = Reader::readDocument($bytes);

        $this->assertSame($raw, $read->root->get('s')->value);
        $this->assertSame($bytes, Writer::writeDocument($read));
        $this->assertSame("\u{FFFD} and \0", $read->root->get('s')->text());
    }

    public function test_modified_utf8(): void
    {
        $this->assertSame("\xC0\x80", Mutf8::encode("\0"));
        $this->assertSame('plain', Mutf8::encode('plain'));
        $this->assertSame("\xC3\xA9", Mutf8::encode('é'));
        // U+1F600 is the surrogate pair D83D DE00, three bytes each.
        $this->assertSame("\xED\xA0\xBD\xED\xB8\x80", Mutf8::encode("\u{1F600}"));
        $this->assertSame("\u{1F600}", Mutf8::decode("\xED\xA0\xBD\xED\xB8\x80"));
        $this->assertSame("\0", Mutf8::decode("\xC0\x80"));
        $this->assertSame("a\u{FFFD}b", Mutf8::decode("a\xFFb"));
        $this->assertSame("\u{FFFD}\u{FFFD}", Mutf8::decode("\xE2\x82"));
        foreach (["\u{20AC} euro", 'ASCII', "mix \u{1F4A9} \u{00FC}\0end", ''] as $text) {
            $this->assertSame($text, Mutf8::decode(Mutf8::encode($text)));
        }
        $this->expectException(NbtException::class);
        Mutf8::encode("\xFF\xFE");
    }

    public function test_out_of_range_numbers_are_refused(): void
    {
        foreach ([fn () => Tag::byte(128), fn () => Tag::short(32768), fn () => Tag::int(2147483648)] as $make) {
            try {
                $make();
                $this->fail('Expected an exception.');
            } catch (NbtException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(NbtException::class);
        Writer::writeDocument(new NbtDocument(Tag::compound(['x' => new Tag(Tag::INT, 5_000_000_000)]), '', false));
    }

    public function test_a_string_longer_than_65535_bytes_cannot_be_written(): void
    {
        $this->expectException(NbtException::class);
        Writer::writeDocument(new NbtDocument(Tag::compound(['x' => Tag::string(str_repeat('a', 65536))]), '', false));
    }

    // Hostile and broken input.

    /** @return array<string, array{string}> */
    public static function brokenFiles(): array
    {
        $head = '0a0000';

        return [
            'empty' => [''],
            'only a type byte' => ['0a'],
            'end as root' => ['00'],
            'root is not a compound' => ['010000'.'05'],
            'truncated name' => ['0a000a6162'],
            'truncated int' => ['0a0000'.'03000161'.'0000'],
            'missing end tag' => [$head.'01000161ff'],
            'unknown tag type' => [$head.'0d000161'],
            'negative list length' => [$head.'09000161'.'01'.'ffffffff'.'00'],
            'huge list length' => [$head.'09000161'.'01'.'7fffffff'.'00'],
            'list longer than the data' => [$head.'09000161'.'03'.'00000064'.'00000001'.'00'],
            'list of ends with entries' => [$head.'09000161'.'00'.'00000001'.'00'],
            'list with unknown element type' => [$head.'09000161'.'0d'.'00000001'.'00'],
            'negative byte array length' => [$head.'07000161'.'ffffff00'.'00'],
            'huge byte array length' => [$head.'07000161'.'7fffffff'.'00'],
            'negative int array length' => [$head.'0b000161'.'80000000'.'00'],
            'huge int array length' => [$head.'0b000161'.'3fffffff'.'00'],
            'huge long array length' => [$head.'0c000161'.'0fffffff'.'00'],
            'truncated string' => [$head.'08000161'.'0010'.'6162'],
            'list of strings cut short' => [$head.'09000161'.'08'.'00000003'.'0001'.'61'],
            'list of compounds cut short' => [$head.'09000161'.'0a'.'00000002'.'00'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('brokenFiles')]
    public function test_broken_input_fails_cleanly(string $hex): void
    {
        $this->expectException(NbtException::class);
        Reader::readFile(hex2bin($hex));
    }

    public function test_nesting_deeper_than_the_limit_fails_without_blowing_the_stack(): void
    {
        foreach (['compound', 'list'] as $kind) {
            $depth = 100000;
            $bytes = $kind === 'compound'
                ? "\x0A\0\0".str_repeat("\x0A\0\0", $depth)
                : "\x0A\0\0\x09\0\0".str_repeat("\x09\x00\x00\x00\x01", $depth);

            try {
                Reader::readDocument($bytes);
                $this->fail('Expected an exception for '.$kind);
            } catch (NbtException $exception) {
                $this->assertStringContainsString('deeper', $exception->getMessage());
            }
        }
    }

    public function test_the_nesting_limit_is_exactly_512(): void
    {
        $build = function (int $levels): string {
            // levels compounds in total, the root included.
            return "\x0A\0\0".str_repeat("\x0A\0\1a", $levels - 1).str_repeat("\0", $levels);
        };

        $this->assertInstanceOf(NbtDocument::class, Reader::readDocument($build(512)));
        $this->expectException(NbtException::class);
        Reader::readDocument($build(513));
    }

    public function test_size_limits(): void
    {
        $document = new NbtDocument(Tag::compound(['x' => Tag::byteArray(str_repeat('a', 1000))]), '', false);
        $bytes = Writer::writeFile($document);

        $this->assertInstanceOf(NbtDocument::class, Reader::readFile($bytes, new Limits(maxBytes: 2000)));
        $this->expectException(NbtException::class);
        Reader::readFile($bytes, new Limits(maxBytes: 500));
    }

    public function test_list_length_limit(): void
    {
        $document = new NbtDocument(Tag::compound(['x' => Tag::list(Tag::INT, range(1, 50))]), '', false);
        $bytes = Writer::writeDocument($document);

        $this->assertInstanceOf(NbtDocument::class, Reader::readDocument($bytes, new Limits(maxListLength: 50)));
        $this->expectException(NbtException::class);
        Reader::readDocument($bytes, new Limits(maxListLength: 49));
    }

    public function test_entry_count_limit(): void
    {
        $children = [];
        for ($i = 0; $i < 100; $i++) {
            $children['k'.$i] = Tag::byte(1);
        }
        $bytes = Writer::writeDocument(new NbtDocument(Tag::compound($children), '', false));

        $this->assertInstanceOf(NbtDocument::class, Reader::readDocument($bytes, new Limits(maxNodes: 100)));
        $this->expectException(NbtException::class);
        Reader::readDocument($bytes, new Limits(maxNodes: 99));
    }

    public function test_a_gzip_bomb_is_stopped_while_inflating(): void
    {
        $bomb = gzencode("\x0A\0\0\x07\0\1a\x00\x40\x00\x00".str_repeat("\0", 64 * 1024 * 1024).'x', 9);
        $this->assertLessThan(200000, strlen($bomb));

        $before = memory_get_peak_usage(true);
        try {
            Reader::readFile($bomb, new Limits(maxBytes: 1024 * 1024));
            $this->fail('Expected an exception.');
        } catch (NbtException $exception) {
            $this->assertStringContainsString('larger', $exception->getMessage());
        }
        $this->assertLessThan(48 * 1024 * 1024, memory_get_peak_usage(true) - $before);
    }

    public function test_corrupt_gzip_fails_cleanly(): void
    {
        $good = Writer::writeFile(new NbtDocument(Tag::compound(['x' => Tag::string(str_repeat('abc', 100))]), '', true));

        foreach ([substr($good, 0, 20), substr($good, 0, -8), "\x1F\x8B".str_repeat("\x07", 40), $good] as $index => $bytes) {
            if ($index === 3) {
                $bytes[12] = chr(ord($bytes[12]) ^ 0xFF);
            }

            try {
                Reader::readFile($bytes);
                $this->fail('Expected an exception for sample '.$index);
            } catch (NbtException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_random_garbage_never_throws_anything_else(): void
    {
        mt_srand(7);
        for ($i = 0; $i < 400; $i++) {
            $bytes = '';
            $length = mt_rand(0, 120);
            for ($j = 0; $j < $length; $j++) {
                $bytes .= chr(mt_rand(0, 255));
            }
            // Start like a real file half of the time so the parser gets further.
            if ($i % 2 === 0) {
                $bytes = "\x0A\0\0".$bytes;
            }

            try {
                Reader::readFile($bytes);
            } catch (NbtException) {
            }
            $this->addToAssertionCount(1);
        }
    }

    public function test_truncating_a_valid_file_at_every_length_fails_cleanly(): void
    {
        $bytes = Writer::writeDocument($this->everything());
        for ($length = 0; $length < strlen($bytes); $length++) {
            try {
                Reader::readDocument(substr($bytes, 0, $length));
                $this->fail('Expected an exception at length '.$length);
            } catch (NbtException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_to_php_for_display(): void
    {
        $php = $this->everything()->root->toPhp();

        $this->assertSame(-5, $php['byte']);
        $this->assertSame([1, -1, 2147483647, -2147483648], $php['ints']);
        $this->assertSame(['a', 'é'], $php['list_string']);
        $this->assertSame([['a' => 1], []], $php['list_compound']);
        $this->assertSame('x', $php['nested']['inner']['deep']);
        $this->assertSame([0, 1, -1, -128], $php['bytes']);
    }
}
