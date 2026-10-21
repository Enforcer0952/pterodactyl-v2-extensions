<?php

namespace McPlayerData\Support\Nbt;

/** A whole NBT file: the root tag, its name, and whether the file was gzip-compressed. */
final class NbtDocument
{
    public function __construct(
        public Tag $root,
        public string $name = '',
        public bool $gzip = true,
    ) {}
}
