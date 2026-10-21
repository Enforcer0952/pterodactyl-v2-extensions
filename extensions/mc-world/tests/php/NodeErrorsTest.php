<?php

use McWorld\Support\NodeErrors;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NodeErrorsTest extends TestCase
{
    #[DataProvider('cases')]
    public function testSeparatesFailuresThatMayStillRun(int $status, bool $noResponse, bool $expected): void
    {
        $this->assertSame($expected, NodeErrors::mayStillRun($status, $noResponse));
    }

    public static function cases(): array
    {
        return [
            'gateway timeout' => [504, false, true],
            'no answer at all' => [500, true, true],
            'bad gateway' => [502, false, true],
            'unavailable' => [503, false, true],
            'status zero' => [0, false, true],
            'node said not found' => [404, false, false],
            'node said internal error' => [500, false, false],
            'node said bad request' => [400, false, false],
        ];
    }
}
