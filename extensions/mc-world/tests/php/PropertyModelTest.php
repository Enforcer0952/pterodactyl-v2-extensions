<?php

use McWorld\Support\PropertyModel;
use PHPUnit\Framework\TestCase;

final class PropertyModelTest extends TestCase
{
    public function testEverySettingHasAGroupTypeAndDefault(): void
    {
        $groups = array_column(PropertyModel::groups(), 'id');
        $keys = [];
        foreach (PropertyModel::settings() as $setting) {
            $this->assertContains($setting['group'], $groups);
            $this->assertContains($setting['type'], ['string', 'text', 'bool', 'int', 'select']);
            $this->assertArrayHasKey('default', $setting);
            $this->assertNull(PropertyModel::validate($setting['key'], $setting['default']), $setting['key']);
            $this->assertNotContains($setting['key'], $keys);
            $keys[] = $setting['key'];
        }
        foreach (['motd', 'max-players', 'difficulty', 'gamemode', 'hardcore', 'pvp', 'white-list', 'enforce-whitelist', 'online-mode', 'spawn-protection', 'view-distance', 'simulation-distance', 'allow-flight', 'enable-command-block', 'level-name', 'level-seed', 'level-type', 'generate-structures', 'allow-nether'] as $key) {
            $this->assertContains($key, $keys);
        }
    }

    public function testBooleansMustBeTrueOrFalse(): void
    {
        $this->assertNull(PropertyModel::validate('pvp', 'true'));
        $this->assertNull(PropertyModel::validate('pvp', 'false'));
        foreach (['True', '1', 'yes', ''] as $bad) {
            $this->assertNotNull(PropertyModel::validate('pvp', $bad), $bad);
        }
    }

    public function testNumbersAreRangeChecked(): void
    {
        $this->assertNull(PropertyModel::validate('view-distance', '32'));
        $this->assertNotNull(PropertyModel::validate('view-distance', '33'));
        $this->assertNotNull(PropertyModel::validate('view-distance', '1'));
        $this->assertNotNull(PropertyModel::validate('view-distance', '10.5'));
        $this->assertNotNull(PropertyModel::validate('view-distance', ''));
        $this->assertNotNull(PropertyModel::validate('max-players', '-1'));
        $this->assertNull(PropertyModel::validate('max-players', '2147483647'));
        $this->assertNotNull(PropertyModel::validate('max-players', '2147483648'));
        $this->assertNotNull(PropertyModel::validate('spawn-protection', 'abc'));
    }

    public function testSelects(): void
    {
        $this->assertNull(PropertyModel::validate('difficulty', 'hard'));
        $this->assertNotNull(PropertyModel::validate('difficulty', 'impossible'));
        $this->assertNotNull(PropertyModel::validate('gamemode', 'Survival'));
        $this->assertNull(PropertyModel::validate('level-type', 'minecraft:flat'));
        $this->assertNull(PropertyModel::validate('level-type', 'largeBiomes'));
        $this->assertNotNull(PropertyModel::validate('level-type', 'flat; stop'));
    }

    public function testWorldNameAndSeed(): void
    {
        $this->assertNull(PropertyModel::validate('level-name', 'Hardcore 3'));
        $this->assertNotNull(PropertyModel::validate('level-name', '../world'));
        $this->assertNotNull(PropertyModel::validate('level-name', ''));
        $this->assertNull(PropertyModel::validate('level-seed', ''));
        $this->assertNull(PropertyModel::validate('level-seed', '-123'));
        $this->assertNotNull(PropertyModel::validate('level-seed', "1\n2"));
    }

    public function testMotdMayHaveLineBreaksAndColourCodesButNoOtherControlCharacters(): void
    {
        $this->assertNull(PropertyModel::validate('motd', "§aHello\n§bWorld"));
        $this->assertNotNull(PropertyModel::validate('motd', "a\0b"));
        $this->assertNotNull(PropertyModel::validate('motd', "a\tb"));
        $this->assertNotNull(PropertyModel::validate('motd', "a\u{2028}b"));
        $this->assertNotNull(PropertyModel::validate('motd', str_repeat('x', PropertyModel::MAX_VALUE + 1)));
    }

    public function testUnlistedKeysAreFreeTextButTheKeyIsChecked(): void
    {
        $this->assertNull(PropertyModel::validate('rcon.password', 'p@ss=word:1'));
        $this->assertNull(PropertyModel::validate('max-tick-time', '60000'));
        foreach (['bad key', 'a=b', "a\nb", '', 'a/b', str_repeat('k', 101), '#comment'] as $key) {
            $this->assertNotNull(PropertyModel::validate($key, 'x'), json_encode($key));
        }
    }
}
