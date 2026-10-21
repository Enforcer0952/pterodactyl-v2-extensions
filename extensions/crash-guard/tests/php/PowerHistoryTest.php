<?php

use CrashGuard\Services\PowerHistory;
use PHPUnit\Framework\TestCase;

class PowerHistoryTest extends TestCase
{
    public function test_stop_commands_typed_in_the_console_explain_a_stop(): void
    {
        foreach (['stop', '/stop', 'restart', 'STOP', 'exit', 'save-and-stop', 'doexit', '  stop  '] as $command) {
            $this->assertTrue(PowerHistory::isStopCommand(json_encode(['command' => $command])), $command);
        }
        $this->assertTrue(PowerHistory::isStopCommand(['command' => 'quit']));
    }

    public function test_other_commands_do_not(): void
    {
        foreach (['say stop it', 'list', 'stopwatch', 'tp Notch 0 0 0', ''] as $command) {
            $this->assertFalse(PowerHistory::isStopCommand(json_encode(['command' => $command])), $command);
        }
        $this->assertFalse(PowerHistory::isStopCommand(null));
        $this->assertFalse(PowerHistory::isStopCommand('not json'));
    }
}
