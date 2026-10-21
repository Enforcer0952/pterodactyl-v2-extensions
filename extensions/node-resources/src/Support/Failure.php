<?php

namespace NodeResources\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Throwable;

/**
 * Tells "the node did not answer" from "the node answered with an error". The panel wraps
 * every failed Wings call in one exception; the exception it wraps says which of the two it was.
 */
final class Failure
{
    /** True when there was no answer at all: refused, timed out, DNS or TLS trouble. */
    public static function isConnection(Throwable $exception): bool
    {
        $cause = $exception->getPrevious() ?? $exception;

        if ($cause instanceof ConnectionException) {
            return true;
        }

        // A request that failed without a response never reached the node's API.
        return $cause instanceof RequestException && ! isset($cause->response);
    }
}
