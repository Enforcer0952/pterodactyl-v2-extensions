<?php

use PHPUnit\Framework\TestCase;
use ValheimTools\Support\Valheim;

class ValheimTest extends TestCase
{
    public function test_matches(): void
    {
        $this->assertTrue(Valheim::matches(['valheim'], 'x'));
        $this->assertTrue(Valheim::matches([], './valheim_server.x86_64 -nographics'));
        $this->assertTrue(Valheim::matches([], 'java -jar', '896660'));
        $this->assertFalse(Valheim::matches(['minecraft'], 'java -jar server.jar', '740'));
        $this->assertFalse(Valheim::matches([], ''));
    }

    public function test_world_names(): void
    {
        foreach (['Dedicated', 'My World', 'Midgard_2', 'a', 'World-1.5', 'Ärger'] as $name) {
            $this->assertTrue(Valheim::validWorldName($name), $name);
        }
        foreach (['', ' x', 'x ', '.hidden', '..', 'a..b', '../x', 'a/b', 'a\\b', 'a"b', 'a$b', 'a`b', 'a;b', "a\nb", str_repeat('a', 21), 'a$(id)', '-x '] as $name) {
            $this->assertFalse(Valheim::validWorldName($name), json_encode($name));
        }
    }

    public function test_plain_name(): void
    {
        $this->assertTrue(Valheim::plainName('Dedicated.db'));
        foreach (['', '.x', '..', 'a/b', "a\x00b", "a\nb", 'a\\b', 'x..y'] as $name) {
            $this->assertFalse(Valheim::plainName($name), json_encode($name));
        }
    }

    public function test_text_strips_controls(): void
    {
        $this->assertSame('a b', Valheim::text("a\x00\nb"));
        $this->assertNull(Valheim::text("\n\t "));
        $this->assertSame('abc', Valheim::text('abcdef', 3));
    }

    public function test_server_path(): void
    {
        $this->assertSame('/saves', Valheim::serverPath('/home/container/saves'));
        $this->assertSame('/saves/a', Valheim::serverPath('saves/./a/'));
        $this->assertSame('/', Valheim::serverPath('/home/container'));
        foreach (['/etc', '../x', 'a/../../x', '/home/container/../etc', 'a$b', 'a`b', "a\nb", 'a"b', '', '/home/container/{x}'] as $path) {
            $this->assertNull(Valheim::serverPath($path), $path);
        }
    }

    public function test_save_directory(): void
    {
        $this->assertSame('/.config/unity3d/IronGate/Valheim', Valheim::saveDirectory('./valheim_server.x86_64 -world "x"'));
        $this->assertSame('/valheim-data', Valheim::saveDirectory('./valheim_server.x86_64 -savedir /home/container/valheim-data -world "x"'));
        $this->assertSame('/data/v', Valheim::saveDirectory('./s -savedir "data/v"'));
        $this->assertSame('/.config/unity3d/IronGate/Valheim', Valheim::saveDirectory('./s -savedir /etc'));
        $this->assertSame('/.config/unity3d/IronGate/Valheim', Valheim::saveDirectory('./s -savedir ../../etc'));
        $this->assertSame('/.config/unity3d/IronGate/Valheim', Valheim::saveDirectory('./s -savedir $(id)'));
    }

    public function test_world_names_that_are_unsafe_in_the_start_command_are_refused(): void
    {
        foreach (['My World', 'Dedicated-2', 'a.b', 'Wereld_1'] as $name) {
            $this->assertTrue(Valheim::validWorldName($name), $name);
        }
        foreach (['', 'a"b', "a'b", 'a$b', '$(id)', 'a;b', 'a`b`', "a\nb", '../x', 'a..b', '.hidden', ' lead', 'trail ', str_repeat('a', 21)] as $name) {
            $this->assertFalse(Valheim::validWorldName($name), $name);
        }
    }
}
