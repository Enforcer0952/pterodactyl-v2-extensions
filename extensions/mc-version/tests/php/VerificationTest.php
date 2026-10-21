<?php

namespace McVersion\Tests;

use McVersion\Support\Verification;
use McVersion\Support\Warnings;
use PHPUnit\Framework\TestCase;

final class VerificationTest extends TestCase
{
    public function test_tiny_files_are_not_jars(): void
    {
        $this->assertNotNull(Verification::sizeProblem(512, null));
        $this->assertNotNull(Verification::sizeProblem(Verification::MIN_JAR_BYTES - 1, null));
        $this->assertNull(Verification::sizeProblem(Verification::MIN_JAR_BYTES, null));
    }

    public function test_size_must_match_what_was_announced(): void
    {
        $this->assertNull(Verification::sizeProblem(50_000, 50_000));
        $this->assertNotNull(Verification::sizeProblem(50_000, 49_999));
        $this->assertNull(Verification::sizeProblem(50_000, null));
        $this->assertNull(Verification::sizeProblem(50_000, 0));
    }

    public function test_header_must_be_a_zip(): void
    {
        $this->assertNull(Verification::headerProblem("PK\x03\x04rest"));
        $this->assertNotNull(Verification::headerProblem('<html>error</html>'));
        $this->assertNotNull(Verification::headerProblem(''));
        $this->assertNotNull(Verification::headerProblem("PK\x05\x06"));
    }

    public function test_plan_says_when_a_large_jar_is_not_verified(): void
    {
        $large = Verification::describe(Verification::READ_MAX_BYTES + 1, true);
        $this->assertFalse($large['content']);
        $this->assertStringContainsString('Not verified', $large['note']);

        $small = Verification::describe(1_000_000, true);
        $this->assertTrue($small['content']);
        $this->assertStringContainsString('checksum', $small['note']);

        $unknown = Verification::describe(null, false);
        $this->assertTrue($unknown['content']);
        $this->assertStringContainsString('no published checksum', $unknown['note']);
    }

    public function test_content_length_is_read_from_any_header_case(): void
    {
        $this->assertSame(1234, Verification::contentLength(['Content-Length' => '1234']));
        $this->assertSame(1234, Verification::contentLength(['content-length' => ['1234']]));
        $this->assertNull(Verification::contentLength(['Content-Length' => '-5']));
        $this->assertNull(Verification::contentLength(['Content-Length' => '12 34']));
        $this->assertNull(Verification::contentLength(['Content-Length' => '0']));
        $this->assertNull(Verification::contentLength(['Content-Type' => 'application/java-archive']));
        $this->assertNull(Verification::contentLength([]));
    }

    public function test_downgrades_and_family_switches_need_a_backup(): void
    {
        $this->assertTrue(Verification::needsBackup(Warnings::for('paper', '1.21.4', 'paper', '1.20.6')));
        $this->assertTrue(Verification::needsBackup(Warnings::for('paper', '1.21.1', 'fabric', '1.21.1')));
        $this->assertTrue(Verification::needsBackup(Warnings::for('fabric', '1.21.1', 'paper', '1.21.1')));
        $this->assertFalse(Verification::needsBackup(Warnings::for('paper', '1.20.6', 'paper', '1.21.4')));
        $this->assertFalse(Verification::needsBackup(Warnings::for('paper', '1.21.1', 'purpur', '1.21.1')));
        $this->assertFalse(Verification::needsBackup(Warnings::for('vanilla', '1.21.1', 'paper', '1.21.1')));
    }
}
