<?php

use PalworldTools\Support\OptionSettings;
use PalworldTools\Support\SettingsModel;
use PHPUnit\Framework\TestCase;

final class SettingsModelTest extends TestCase
{
    private SettingsModel $model;

    protected function setUp(): void
    {
        $this->model = new SettingsModel();
    }

    private function fixture(): string
    {
        return (string) file_get_contents(__DIR__.'/fixtures/DefaultPalWorldSettings.ini');
    }

    public function testSchemaIsConsistent(): void
    {
        $schema = $this->model->schema();
        $groups = array_column($schema['groups'], 'id');
        $keys = [];
        foreach ($schema['settings'] as $setting) {
            $this->assertContains($setting['group'], $groups, $setting['key']);
            $this->assertNotContains($setting['key'], $keys, 'duplicate '.$setting['key']);
            $keys[] = $setting['key'];
            $this->assertContains($setting['type'], ['string', 'int', 'float', 'bool', 'enum', 'list']);
            // Every default is itself a valid value.
            $this->model->format($setting['key'], OptionSettings::display($setting['default']));
        }
        foreach ($schema['secrets'] as $secret) {
            $this->assertContains($secret, $keys);
        }
    }

    public function testEveryKeyInTheDefaultFileThatTheFormKnowsMatchesItsDefault(): void
    {
        $options = OptionSettings::parse($this->fixture());
        foreach ($this->model->schema()['settings'] as $setting) {
            $raw = $options->get($setting['key']);
            if ($raw === null) {
                continue;
            }
            $this->assertSame(OptionSettings::display($setting['default']), $raw, $setting['key']);
        }
    }

    public function testFormatsValuesTheWayPalworldWritesThem(): void
    {
        $this->assertSame('2.500000', $this->model->format('ExpRate', '2.5'));
        $this->assertSame('3.000000', $this->model->format('ExpRate', ' 3 '));
        $this->assertSame('16', $this->model->format('ServerPlayerMaxNum', '16'));
        $this->assertSame('True', $this->model->format('bIsPvP', 'true'));
        $this->assertSame('False', $this->model->format('bIsPvP', 'FALSE'));
        $this->assertSame('ItemAndEquipment', $this->model->format('DeathPenalty', 'itemandequipment'));
        $this->assertSame('"My, (server)"', $this->model->format('ServerName', 'My, (server)'));
        $this->assertSame('(Steam,Mac)', $this->model->format('CrossplayPlatforms', 'steam, MAC, Steam'));
        $this->assertSame('()', $this->model->format('CrossplayPlatforms', ''));
    }

