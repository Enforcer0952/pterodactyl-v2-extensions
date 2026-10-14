<?php

use PHPUnit\Framework\TestCase;
use ValheimTools\Support\ServerSettings;

class ServerSettingsTest extends TestCase
{
    public function test_names(): void
    {
        $this->assertNull(ServerSettings::validateName('My Server'));
        $this->assertNull(ServerSettings::validateName("Anton's Valheim"));
        foreach (['', ' x', 'x ', str_repeat('a', 61), 'a"b', 'a$b', 'a`b', 'a\\b', 'a{{b}}', "a\nb", 'a$(id)', "a\x00b"] as $bad) {
            $this->assertNotNull(ServerSettings::validateName($bad), json_encode($bad));
        }
    }

    public function test_passwords(): void
    {
        $this->assertNull(ServerSettings::validatePassword('secret', 'Dedicated'));
        foreach (['abcd', str_repeat('a', 21), 'has space', 'a"bcde', 'a$bcde', 'abc`de', "abc\nde", 'abc{de}', 'abc\\de'] as $bad) {
            $this->assertNotNull(ServerSettings::validatePassword($bad, 'Dedicated'), json_encode($bad));
        }
    }

    public function test_password_in_world_name_is_an_error(): void
    {
        $this->assertNotNull(ServerSettings::validatePassword('secret', 'My secret world'));
        $this->assertNotNull(ServerSettings::validatePassword('SECRET', 'my secret world'));
        $this->assertNull(ServerSettings::validatePassword('secret', ''));
    }

    public function test_password_in_server_name_is_a_warning(): void
    {
        $this->assertNotNull(ServerSettings::nameWarning('Join with secret', 'secret'));
        $this->assertNull(ServerSettings::nameWarning('My Server', 'secret'));
        $this->assertNull(ServerSettings::nameWarning('My Server', ''));
    }

    public function test_flags(): void
    {
        $this->assertSame('1', ServerSettings::flag(true));
        $this->assertSame('1', ServerSettings::flag('1'));
        $this->assertSame('0', ServerSettings::flag(false));
        $this->assertSame('0', ServerSettings::flag(0));
        $this->assertNull(ServerSettings::flag('2'));
        $this->assertNull(ServerSettings::flag('yes'));
        $this->assertNull(ServerSettings::flag(null));
    }

    public function test_presets(): void
    {
        $this->assertNull(ServerSettings::validatePreset('Hard'));
        $this->assertNull(ServerSettings::validatePreset(''));
        $this->assertNotNull(ServerSettings::validatePreset('hard; rm -rf /'));
        $this->assertNotNull(ServerSettings::validatePreset('nightmare'));
    }
}
