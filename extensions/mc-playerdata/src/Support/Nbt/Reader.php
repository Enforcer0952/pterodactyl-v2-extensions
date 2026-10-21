<?php

namespace McPlayerData\Support\Nbt;

/**
 * Reads big-endian NBT, gzip-compressed or not. Every length is checked against
 * the bytes that are really there before anything is allocated, so a file that
 * claims a huge list cannot use more memory than its own size.
 */
final class Reader
{
    /** Smallest number of bytes one element of each type takes in a list. */
    private const array MIN_SIZE = [
        Tag::BYTE => 1, Tag::SHORT => 2, Tag::INT => 4, Tag::LONG => 8, Tag::FLOAT => 4, Tag::DOUBLE => 8,
        Tag::BYTE_ARRAY => 4, Tag::STRING => 2, Tag::LIST => 5, Tag::COMPOUND => 1, Tag::INT_ARRAY => 4, Tag::LONG_ARRAY => 4,
    ];

    private string $data = '';

    private int $pos = 0;

    private int $length = 0;

    private int $nodes = 0;

    private int $elements = 0;

    private function __construct(private readonly Limits $limits) {}

    /** Read a file's bytes, which may or may not be gzip-compressed. */
    public static function readFile(string $bytes, ?Limits $limits = null): NbtDocument
    {
        $limits ??= new Limits;
        $gzip = self::isGzip($bytes);
        $document = self::readDocument($gzip ? self::inflate($bytes, $limits->maxBytes) : $bytes, $limits);
        $document->gzip = $gzip;

        return $document;
    }

    public static function isGzip(string $bytes): bool
    {
        return strlen($bytes) >= 2 && $bytes[0] === "\x1F" && $bytes[1] === "\x8B";
    }

    /** The NBT bytes of a file, decompressed when it is gzip. */
    public static function uncompressed(string $bytes, ?Limits $limits = null): string
    {
        return self::isGzip($bytes) ? self::inflate($bytes, ($limits ?? new Limits)->maxBytes) : $bytes;
    }

    /** Read uncompressed NBT: a type byte, the root name, then the root tag. */
    public static function readDocument(string $data, ?Limits $limits = null): NbtDocument
    {
        $limits ??= new Limits;
        if (strlen($data) > $limits->maxBytes) {
            throw new NbtException('The NBT data is larger than the allowed '.$limits->maxBytes.' bytes.');
        }

        $reader = new self($limits);
        $reader->data = $data;
        $reader->length = strlen($data);

        $type = $reader->byteAt();
        if ($type === Tag::END) {
            throw new NbtException('The NBT data has no root tag.');
        }
        if ($type !== Tag::COMPOUND && $type !== Tag::LIST) {
            throw new NbtException('The root of the NBT data must be a compound or a list.');
        }

        $name = $reader->rawString();
        $root = $reader->payload($type, 1);

        return new NbtDocument($root, $name, false);
    }

    /** gzip to bytes, stopping as soon as the result would pass $max. */
    public static function inflate(string $compressed, int $max): string
    {
        $context = @inflate_init(ZLIB_ENCODING_GZIP);
        if ($context === false) {
            throw new NbtException('Could not start decompressing.');
        }

        $out = '';
        $failed = null;
        set_error_handler(function (int $no, string $message) use (&$failed): bool {
            $failed = $message;

            return true;
        });

        try {
            $total = strlen($compressed);
            for ($offset = 0; $offset < $total; $offset += 8192) {
                $chunk = substr($compressed, $offset, 8192);
                $piece = inflate_add($context, $chunk, $offset + 8192 >= $total ? ZLIB_FINISH : ZLIB_SYNC_FLUSH);
                if ($piece === false || $failed !== null) {
                    throw new NbtException('The file is not valid gzip data.');
                }

                $out .= $piece;
                if (strlen($out) > $max) {
                    throw new NbtException('The data is larger than the allowed '.$max.' bytes once decompressed.');
                }
            }

            if ($total === 0 || inflate_get_status($context) !== ZLIB_STREAM_END) {
                throw new NbtException('The gzip data is incomplete.');
            }
        } finally {
            restore_error_handler();
        }

        return $out;
    }

    private function payload(int $type, int $depth): Tag
    {
        // Only compounds and lists nest.
        if (($type === Tag::COMPOUND || $type === Tag::LIST) && $depth > $this->limits->maxDepth) {
            throw new NbtException('The NBT data is nested deeper than '.$this->limits->maxDepth.' levels.');
        }

        switch ($type) {
            case Tag::BYTE:
                $v = $this->byteAt();

                return new Tag($type, $v >= 128 ? $v - 256 : $v);
            case Tag::SHORT:
                return new Tag($type, $this->shortValue());
            case Tag::INT:
                return new Tag($type, $this->intValue());
            case Tag::LONG:
                return new Tag($type, $this->longValue());
            case Tag::FLOAT:
                return new Tag($type, $this->floatValue());
            case Tag::DOUBLE:
                return new Tag($type, $this->doubleValue());
            case Tag::BYTE_ARRAY:
                $count = $this->arrayLength(1);

                return new Tag($type, $this->take($count));
            case Tag::STRING:
                return new Tag($type, $this->rawString());
            case Tag::INT_ARRAY:
                $count = $this->arrayLength(4);
                $this->countElements($count);

                return new Tag($type, $count === 0 ? [] : array_map(fn (int $v): int => $v >= 0x80000000 ? $v - 0x100000000 : $v, array_values(unpack('N'.$count, $this->take($count * 4)) ?: [])));
            case Tag::LONG_ARRAY:
                $count = $this->arrayLength(8);
                $this->countElements($count);

                return new Tag($type, $count === 0 ? [] : array_values(unpack('J'.$count, $this->take($count * 8)) ?: []));
            case Tag::LIST:
                return $this->listPayload($depth);
            case Tag::COMPOUND:
                return $this->compoundPayload($depth);
        }

        throw new NbtException('Unknown tag type '.$type.'.');
    }

