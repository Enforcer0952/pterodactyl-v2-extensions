<?php

namespace ArkTools\Tests;

use ArkTools\Support\ConfigModel;
use ArkTools\Support\Ini;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    private const string GUS = 'GameUserSettings.ini';

    private const string GAME = 'Game.ini';

    private const string MODE = '/Script/ShooterGame.ShooterGameMode';

    /** @return array<string, string> */
    private static function files(): array
    {
        return [
            self::GUS => (string) file_get_contents(__DIR__.'/fixtures/GameUserSettings.ini'),
            self::GAME => (string) file_get_contents(__DIR__.'/fixtures/Game.ini'),
        ];
    }

    /** @return iterable<string, array{string}> */
    public static function samples(): iterable
    {
        foreach (self::files() as $name => $contents) {
            yield $name => [$contents];
        }
        yield 'crlf' => ["[ServerSettings]\r\nXPMultiplier=2\r\n"];
        yield 'bom' => ["\xEF\xBB\xBF[ServerSettings]\nXPMultiplier=2"];
        yield 'mixed endings' => ["; top comment\n[A]\r\nx=1\n\n[B]\ry=2\r\n"];
        yield 'empty' => [''];
        yield 'keys before any section' => ["loose=1\n[A]\nb=2\n"];
    }

    #[DataProvider('samples')]
    public function test_unchanged_files_round_trip_byte_for_byte(string $contents): void
    {
        $this->assertSame($contents, Ini::parse($contents)->render());
    }

    public function test_set_changes_every_copy_and_keeps_the_spelling(): void
    {
        $ini = Ini::parse("[ServerSettings]\nallowThirdPersonPlayer=False\nx=1\nALLOWTHIRDPERSONPLAYER=True\n");
        $ini->set('serversettings', 'AllowThirdPersonPlayer', 'True');

        $this->assertSame("[ServerSettings]\nallowThirdPersonPlayer=True\nx=1\nALLOWTHIRDPERSONPLAYER=True\n", $ini->render());
    }

    public function test_set_adds_a_missing_key_after_the_last_line_of_its_section(): void
    {
        $ini = Ini::parse("[A]\nx=1\n\n[B]\ny=2\n");
        $ini->set('A', 'z', '3');

        $this->assertSame("[A]\nx=1\nz=3\n\n[B]\ny=2\n", $ini->render());
    }

    public function test_set_adds_a_missing_section_at_the_end_with_the_files_line_endings(): void
    {
        $ini = Ini::parse("[A]\r\nx=1");
        $ini->set(self::MODE, 'XPMultiplier', '2');

        $this->assertSame("[A]\r\nx=1\r\n\r\n[".self::MODE."]\r\nXPMultiplier=2\r\n", $ini->render());
    }

    public function test_values_cannot_inject_a_second_line(): void
    {
        $ini = Ini::parse("[A]\n");
        $ini->set('A', 'Message', "hi\n[ServerSettings]\nServerAdminPassword=x");

        $this->assertCount(1, $ini->entries());
        $this->assertSame(['A'], $ini->sections());
    }

    public function test_remove_and_last_value_wins(): void
    {
        $ini = Ini::parse("[A]\nx=1\nx=2\ny=3\n");
        $this->assertSame('2', $ini->get('a', 'X'));

        $this->assertTrue($ini->remove('A', 'x'));
        $this->assertSame("[A]\ny=3\n", $ini->render());
    }

    public function test_fields_read_values_from_the_right_file_only(): void
    {
        $fields = collect_fields((new ConfigModel)->fields(self::files(), ['SessionName', 'MaxPlayers']));

        $this->assertSame('2.00000', $fields['HarvestAmountMultiplier']['value']); // not Game.ini's 4.0
        $this->assertSame('12.0', $fields['EggHatchSpeedMultiplier']['value']);
        $this->assertNull($fields['MaxNumberOfPlayersInTribe']['value']); // only typos of it exist
        $this->assertSame('True', $fields['AllowThirdPersonPlayer']['value']); // case-insensitive key
        $this->assertTrue($fields['SessionName']['overridden']);
        $this->assertFalse($fields['XPMultiplier']['overridden']);
    }

    public function test_issues_find_misplaced_typo_and_overridden_settings(): void
    {
        $issues = (new ConfigModel)->issues(self::files());
        $find = fn (string $file, string $key) => array_values(array_filter($issues, fn (array $i): bool => $i['file'] === $file && $i['key'] === $key));

        // Game.ini harvest 4.0 is ignored; GameUserSettings has 2.0.
        [$harvest] = $find(self::GAME, 'HarvestAmountMultiplier');
        $this->assertSame('misplaced', $harvest['kind']);
        $this->assertStringContainsString('uses 2.00000', $harvest['message']);
        $this->assertSame(['Keep 2.00000', 'Use 4.000000'], array_column($harvest['fixes'], 'label'));

        // Same value in both: just remove the dead copy.
        [$xp] = $find(self::GAME, 'XPMultiplier');
        $this->assertSame(['Remove this line'], array_column($xp['fixes'], 'label'));

        // Wild food drain belongs in Game.ini; the GameUserSettings copy is dead.
        [$wild] = $find(self::GUS, 'WildDinoCharacterFoodDrainMultiplier');
        $this->assertSame('misplaced', $wild['kind']);

        // Typo, and the real setting is set nowhere yet: offer a rename.
        [$tribe] = $find(self::GAME, 'MaxNumbersofPlayersInTribe');
        $this->assertSame('typo', $tribe['kind']);
        $this->assertSame('Rename to MaxNumberOfPlayersInTribe', $tribe['fixes'][0]['label']);

        [$carry] = $find(self::GUS, 'bAllowFlyerCarryPvE');
        $this->assertSame('typo', $carry['kind']);

        // Real ARK settings the form does not cover are not typos.
        $this->assertSame([], $find(self::GUS, 'EnablePVEGamma'));
        $this->assertSame([], $find(self::GUS, 'ServerPassword'));

        // Mod sections and unknown non-typo keys are left alone.
        $this->assertSame([], $find(self::GUS, 'EnableAllGeneration'));
        $this->assertSame([], $find(self::GAME, 'GestatationSpeedMultiplier'));
        $this->assertSame([], $find(self::GAME, 'NoFoundationsRequired'));
    }

    public function test_every_fix_applies_cleanly_and_clears_its_issue(): void
    {
        $model = new ConfigModel;
        $files = self::files();
        foreach ($model->issues($files) as $issue) {
            foreach ($issue['fixes'] as $fix) {
                $changed = $model->apply($files, [], $fix['edits']) + $files;
                $left = array_filter(
                    $model->issues($changed),
                    fn (array $i): bool => $i['file'] === $issue['file'] && $i['key'] === $issue['key'] && $i['kind'] === $issue['kind'],
                );
                $this->assertSame([], array_values($left), $issue['key'].': '.$fix['label']);
            }
        }
    }

    public function test_duplicates_are_reported_once_with_the_winning_value(): void
    {
        $issues = (new ConfigModel)->issues([self::GUS => "[ServerSettings]\nXPMultiplier=2\nXPMultiplier=3\nXPMultiplier=5\n", self::GAME => null]);

        $this->assertCount(1, $issues);
        $this->assertSame('duplicate', $issues[0]['kind']);
        $this->assertSame('Keep 5', $issues[0]['fixes'][0]['label']);
    }

    public function test_passwords_are_masked_and_restored_on_raw_save(): void
    {
        $model = new ConfigModel;
        $files = self::files();
        $masked = $model->mask($files[self::GUS]);

        $this->assertStringNotContainsString('admin-password', $masked);
        $this->assertStringContainsString('ServerAdminPassword='.ConfigModel::MASK, $masked);

        // Saving the masked text unchanged changes nothing.
        $this->assertSame([], $model->apply($files, [self::GUS => $masked], []));

        // Editing something else keeps the real passwords.
        $saved = $model->apply($files, [self::GUS => str_replace('XPMultiplier=4.00000', 'XPMultiplier=6', $masked)], []);
        $this->assertStringContainsString('ServerAdminPassword=admin-password', $saved[self::GUS]);
        $this->assertStringContainsString("\nXPMultiplier=6\n", $saved[self::GUS]);
    }

    public function test_raw_editor_cannot_change_passwords(): void
    {
        $model = new ConfigModel;
        $files = self::files();
        $masked = $model->mask($files[self::GUS]);

        foreach ([
            'changed' => str_replace('ServerAdminPassword='.ConfigModel::MASK, 'ServerAdminPassword=mine', $masked),
            'removed' => str_replace("ServerAdminPassword=".ConfigModel::MASK."\n", '', $masked),
            'added' => $masked."[ServerSettings]\nSpectatorPassword=spy\n",
        ] as $case => $text) {
            try {
                $model->apply($files, [self::GUS => $text], []);
                $this->fail('Accepted a '.$case.' password');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('Startup page', $exception->getMessage());
            }
        }

        // Moving the line is fine.
        $moved = str_replace("ServerAdminPassword=".ConfigModel::MASK."\n", '', $masked)."ServerAdminPassword=".ConfigModel::MASK."\n";
        $this->assertArrayHasKey(self::GUS, $model->apply($files, [self::GUS => $moved], []));
    }

    public function test_masking_keeps_crlf_line_endings(): void
    {
        $model = new ConfigModel;
        $crlf = "[ServerSettings]\r\nServerAdminPassword=secret\r\nXPMultiplier=2\r\n";

        $this->assertSame("[ServerSettings]\r\nServerAdminPassword=".ConfigModel::MASK."\r\nXPMultiplier=2\r\n", $model->mask($crlf));
        $this->assertSame([], $model->apply([self::GUS => $crlf, self::GAME => null], [self::GUS => $model->mask($crlf)], []));
    }

    public function test_edits_are_validated(): void
    {
        $model = new ConfigModel;
        $bad = [
            ['file' => self::GUS, 'section' => 'ServerSettings', 'key' => 'XPMultiplier', 'value' => 'lots'],
            ['file' => self::GUS, 'section' => 'ServerSettings', 'key' => 'DifficultyOffset', 'value' => '2'],
            ['file' => self::GUS, 'section' => 'ServerSettings', 'key' => 'ServerPVE', 'value' => 'yes'],
            ['file' => self::GUS, 'section' => 'ServerSettings', 'key' => 'ServerAdminPassword', 'value' => 'x'],
            ['file' => self::GAME, 'section' => self::MODE, 'key' => 'XPMultiplier', 'value' => '2'], // wrong file
            ['file' => self::GUS, 'section' => 'DinoStorage2', 'key' => 'TerminalSlots', 'value' => '1'],
            ['file' => 'Engine.ini', 'section' => 'ServerSettings', 'key' => 'XPMultiplier', 'value' => '2'],
            ['file' => self::GAME, 'section' => self::MODE, 'key' => 'MaxNumberOfPlayersInTribe', 'value' => '2.5'],
        ];
        foreach ($bad as $edit) {
            try {
                $model->apply(self::files(), [], [$edit]);
                $this->fail('Accepted '.json_encode($edit));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_preset_values_are_all_valid_settings(): void
    {
        $model = new ConfigModel;
        foreach ($model->schema()['presets'] as $preset) {
            $edits = [];
            foreach ($preset['values'] as $key => $value) {
                $setting = $model->setting($key);
                $this->assertNotNull($setting, $preset['id'].': '.$key);
                $edits[] = ['file' => $setting['file'], 'section' => $setting['section'], 'key' => $setting['key'], 'value' => $value];
            }
            $model->apply(self::files(), [], $edits);
        }
    }

    public function test_startup_keys_are_read_from_the_command(): void
    {
        $command = './ShooterGameServer {{SERVER_MAP}}?listen?SessionName="{{SESSION_NAME}}"?ServerPassword={{ARK_PASSWORD}}?Port=7777?MaxPlayers={{MAX_PLAYERS}} -server';

        $this->assertSame(['SessionName', 'ServerPassword', 'Port', 'MaxPlayers', 'AllowFlyerCarryPvE'], ConfigModel::startupKeys($command, '?AllowFlyerCarryPvE=True'));
    }
}

/**
 * @param  list<array<string, mixed>>  $fields
 * @return array<string, array<string, mixed>>
 */
function collect_fields(array $fields): array
{
    return array_column($fields, null, 'key');
}
