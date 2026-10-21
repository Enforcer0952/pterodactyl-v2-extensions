<?php

namespace NodeResources\Tests;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use NodeResources\Support\Failure;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FailureTest extends TestCase
{
    public function testAConnectionErrorMeansTheNodeDidNotAnswer(): void
    {
        $this->assertTrue(Failure::isConnection(new RuntimeException('wrapped', 0, new ConnectionException('timed out'))));
    }

    public function testARequestWithoutAResponseCountsAsNoAnswer(): void
    {
        $this->assertTrue(Failure::isConnection(new RuntimeException('wrapped', 0, new RequestException('no response'))));
    }

    public function testAnErrorAnswerIsNotAConnectionFailure(): void
    {
        $withResponse = new RequestException('HTTP 500');
        $withResponse->response = new \stdClass;

        $this->assertFalse(Failure::isConnection(new RuntimeException('wrapped', 0, $withResponse)));
        $this->assertFalse(Failure::isConnection(new RuntimeException('wrapped', 0, new \UnexpectedValueException('not json'))));
        $this->assertFalse(Failure::isConnection(new RuntimeException('no previous')));
    }
}
