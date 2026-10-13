<?php

namespace TerrariaTools\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TerrariaTools\Support\ServerConfig;
use TerrariaTools\Support\ServerSettings;

final class ServerSettingsTest extends TestCase
{
    /** @return iterable<string, array{string, string, bool}> */
    public static function values(): iterable
    {
        yield 'maxplayers ok' => ['maxplayers', '8', true];
        yield 'maxplayers min' => ['maxplayers', '1', true];
        yield 'maxplayers max' => ['maxplayers', '255', true];
        yield 'maxplayers zero' => ['maxplayers', '0', false];
        yield 'maxplayers too many' => ['maxplayers', '256', false];
        yield 'maxplayers text' => ['maxplayers', 'many', false];
        yield 'maxplayers negative' => ['maxplayers', '-1', false];
        yield 'maxplayers float' => ['maxplayers', '8.5', false];
        yield 'maxplayers newline' => ['maxplayers', "8\n", false];
        yield 'difficulty ok' => ['difficulty', '3', true];
        yield 'difficulty out of range' => ['difficulty', '4', false];
        yield 'difficulty text' => ['difficulty', 'expert', false];
        yield 'autocreate never' => ['autocreate', '0', true];
        yield 'autocreate large' => ['autocreate', '3', true];
        yield 'autocreate huge' => ['autocreate', '4', false];
        yield 'npcstream off' => ['npcstream', '0', true];
        yield 'npcstream 60' => ['npcstream', '60', true];
        yield 'npcstream too high' => ['npcstream', '1000', false];
        yield 'motd ok' => ['motd', 'Welcome to the server', true];
        yield 'motd empty' => ['motd', '', true];
        yield 'motd newline' => ['motd', "hi\nautocreate=3", false];
        yield 'motd line separator' => ['motd', "hi\u{2028}there", false];
        yield 'motd too long' => ['motd', str_repeat('x', 201), false];
        yield 'password ok' => ['password', 'p@ss word!', true];
        yield 'password empty' => ['password', '', true];
        yield 'password control char' => ['password', "pw\x00", false];
        yield 'password too long' => ['password', str_repeat('x', 101), false];
        yield 'unknown key' => ['port', '1234', false];
    }

    #[DataProvider('values')]
    public function test_validates_values(string $key, string $value, bool $valid): void
    {
        $this->assertSame($valid, ServerSettings::validate($key, $value) === null, $key.'='.json_encode($value));
    }

    public function test_apply_edits_the_known_keys_and_keeps_the_rest(): void
    {
        $config = ServerConfig::parse("# my server\nport=12556\nmaxplayers=8\nmotd=old\n");
        ServerSettings::apply($config, ['maxplayers' => '16', 'motd' => 'new', 'password' => 'secret']);

        $this->assertSame("# my server\nport=12556\nmaxplayers=16\nmotd=new\npassword=secret\n", $config->render());
    }

    public function test_apply_changes_nothing_when_one_value_is_bad(): void
    {
        $config = ServerConfig::parse("maxplayers=8\nmotd=old\n");

        try {
            ServerSettings::apply($config, ['motd' => 'new', 'maxplayers' => '999']);
            $this->fail('expected an exception');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('Max players', $exception->getMessage());
        }

        $this->assertSame("maxplayers=8\nmotd=old\n", $config->render());
    }

    public function test_apply_cannot_smuggle_in_another_key(): void
    {
        $config = ServerConfig::parse("motd=old\n");

        $this->expectException(InvalidArgumentException::class);
        ServerSettings::apply($config, ['motd' => "x\npassword=hacked"]);
    }

    public function test_fields_hide_the_password_unless_revealed(): void
    {
        $config = ServerConfig::parse("password=hunter2\nmaxplayers=8\n");

        $hidden = self::field(ServerSettings::fields($config, false), 'password');
        $this->assertNull($hidden['value']);
        $this->assertTrue($hidden['set']);

        $shown = self::field(ServerSettings::fields($config, true), 'password');
        $this->assertSame('hunter2', $shown['value']);

        $this->assertStringNotContainsString('hunter2', (string) json_encode(ServerSettings::fields($config, false)));
    }

    public function test_fields_report_missing_keys(): void
    {
        $field = self::field(ServerSettings::fields(ServerConfig::parse(''), true), 'motd');

        $this->assertNull($field['value']);
        $this->assertFalse($field['present']);
        $this->assertFalse($field['set']);
    }

    public function test_choices_are_listed(): void
    {
        $field = self::field(ServerSettings::fields(ServerConfig::parse("difficulty=1\n"), true), 'difficulty');

        $this->assertSame('1', $field['value']);
        $this->assertSame(['value' => '2', 'label' => 'Master'], $field['choices'][2]);
    }

    public function test_variables_map_to_the_egg(): void
    {
        $this->assertSame('MAX_PLAYERS', ServerSettings::variableFor('maxplayers'));
        $this->assertSame('PASSWORD', ServerSettings::variableFor('PASSWORD'));
        $this->assertSame('WORLD_SIZE', ServerSettings::variableFor('autocreate'));
        $this->assertNull(ServerSettings::variableFor('port'));
        $this->assertTrue(ServerSettings::isSecret('Password'));
        $this->assertFalse(ServerSettings::isSecret('motd'));
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @return array<string, mixed>
     */
    private static function field(array $fields, string $key): array
    {
        foreach ($fields as $field) {
            if ($field['key'] === $key) {
                return $field;
            }
        }
        throw new \LogicException('missing field '.$key);
    }
}
