<?php

namespace McPlayerData\Support\Nbt;

/**
 * One NBT value.
 *
 * $value by type:
 *  - BYTE, SHORT, INT, LONG: int. FLOAT, DOUBLE: float.
 *  - STRING: the bytes exactly as stored (modified UTF-8). Use text() to read it
 *    as UTF-8; keeping the raw bytes is what makes an unchanged file write back
 *    byte for byte.
 *  - BYTE_ARRAY: a binary string. INT_ARRAY, LONG_ARRAY: list<int>.
 *  - COMPOUND: array<string, Tag>, in file order.
 *  - LIST: list of elements of $elementType. Compound and list elements are Tag
 *    objects; every other element type is stored as its plain value.
 */
final class Tag
{
    public const int END = 0;

    public const int BYTE = 1;

    public const int SHORT = 2;

    public const int INT = 3;

    public const int LONG = 4;

    public const int FLOAT = 5;

    public const int DOUBLE = 6;

    public const int BYTE_ARRAY = 7;

    public const int STRING = 8;

    public const int LIST = 9;

    public const int COMPOUND = 10;

    public const int INT_ARRAY = 11;

    public const int LONG_ARRAY = 12;

    public const array NAMES = [
        self::END => 'end', self::BYTE => 'byte', self::SHORT => 'short', self::INT => 'int', self::LONG => 'long',
        self::FLOAT => 'float', self::DOUBLE => 'double', self::BYTE_ARRAY => 'byte array', self::STRING => 'string',
        self::LIST => 'list', self::COMPOUND => 'compound', self::INT_ARRAY => 'int array', self::LONG_ARRAY => 'long array',
    ];

    /**
     * @param  int  $elementType  only for LIST: the type of its elements (END when empty)
     */
    public function __construct(public int $type, public mixed $value, public int $elementType = self::END) {}

    public static function byte(int $value): self
    {
        return new self(self::BYTE, self::range($value, -128, 127, 'byte'));
    }

    public static function short(int $value): self
    {
        return new self(self::SHORT, self::range($value, -32768, 32767, 'short'));
    }

    public static function int(int $value): self
    {
        return new self(self::INT, self::range($value, -2147483648, 2147483647, 'int'));
    }

    public static function long(int $value): self
    {
        return new self(self::LONG, $value);
    }

    public static function float(float $value): self
    {
        return new self(self::FLOAT, $value);
    }

    public static function double(float $value): self
    {
        return new self(self::DOUBLE, $value);
    }

    /** From UTF-8 text. */
    public static function string(string $utf8): self
    {
        return new self(self::STRING, Mutf8::encode($utf8));
    }

    /** From bytes already in modified UTF-8. */
    public static function rawString(string $modified): self
    {
        return new self(self::STRING, $modified);
    }

    public static function byteArray(string $bytes): self
    {
        return new self(self::BYTE_ARRAY, $bytes);
    }

    /** @param list<int> $values */
    public static function intArray(array $values): self
    {
        return new self(self::INT_ARRAY, array_values($values));
    }

    /** @param list<int> $values */
    public static function longArray(array $values): self
    {
        return new self(self::LONG_ARRAY, array_values($values));
    }

    /** @param array<string|int, Tag> $children */
    public static function compound(array $children = []): self
    {
        return new self(self::COMPOUND, $children);
    }

    /**
     * @param  list<mixed>  $items  Tag objects for compound and list elements, plain values otherwise
     */
    public static function list(int $elementType, array $items = []): self
    {
        return new self(self::LIST, array_values($items), $items === [] ? self::END : $elementType);
    }

    public function is(int $type): bool
    {
        return $this->type === $type;
    }

    public function isNumber(): bool
    {
        return $this->type >= self::BYTE && $this->type <= self::DOUBLE;
    }

    /** The numeric value of any number tag as an int (floats are truncated), or null. */
    public function asInt(): ?int
    {
        if ($this->type >= self::BYTE && $this->type <= self::LONG) {
            return $this->value;
        }

        return $this->isNumber() && is_finite($this->value) ? (int) $this->value : null;
    }

    public function asFloat(): ?float
    {
        return $this->isNumber() ? (float) $this->value : null;
    }

    /** A string tag as UTF-8 text, or null for other types. */
    public function text(): ?string
    {
        return $this->type === self::STRING ? Mutf8::decode($this->value) : null;
    }

    // Compound helpers. Each is a no-op or null on other types.

    public function get(string $key): ?self
    {
        return $this->type === self::COMPOUND ? ($this->value[$key] ?? null) : null;
    }

    public function has(string $key): bool
    {
        return $this->type === self::COMPOUND && isset($this->value[$key]);
    }

    public function put(string $key, self $tag): void
    {
        if ($this->type !== self::COMPOUND) {
            throw new NbtException('Not a compound.');
        }

        $this->value[$key] = $tag;
    }

    public function remove(string $key): void
    {
        if ($this->type === self::COMPOUND) {
            unset($this->value[$key]);
        }
    }

    /**
     * The elements of a list of compounds or lists as Tag objects, [] for any other tag.
     *
     * @return list<self>
     */
    public function tags(): array
    {
        return $this->type === self::LIST && ($this->elementType === self::COMPOUND || $this->elementType === self::LIST) ? $this->value : [];
    }

    /**
     * Convert to plain PHP for display: compounds become arrays, lists become
     * lists, strings become UTF-8, and arrays stay lists of ints.
     */
    public function toPhp(int $depth = 0): mixed
    {
        if ($depth > 64) {
            return null;
        }

        return match ($this->type) {
            self::STRING => Mutf8::decode($this->value),
            self::BYTE_ARRAY => array_values(unpack('c*', $this->value) ?: []),
            self::COMPOUND => array_map(fn (self $tag): mixed => $tag->toPhp($depth + 1), array_combine(array_map('strval', array_keys($this->value)), $this->value) ?: []),
            self::LIST => array_map(
                fn (mixed $item): mixed => $item instanceof self ? $item->toPhp($depth + 1) : ($this->elementType === self::STRING ? Mutf8::decode($item) : ($this->elementType === self::BYTE_ARRAY ? array_values(unpack('c*', $item) ?: []) : $item)),
                $this->value,
            ),
            default => $this->value,
        };
    }

    private static function range(int $value, int $min, int $max, string $name): int
    {
        if ($value < $min || $value > $max) {
            throw new NbtException("$value does not fit in a $name.");
        }

        return $value;
    }
}
