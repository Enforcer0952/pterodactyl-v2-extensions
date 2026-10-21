<?php

namespace McPlayerData\Support\Nbt;

/**
 * Bounds that stop a hostile or corrupt file from exhausting memory or the stack.
 * Memory use follows the data actually present: a declared length is checked
 * against the bytes left before anything is allocated for it.
 */
final readonly class Limits
{
    /** The nesting depth the game itself allows. */
    public const int DEFAULT_DEPTH = 512;

    public const int DEFAULT_BYTES = 8 * 1024 * 1024;

    public const int DEFAULT_LIST = 1 << 20;

    public const int DEFAULT_NODES = 500_000;

    public function __construct(
        /** Nesting depth of compounds and lists. */
        public int $maxDepth = self::DEFAULT_DEPTH,
        /** Size of the data after decompression. */
        public int $maxBytes = self::DEFAULT_BYTES,
        /** Entries in one list, int array or long array. */
        public int $maxListLength = self::DEFAULT_LIST,
        /** Compound entries and compound or list elements, over the whole file. */
        public int $maxNodes = self::DEFAULT_NODES,
    ) {}
}
