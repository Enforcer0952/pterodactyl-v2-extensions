<?php

namespace McWorld\Support;

/**
 * Tells a failed node call that certainly did nothing apart from one that may still be
 * running: when the connection drops or times out, Wings can keep unpacking an archive.
 */
final class NodeErrors
{
    /**
     * @param  int  $status  status code of the exception the panel raised for the node call
     * @param  bool  $noResponse  true when the node never answered (connection error or timeout)
     */
    public static function mayStillRun(int $status, bool $noResponse): bool
    {
        return $noResponse || in_array($status, [0, 408, 502, 503, 504], true);
    }
}
