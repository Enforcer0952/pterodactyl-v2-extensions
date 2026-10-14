<?php

use PalworldTools\Support\OptionSettings;
use PHPUnit\Framework\TestCase;

final class OptionSettingsTest extends TestCase
{
    private function fixture(): string
    {
        return (string) file_get_contents(__DIR__.'/fixtures/DefaultPalWorldSettings.ini');
    }

    public function testRoundTripOfTheFullDefaultFileIsByteIdentical(): void
    {
        $text = $this->fixture();

        $this->assertSame($text, OptionSettings::parse($text)->render());
        $this->assertSame(str_replace("\n", "\r\n", $text), OptionSettings::parse(str_replace("\n", "\r\n", $text))->render());
    }

    public function testReadsTypedValues(): void
    {
        $options = OptionSettings::parse($this->fixture());

        $this->assertSame('None', $options->get('Difficulty'));
        $this->assertSame('1.000000', $options->get('ExpRate'));
        $this->assertSame('Default Palworld Server', $options->get('ServerName'));
        $this->assertSame('', $options->get('AdminPassword'));
        $this->assertSame('Steam,Xbox,PS5,Mac', $options->get('CrossplayPlatforms'));
        $this->assertSame('(Steam,Xbox,PS5,Mac)', $options->raw('CrossplayPlatforms'));
        $this->assertSame('', $options->get('DenyTechnologyList'));
        $this->assertTrue($options->has('rcONenabled'), 'keys are case-insensitive');
        $this->assertNull($options->get('NoSuchKey'));
        $this->assertGreaterThan(90, count($options->keys()));
    }

    public function testQuotedStringsKeepCommasParenthesesAndEscapedQuotes(): void
    {
        $line = 'OptionSettings=(ServerName="Pals, (the \\"best\\") server",ExpRate=2.000000,ServerDescription="a)b,c=d")';
        $options = OptionSettings::parse($line);

        $this->assertSame('Pals, (the "best") server', $options->get('ServerName'));
        $this->assertSame('2.000000', $options->get('ExpRate'));
        $this->assertSame('a)b,c=d', $options->get('ServerDescription'));
        $this->assertSame($line, $options->render());
    }

    public function testSetChangesOnlyThatValue(): void
    {
        $text = $this->fixture();
        $options = OptionSettings::parse($text);
        $options->set('ExpRate', '5.000000');
        $options->set('ServerName', OptionSettings::quote('Say "hi", (ok)'));

        $out = $options->render();
        $this->assertStringContainsString('ExpRate=5.000000,', $out);
        $this->assertStringContainsString('ServerName="Say \\"hi\\", (ok)",', $out);
        $again = OptionSettings::parse($out);
        $this->assertSame('Say "hi", (ok)', $again->get('ServerName'));
        $this->assertSame($again->keys(), OptionSettings::parse($text)->keys(), 'order unchanged');
        $this->assertSame(
            str_replace(['ExpRate=1.000000', 'ServerName="Default Palworld Server"'], ['ExpRate=5.000000', 'ServerName="Say \\"hi\\", (ok)"'], $text),
            $out,
        );
    }

    public function testSetAppendsNewKeysAtTheEnd(): void
    {
        $options = OptionSettings::parse('OptionSettings=(A=1,B=2)');
        $options->set('C', '3');

        $this->assertSame('OptionSettings=(A=1,B=2,C=3)', $options->render());
    }

    public function testSetKeepsSpacingAndDropsEarlierDuplicates(): void
    {
        $options = OptionSettings::parse('OptionSettings=( A = 1 , B=2, A=3 )');
        $options->set('A', '9');

        $this->assertSame('OptionSettings=( B=2, A=9 )', $options->render());
    }

    public function testRemove(): void
    {
        $options = OptionSettings::parse('OptionSettings=(A=1,B=(x,y),C="1,2")');
        $options->remove('b');

        $this->assertSame('OptionSettings=(A=1,C="1,2")', $options->render());
        $options->remove('A');
        $options->remove('C');
        $this->assertSame('OptionSettings=()', $options->render());
    }

    public function testKeepsTextAroundTheLine(): void
    {
        $text = "; comment\n[/Script/Pal.PalGameWorldSettings]\nOptionSettings=(A=1)\n\n[Other]\nX=1\n";
        $options = OptionSettings::parse($text);
        $options->set('A', '2');

        $this->assertSame("; comment\n[/Script/Pal.PalGameWorldSettings]\nOptionSettings=(A=2)\n\n[Other]\nX=1\n", $options->render());
    }

    public function testIgnoresCommentedOutLinesAndUsesTheLastAssignment(): void
    {
        $text = "; OptionSettings=(A=0)\nOptionSettings=(A=1)\nOptionSettings=(A=2)\n";

        $this->assertSame('2', OptionSettings::parse($text)->get('A'));
        $this->assertFalse(OptionSettings::exists("; OptionSettings=(A=0)\n"));
        $this->assertFalse(OptionSettings::exists(''));
    }

    public function testNestedTuplesAndLists(): void
    {
        $options = OptionSettings::parse('OptionSettings=(DenyTechnologyList=("PALBOX", "RepairBench"),X=1)');

        $this->assertSame('PALBOX,RepairBench', $options->get('DenyTechnologyList'));
        $this->assertSame('1', $options->get('X'));
    }

    public function testBrokenInputIsRejected(): void
    {
        foreach (['', 'nothing here', 'OptionSettings=(A=1,B=2', 'OptionSettings=(A="open)'] as $text) {
            try {
                OptionSettings::parse($text);
                $this->fail('Expected an exception for: '.$text);
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testValuesCannotBreakOutOfTheTuple(): void
    {
        $options = OptionSettings::parse('OptionSettings=(A=1)');
        foreach (['2,B=3', '2)', "2\nX=1", '"open', '(1', 'a"b'] as $token) {
            try {
                $options->set('A', $token);
                $this->fail('Expected an exception for: '.$token);
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        foreach (['A,B', 'A=', '1A', "A\n", ''] as $key) {
            try {
                $options->set($key, '1');
                $this->fail('Expected an exception for key: '.$key);
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('OptionSettings=(A=1)', $options->render());
    }

    public function testQuoteRejectsControlCharacters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        OptionSettings::quote("two\nlines");
    }

    public function testQuoteEscapesBackslashes(): void
    {
        $this->assertSame('"C:\\\\x"', OptionSettings::quote('C:\\x'));
        $this->assertSame('C:\\x', OptionSettings::display(OptionSettings::quote('C:\\x')));
    }
}
