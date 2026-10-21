<?php

namespace McPlayerData\Support\Nbt;

/** Writes big-endian NBT, gzip-compressed or not. */
final class Writer
{
    /** The file as stored on disk: gzip-compressed when the document was. */
    public static function writeFile(NbtDocument $document): string
    {
        $data = self::writeDocument($document);
        if (! $document->gzip) {
            return $data;
        }

        $compressed = gzencode($data, 6);
        if ($compressed === false) {
            throw new NbtException('Could not compress the data.');
        }

        return $compressed;
    }

    /** Uncompressed NBT: type byte, root name, root payload. */
    public static function writeDocument(NbtDocument $document): string
    {
        $type = $document->root->type;
        if ($type !== Tag::COMPOUND && $type !== Tag::LIST) {
            throw new NbtException('The root of the NBT data must be a compound or a list.');
        }

        $out = [chr($type), self::string($document->name)];
        self::payload($document->root, $out, 1);

        return implode('', $out);
    }

    /**
     * @param  list<string>  $out
     */
    private static function payload(Tag $tag, array &$out, int $depth): void
    {
        if (($tag->type === Tag::COMPOUND || $tag->type === Tag::LIST) && $depth > Limits::DEFAULT_DEPTH) {
            throw new NbtException('The NBT data is nested too deeply to write.');
        }

        $value = $tag->value;
        switch ($tag->type) {
            case Tag::BYTE:
                $out[] = pack('c', self::int($value, -128, 127));
                break;
            case Tag::SHORT:
                $out[] = pack('n', self::int($value, -32768, 32767) & 0xFFFF);
                break;
            case Tag::INT:
                $out[] = pack('N', self::int($value, -2147483648, 2147483647) & 0xFFFFFFFF);
                break;
            case Tag::LONG:
                $out[] = pack('J', self::int($value, PHP_INT_MIN, PHP_INT_MAX));
                break;
            case Tag::FLOAT:
                $out[] = pack('G', $value);
                break;
            case Tag::DOUBLE:
                $out[] = pack('E', $value);
                break;
            case Tag::BYTE_ARRAY:
                $out[] = pack('N', strlen($value)).$value;
                break;
            case Tag::STRING:
                $out[] = self::string($value);
                break;
            case Tag::INT_ARRAY:
                $out[] = pack('N', count($value));
                foreach ($value as $v) {
                    $out[] = pack('N', self::int($v, -2147483648, 2147483647) & 0xFFFFFFFF);
                }
                break;
            case Tag::LONG_ARRAY:
                $out[] = pack('N', count($value));
                foreach ($value as $v) {
                    $out[] = pack('J', self::int($v, PHP_INT_MIN, PHP_INT_MAX));
                }
                break;
            case Tag::COMPOUND:
                foreach ($value as $name => $child) {
                    $out[] = chr($child->type).self::string((string) $name);
                    self::payload($child, $out, $depth + 1);
                }
                $out[] = "\0";
                break;
            case Tag::LIST:
                self::list($tag, $out, $depth);
                break;
            default:
                throw new NbtException('Cannot write a tag of type '.$tag->type.'.');
        }
    }

    /**
     * @param  list<string>  $out
     */
    private static function list(Tag $tag, array &$out, int $depth): void
    {
        $items = $tag->value;
        $type = $tag->elementType;
        if ($items !== [] && $type === Tag::END) {
            throw new NbtException('A list has entries but no type.');
        }

        $out[] = chr($type).pack('N', count($items));
        foreach ($items as $item) {
            if ($type === Tag::COMPOUND || $type === Tag::LIST) {
                if (! $item instanceof Tag || $item->type !== $type) {
                    throw new NbtException('A list holds an entry of the wrong type.');
                }
                self::payload($item, $out, $depth + 1);
            } else {
                self::payload(new Tag($type, $item), $out, $depth + 1);
            }
        }
    }

    private static function string(string $modified): string
    {
        if (strlen($modified) > 65535) {
            throw new NbtException('A string is longer than 65535 bytes.');
        }

        return pack('n', strlen($modified)).$modified;
    }

    private static function int(mixed $value, int $min, int $max): int
    {
        if (! is_int($value) || $value < $min || $value > $max) {
            throw new NbtException('A number is outside the range of its type.');
        }

        return $value;
    }
}
