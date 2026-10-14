<?php

namespace PalworldTools\Services;

use RuntimeException;

/** The REST API could not be used. $reason is one of the codes the Players card understands. */
class RestException extends RuntimeException
{
    public const string OFFLINE = 'offline';

    public const string DISABLED = 'disabled';

    public const string NOT_ALLOCATED = 'not_allocated';

    public const string NO_PASSWORD = 'no_password';

    public const string UNREACHABLE = 'unreachable';

    public const string AUTH = 'auth';

    public const string FAILED = 'failed';

    public function __construct(public readonly string $reason, string $message = '', public readonly ?int $status = null)
    {
        parent::__construct($message);
    }
}
