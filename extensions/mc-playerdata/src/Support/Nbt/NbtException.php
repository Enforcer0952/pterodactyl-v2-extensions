<?php

namespace McPlayerData\Support\Nbt;

/**
 * Raised for NBT that is malformed, too large, too deep, or cannot be written.
 * The message is safe to show to a user.
 */
class NbtException extends \RuntimeException {}