    public function testRejectsBadValues(): void
    {
        $bad = [
            ['ExpRate', 'fast'], ['ExpRate', '-1'], ['ExpRate', '1e3'], ['ExpRate', 'NaN'], ['ServerPlayerMaxNum', '33'],
            ['ServerPlayerMaxNum', '0'], ['ServerPlayerMaxNum', '1.5'], ['bIsPvP', 'yes'], ['DeathPenalty', 'Everything'],
            ['BaseCampMaxNumInGuild', '11'], ['BaseCampWorkerMaxNum', '51'], ['ServerReplicatePawnCullDistance', '100'],
            ['CrossplayPlatforms', 'Steam,Switch'], ['ServerName', str_repeat('x', 65)], ['PublicPort', '70000'], ['Nope', '1'],
            ['ServerName', "a\nb"], ['ServerName', "a\x00b"],
        ];
        foreach ($bad as [$key, $value]) {
            try {
                $this->model->format($key, $value);
                $this->fail("Expected an exception for $key=$value");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testFieldsHideThePasswords(): void
    {
        $text = str_replace('AdminPassword=""', 'AdminPassword="hunter2"', $this->fixture());
        $fields = array_column($this->model->fields($text), null, 'key');

        $this->assertNull($fields['AdminPassword']['value']);
        $this->assertTrue($fields['AdminPassword']['set']);
        $this->assertFalse($fields['ServerPassword']['set']);
        $this->assertSame('1.000000', $fields['ExpRate']['value']);
        $this->assertStringNotContainsString('hunter2', json_encode($fields));
        $this->assertStringNotContainsString('hunter2', $this->model->mask($text));
        $this->assertStringContainsString('AdminPassword="********"', $this->model->mask($text));
        $this->assertStringContainsString('ServerPassword=""', $this->model->mask($text));
    }

    public function test_mask_hides_passwords_in_broken_files_and_every_line(): void
    {
        $broken = "[/Script/Pal.PalGameWorldSettings]\nOptionSettings=(AdminPassword=\"hunter2\",ServerPassword=\"secret\"\n";
        $masked = $this->model->mask($broken);
        $this->assertStringNotContainsString('hunter2', $masked);
        $this->assertStringNotContainsString('secret"', $masked);

        $twice = "OptionSettings=(AdminPassword=\"first\")\nOptionSettings=(AdminPassword=\"second\",ServerPassword=plain)\n";
        $masked = $this->model->mask($twice);
        foreach (['first', 'second', 'plain'] as $secret) {
            $this->assertStringNotContainsString($secret, $masked);
        }
    }

    public function testApplyEdits(): void
    {
        $new = $this->model->apply($this->fixture(), null, [
            ['key' => 'ExpRate', 'value' => '5'],
            ['key' => 'servername', 'value' => 'Pals "R" Us'],
            ['key' => 'RESTAPIEnabled', 'value' => 'True'],
            ['key' => 'bShowPlayerList', 'value' => null],
        ], true);

        $options = OptionSettings::parse($new);
        $this->assertSame('5.000000', $options->get('ExpRate'));
        $this->assertSame('Pals "R" Us', $options->get('ServerName'));
        $this->assertSame('True', $options->get('RESTAPIEnabled'));
        $this->assertFalse($options->has('bShowPlayerList'));
        $this->assertSame('8212', $options->get('RESTAPIPort'));
    }

    public function testApplyWithoutEditsKeepsTheTextExactly(): void
    {
        $this->assertSame($this->fixture(), $this->model->apply($this->fixture(), null, [], true));
    }

    public function testApplyRejectsUnknownKeysAndInjection(): void
    {
        foreach ([['key' => 'Evil', 'value' => '1'], ['key' => 'ExpRate', 'value' => '1,AdminPassword="x"'], ['key' => 'ServerName', 'value' => "a\"),B=(\nx"]] as $edit) {
            try {
                $this->model->apply($this->fixture(), null, [$edit], true);
                $this->fail('Expected an exception');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testPasswordEditsNeedPermission(): void
    {
        try {
            $this->model->apply($this->fixture(), null, [['key' => 'AdminPassword', 'value' => 'x']], false);
            $this->fail('Expected an exception');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(SettingsModel::FORBIDDEN, $exception->getCode());
        }

        $new = $this->model->apply($this->fixture(), null, [['key' => 'AdminPassword', 'value' => 'a"b,c']], true);
        $this->assertSame('a"b,c', OptionSettings::parse($new)->get('AdminPassword'));
    }

    public function testEmptyTextClearsAStringInsteadOfRemovingIt(): void
    {
        $this->assertSame('""', $this->model->format('ServerDescription', ''));

        $new = $this->model->apply($this->fixture(), null, [['key' => 'ServerName', 'value' => '']], true);
        $this->assertSame('', OptionSettings::parse($new)->get('ServerName'));
        $this->assertStringContainsString('ServerName=""', $new);

        $removed = $this->model->apply($this->fixture(), null, [['key' => 'ServerName', 'value' => null]], true);
        $this->assertNull(OptionSettings::parse($removed)->get('ServerName'));
    }

    public function testEmptyTextIsRejectedForNumbers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->model->format('ExpRate', '');
    }

    public function testRawSaveKeepsMaskedPasswords(): void
    {
        $current = str_replace('AdminPassword=""', 'AdminPassword="hunter2"', $this->fixture());
        $raw = str_replace('ExpRate=1.000000', 'ExpRate=3.000000', $this->model->mask($current));

        $new = $this->model->apply($current, $raw, [], true);

        $options = OptionSettings::parse($new);
        $this->assertSame('hunter2', $options->get('AdminPassword'));
        $this->assertSame('3.000000', $options->get('ExpRate'));
    }

    public function testRawSaveCannotChangePasswordsWithoutPermission(): void
    {
        $current = str_replace('AdminPassword=""', 'AdminPassword="hunter2"', $this->fixture());
        $raw = str_replace('AdminPassword="********"', 'AdminPassword="mine"', $this->model->mask($current));

        $new = $this->model->apply($current, $raw, [], false);

        $this->assertSame('hunter2', OptionSettings::parse($new)->get('AdminPassword'));
    }

    public function testRawSaveChecksChangedValues(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->model->apply($this->fixture(), str_replace('ExpRate=1.000000', 'ExpRate=lots', $this->fixture()), [], true);
    }

    public function testRawSaveKeepsUnknownKeysAndOrder(): void
    {
        $raw = str_replace('ExpRate=1.000000,', 'ExpRate=1.000000,FutureThing=(A,"b,c"),', $this->fixture());
        $new = $this->model->apply($this->fixture(), $raw, [], true);

        $this->assertSame($raw, $new);
    }

    public function testRawSaveWithoutOptionSettingsIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->model->apply($this->fixture(), "[/Script/Pal.PalGameWorldSettings]\n", [], true);
    }

    public function testBaseUsesTheTemplateWhenTheFileIsEmpty(): void
    {
        $template = $this->fixture();

        $this->assertSame($template, $this->model->base(null, $template));
        $this->assertSame($template, $this->model->base('', $template));
        $this->assertSame($template, $this->model->base("[/Script/Pal.PalGameWorldSettings]\nOptionSettings=()\n", $template));
        $mine = str_replace('ExpRate=1.000000', 'ExpRate=2.000000', $template);
        $this->assertSame($mine, $this->model->base($mine, $template));
        $this->assertStringContainsString('OptionSettings=()', $this->model->base(null, null));
    }

    public function testIssues(): void
    {
        $text = str_replace('ExpRate=1.000000,', 'ExpRate=1.000000,ExpRate=2.000000,', str_replace('PalCaptureRate=1.000000', 'PalCaptureRate=abc', $this->fixture()));
        $issues = $this->model->issues($text);
        $kinds = array_map(fn (array $issue): string => $issue['kind'].':'.$issue['key'], $issues);

        $this->assertContains('duplicate:ExpRate', $kinds);
        $this->assertContains('invalid:PalCaptureRate', $kinds);
        $this->assertSame([], $this->model->issues($this->fixture()));
        $this->assertSame('broken', $this->model->issues('OptionSettings=(A=1')[0]['kind']);

        $fix = $issues[array_search('duplicate:ExpRate', $kinds, true)]['fixes'][0]['edits'];
        $fixed = $this->model->apply($text, null, $fix, true);
        $this->assertSame(1, count(array_filter(OptionSettings::parse($fixed)->keys(), fn (string $key): bool => $key === 'ExpRate')));
    }

    public function testPresetsOnlyUseKnownValidSettings(): void
    {
        foreach ($this->model->schema()['presets'] as $preset) {
            $edits = [];
            foreach ($preset['values'] as $key => $value) {
                $edits[] = ['key' => $key, 'value' => $value];
            }
            $new = $this->model->apply($this->fixture(), null, $edits, false);
            $options = OptionSettings::parse($new);
            foreach ($preset['values'] as $key => $value) {
                $this->assertSame($value, $options->get($key), $preset['id'].' '.$key);
            }
        }
    }

    public function testVanillaPresetUndoesTheRatePresets(): void
    {
        $presets = array_column($this->model->schema()['presets'], null, 'id');
        $edit = fn (array $preset): array => array_map(fn (string $k, string $v): array => ['key' => $k, 'value' => $v], array_keys($preset['values']), $preset['values']);

        $boosted = $this->model->apply($this->fixture(), null, $edit($presets['x5']), false);
        $back = $this->model->apply($boosted, null, $edit($presets['vanilla']), false);

        $this->assertSame($this->fixture(), $back);
    }
}
