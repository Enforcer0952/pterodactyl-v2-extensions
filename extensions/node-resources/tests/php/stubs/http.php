<?php

// Stand-ins for the panel's HTTP client exceptions, so the pure classes can be tested without the panel.

namespace Illuminate\Http\Client {
    if (! class_exists(ConnectionException::class)) {
        class ConnectionException extends \RuntimeException {}
    }
    if (! class_exists(RequestException::class)) {
        class RequestException extends \RuntimeException
        {
            public $response = null;
        }
    }
}