    private function compoundPayload(int $depth): Tag
    {
        $children = [];
        while (true) {
            $type = $this->byteAt();
            if ($type === Tag::END) {
                break;
            }

            $name = $this->rawString();
            $this->countNode();
            $children[$name] = $this->payload($type, $depth + 1);
        }

        return new Tag(Tag::COMPOUND, $children);
    }

    private function listPayload(int $depth): Tag
    {
        $elementType = $this->byteAt();
        $count = $this->intValue();
        if ($count < 0) {
            throw new NbtException('A list has a negative length.');
        }
        if ($count > $this->limits->maxListLength) {
            throw new NbtException('A list is longer than the allowed '.$this->limits->maxListLength.' entries.');
        }
        if ($elementType === Tag::END) {
            if ($count > 0) {
                throw new NbtException('A list has entries but no type.');
            }

            return new Tag(Tag::LIST, [], Tag::END);
        }
        if (! isset(self::MIN_SIZE[$elementType])) {
            throw new NbtException('A list has an unknown element type '.$elementType.'.');
        }
        if ($count * self::MIN_SIZE[$elementType] > $this->length - $this->pos) {
            throw new NbtException('The NBT data ends in the middle of a list.');
        }

        // Compounds and nested lists are counted as nodes; everything else here.
        if ($elementType !== Tag::COMPOUND && $elementType !== Tag::LIST && $elementType !== Tag::INT_ARRAY && $elementType !== Tag::LONG_ARRAY) {
            $this->countElements($count);
        }

        $items = [];
        switch ($elementType) {
            case Tag::BYTE:
                $items = $count === 0 ? [] : array_values(unpack('c'.$count, $this->take($count)) ?: []);
                break;
            case Tag::SHORT:
                $items = $count === 0 ? [] : array_map(fn (int $v): int => $v >= 0x8000 ? $v - 0x10000 : $v, array_values(unpack('n'.$count, $this->take($count * 2)) ?: []));
                break;
            case Tag::INT:
                $items = $count === 0 ? [] : array_map(fn (int $v): int => $v >= 0x80000000 ? $v - 0x100000000 : $v, array_values(unpack('N'.$count, $this->take($count * 4)) ?: []));
                break;
            case Tag::LONG:
                $items = $count === 0 ? [] : array_values(unpack('J'.$count, $this->take($count * 8)) ?: []);
                break;
            case Tag::FLOAT:
                $items = $count === 0 ? [] : array_values(unpack('G'.$count, $this->take($count * 4)) ?: []);
                break;
            case Tag::DOUBLE:
                $items = $count === 0 ? [] : array_values(unpack('E'.$count, $this->take($count * 8)) ?: []);
                break;
            case Tag::STRING:
                for ($i = 0; $i < $count; $i++) {
                    $items[] = $this->rawString();
                }
                break;
            case Tag::BYTE_ARRAY:
                for ($i = 0; $i < $count; $i++) {
                    $items[] = $this->take($this->arrayLength(1));
                }
                break;
            default:
                // Compound, list, int array and long array elements.
                for ($i = 0; $i < $count; $i++) {
                    $this->countNode();
                    $element = $this->payload($elementType, $depth + 1);
                    $items[] = $elementType === Tag::COMPOUND || $elementType === Tag::LIST ? $element : $element->value;
                }
        }

        return new Tag(Tag::LIST, $items, $elementType);
    }

    /** Lists of plain values are cheap per byte but costly in memory: they share one budget. */
    private function countElements(int $count): void
    {
        $this->elements += $count;
        if ($this->elements > $this->limits->maxElements) {
            throw new NbtException('The NBT data has more than '.$this->limits->maxElements.' list entries in total.');
        }
    }

    private function countNode(): void
    {
        if (++$this->nodes > $this->limits->maxNodes) {
            throw new NbtException('The NBT data has more than '.$this->limits->maxNodes.' entries.');
        }
    }

    private function arrayLength(int $elementSize): int
    {
        $count = $this->intValue();
        if ($count < 0) {
            throw new NbtException('An array has a negative length.');
        }
        if ($elementSize > 1 && $count > $this->limits->maxListLength) {
            throw new NbtException('An array is longer than the allowed '.$this->limits->maxListLength.' entries.');
        }
        if ($count * $elementSize > $this->length - $this->pos) {
            throw new NbtException('The NBT data ends in the middle of an array.');
        }

        return $count;
    }

    private function take(int $count): string
    {
        if ($count < 0 || $this->pos + $count > $this->length) {
            throw new NbtException('The NBT data ends too early.');
        }

        $bytes = substr($this->data, $this->pos, $count);
        $this->pos += $count;

        return $bytes;
    }

    private function byteAt(): int
    {
        if ($this->pos >= $this->length) {
            throw new NbtException('The NBT data ends too early.');
        }

        return ord($this->data[$this->pos++]);
    }

    private function shortValue(): int
    {
        $v = unpack('n', $this->take(2))[1];

        return $v >= 0x8000 ? $v - 0x10000 : $v;
    }

    private function intValue(): int
    {
        $v = unpack('N', $this->take(4))[1];

        return $v >= 0x80000000 ? $v - 0x100000000 : $v;
    }

    private function longValue(): int
    {
        return unpack('J', $this->take(8))[1];
    }

    private function floatValue(): float
    {
        return unpack('G', $this->take(4))[1];
    }

    private function doubleValue(): float
    {
        return unpack('E', $this->take(8))[1];
    }

    private function rawString(): string
    {
        return $this->take(unpack('n', $this->take(2))[1]);
    }
}
