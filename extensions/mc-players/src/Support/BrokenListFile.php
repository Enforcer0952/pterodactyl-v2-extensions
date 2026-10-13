<?php

namespace McPlayers\Support;

use RuntimeException;

/** A list file exists but cannot be read as a list, so it must not be overwritten. */
final class BrokenListFile extends RuntimeException
{
    public function __construct(public readonly string $listFile, string $reason)
    {
        parent::__construct(ltrim($listFile, '/').' is not valid JSON ('.$reason.'). Fix or delete it in the file manager first; it was left untouched.');
    }
}
