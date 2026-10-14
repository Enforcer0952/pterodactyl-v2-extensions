<?php

use PHPUnit\Framework\TestCase;
use ValheimTools\Support\ConsoleDelta;

class ConsoleDeltaTest extends TestCase
{
    public function test_since(): void
    {
        $this->assertSame([], ConsoleDelta::since(['a', 'b'], ['a', 'b']));
        $this->assertSame(['c'], ConsoleDelta::since(['a', 'b'], ['a', 'b', 'c']));
        $this->assertSame(['d'], ConsoleDelta::since(['a', 'b', 'c'], ['b', 'c', 'd']));
        $this->assertSame(['x', 'y'], ConsoleDelta::since(['a', 'b'], ['x', 'y']));
        $this->assertSame(['x'], ConsoleDelta::since([], ['x']));
        $this->assertSame([], ConsoleDelta::since(['a'], []));
    }

    public function test_log_that_scrolled(): void
    {
        $this->assertSame(['d', 'e'], ConsoleDelta::since(['a', 'b', 'c'], ['c', 'd', 'e']));
    }
}
