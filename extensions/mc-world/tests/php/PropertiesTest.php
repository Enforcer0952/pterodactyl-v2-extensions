<?php

use McWorld\Support\Properties;
use PHPUnit\Framework\TestCase;

final class PropertiesTest extends TestCase
{
    private const SAMPLE = "#Minecraft server properties\n#Mon Oct 13 10:00:00 UTC 2026\nenable-jmx-monitoring=false\nmotd=\\u00A7aHello \\u00A7lWorld\\nSecond line\nlevel-name=world\nlevel-seed=\nrcon.password=a\\=b\\:c\n\nonline-mode=true\n";

    public function testUntouchedFileRoundTripsByteForByte(): void
    {
        foreach ([self::SAMPLE, str_replace("\n", "\r\n", self::SAMPLE), rtrim(self::SAMPLE), '', "# only a comment\n", "a=1\rb=2\r"] as $text) {
            $this->assertSame($text, Properties::parse($text)->toString());
            $this->assertSame($text, Properties::parse($text)->with([])->toString());
        }
    }

    public function testDecodesValues(): void
    {
        $all = Properties::parse(self::SAMPLE)->all();
        $this->assertSame("§aHello §lWorld\nSecond line", $all['motd']);
        $this->assertSame('', $all['level-seed']);
        $this->assertSame('a=b:c', $all['rcon.password']);
        $this->assertSame('true', $all['online-mode']);
        $this->assertArrayNotHasKey('#Minecraft server properties', $all);
    }

    public function testChangingOneKeyLeavesEveryOtherByteAlone(): void
    {
        $changed = Properties::parse(self::SAMPLE)->with(['level-seed' => '12345'])->toString();
        $this->assertSame(str_replace("level-seed=\n", "level-seed=12345\n", self::SAMPLE), $changed);
    }

    public function testCrlfIsKeptForChangedAndNewLines(): void
    {
        $crlf = str_replace("\n", "\r\n", self::SAMPLE);
        $changed = Properties::parse($crlf)->with(['online-mode' => 'false', 'new-key' => 'x'])->toString();
        $this->assertSame(str_replace('online-mode=true', 'online-mode=false', $crlf)."new-key=x\r\n", $changed);
        $this->assertStringNotContainsString("\r\r", $changed);
        $this->assertSame(0, preg_match_all('/(?<!\r)\n/', $changed));
    }

    public function testWritesJavaEscapes(): void
    {
        $text = Properties::parse("motd=x\n")->with(['motd' => "§6Big: sale = 50%! #1\\path\nLine 2 é 😀"])->toString();
        $this->assertSame("motd=\\u00A76Big\\: sale \\= 50%\\! \\#1\\\\path\\nLine 2 \\u00E9 \\uD83D\\uDE00\n", $text);
        $this->assertSame("§6Big: sale = 50%! #1\\path\nLine 2 é 😀", Properties::parse($text)->get('motd'));
    }

    public function testLeadingSpaceOfValueIsEscaped(): void
    {
        $text = Properties::parse("motd=a\n")->with(['motd' => '  centred'])->toString();
        $this->assertSame("motd=\\  centred\n", $text);
        $this->assertSame('  centred', Properties::parse($text)->get('motd'));
    }

    public function testSameValueIsNotRewritten(): void
    {
        // Equivalent but differently written values stay exactly as they were.
        $text = "motd=\\u00a7aHi\nlevel-name = world\n";
        $this->assertSame($text, Properties::parse($text)->with(['motd' => '§aHi', 'level-name' => 'world'])->toString());
    }

    public function testSeparatorStyleIsKept(): void
    {
        $this->assertSame("level-name = nether\n", Properties::parse("level-name = world\n")->with(['level-name' => 'nether'])->toString());
        $this->assertSame("a: 2\n", Properties::parse("a: 1\n")->with(['a' => '2'])->toString());
    }

    public function testAppendAddsLineEndingToLastLine(): void
    {
        $this->assertSame("a=1\nb=2\n", Properties::parse('a=1')->with(['b' => '2'])->toString());
        $this->assertSame("a=1\n", Properties::parse('')->with(['a' => '1'])->toString());
    }

    public function testRemoveDeletesEveryCopyOfAKey(): void
    {
        $text = Properties::parse("a=1\n# keep\nb=2\na=3\n")->with(['a' => null, 'missing' => null])->toString();
        $this->assertSame("# keep\nb=2\n", $text);
    }

    public function testDuplicateKeyLastOneWinsAndIsTheOneChanged(): void
    {
        $properties = Properties::parse("a=1\na=2\n");
        $this->assertSame('2', $properties->get('a'));
        $this->assertSame("a=1\na=9\n", $properties->with(['a' => '9'])->toString());
    }

    public function testCommentsAreNeverEntries(): void
    {
        $properties = Properties::parse("# a=1\n  ! b=2\n\tc=3\n");
        $this->assertSame(['c' => '3'], $properties->all());
    }

    public function testKeyWithoutValueAndSpaceSeparators(): void
    {
        $all = Properties::parse("lonely\nspaced   value here\ncolon:x\n")->all();
        $this->assertSame(['lonely' => '', 'spaced' => 'value here', 'colon' => 'x'], $all);
    }

    public function testContinuationLines(): void
    {
        $text = "motd=first \\\n    second\nnext=1\n";
        $properties = Properties::parse($text);
        $this->assertSame('first second', $properties->get('motd'));
        $this->assertSame($text, $properties->toString());
        $this->assertSame("motd=short\nnext=1\n", $properties->with(['motd' => 'short'])->toString());
    }

    public function testEscapedBackslashAtLineEndIsNotAContinuation(): void
    {
        $properties = Properties::parse("a=C\\\\\nb=2\n");
        $this->assertSame('C\\', $properties->get('a'));
        $this->assertSame('2', $properties->get('b'));
    }

    public function testUnicodeEscapesIncludingSurrogatePairs(): void
    {
        $this->assertSame('😀', Properties::unescape('\\uD83D\\uDE00'));
        $this->assertSame("\u{FFFD}", Properties::unescape('\\uD83D'));
        $this->assertSame("\u{FFFD}", Properties::unescape('\\uDE00'));
        $this->assertSame('\\uZZ', Properties::unescape('\\uZZ'));
        $this->assertSame('é', Properties::unescape('\\u00e9'));
    }

    public function testValueWithNewlineCannotInjectAnotherKey(): void
    {
        $text = Properties::parse("a=1\n")->with(['a' => "x\nop-permission-level=4", 'b' => "y\r\nz=1"])->toString();
        $this->assertSame(['a', 'b'], array_keys(Properties::parse($text)->all()));
        $this->assertSame("x\nop-permission-level=4", Properties::parse($text)->get('a'));
        $this->assertSame(2, substr_count($text, "\n"));
    }

    public function testKeyIsEscapedWhenAppended(): void
    {
        $text = Properties::parse('')->with(['odd key=1' => 'v'])->toString();
        $this->assertSame("odd\\ key\\=1=v\n", $text);
        $this->assertSame('v', Properties::parse($text)->get('odd key=1'));
    }

    public function testRealisticServerPropertiesEditOnlyTouchesChangedKeys(): void
    {
        $text = file_get_contents(__DIR__.'/fixtures/server.properties');
        $this->assertNotFalse($text);
        $edited = Properties::parse($text)->with(['difficulty' => 'hard', 'hardcore' => 'true', 'motd' => '§cHardcore']);
        $old = explode("\n", $text);
        $new = explode("\n", $edited->toString());
        $this->assertCount(count($old), $new);
        $diff = array_keys(array_filter($old, fn ($line, $i) => $line !== $new[$i], ARRAY_FILTER_USE_BOTH));
        $this->assertCount(3, $diff);
        $this->assertSame('hard', $edited->get('difficulty'));
    }
}
