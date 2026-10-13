<?php

namespace TerrariaTools\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TerrariaTools\Support\Terraria;

final class TerrariaTest extends TestCase
{
    public function test_detects_the_server_by_tag_or_startup_command(): void
    {
        $this->assertTrue(Terraria::matches(['Terraria'], ''));
        $this->assertTrue(Terraria::matches(['srcds'], './TerrariaServer.bin.x86_64 -config serverconfig.txt'));
        $this->assertTrue(Terraria::matches([], 'mono TerrariaServer.exe -x64'));
        $this->assertFalse(Terraria::matches(['srcds'], './srcds_run -game cs2'));
        $this->assertFalse(Terraria::matches([], ''));
    }

    public function test_text_is_one_clean_line(): void
    {
        $this->assertSame('hello world', Terraria::text("hello\nworld"));
        $this->assertSame('a b', Terraria::text("a\r\n\r\nb"));
        $this->assertSame('a b', Terraria::text("a\u{2028}b"));
        $this->assertSame('a b', Terraria::text("a\x00\x1b\x7fb"));
        $this->assertSame('hi', Terraria::text("  hi\t"));
        $this->assertNull(Terraria::text("\n\t "));
        $this->assertNull(Terraria::text(null));
        $this->assertSame('abc', Terraria::text('abcdef', 3));
    }

    /** @return iterable<string, array{string}> */
    public static function injections(): iterable
    {
        yield 'newline then command' => ["Bob\nexit"];
        yield 'carriage return' => ["Bob\rsave"];
        yield 'crlf' => ["hi\r\nsay pwned\r\nexit-nosave"];
        yield 'unicode line separator' => ["Bob\u{2028}exit"];
        yield 'paragraph separator' => ["Bob\u{2029}exit"];
        yield 'next line (C1)' => ["Bob\u{0085}exit"];
        yield 'vertical tab and form feed' => ["Bob\x0Bexit\x0Csave"];
        yield 'escape sequence' => ["Bob\x1b[2Jexit"];
        yield 'null byte' => ["Bob\x00exit"];
    }

    #[DataProvider('injections')]
    public function test_names_and_messages_cannot_carry_a_second_command(string $input): void
    {
        foreach ([Terraria::playerName($input), Terraria::text($input)] as $clean) {
            $this->assertNotNull($clean);
            $this->assertSame(0, preg_match('/[\p{Cc}\p{Zl}\p{Zp}]/u', $clean), 'control characters left in: '.json_encode($clean));
            $this->assertStringNotContainsString("\n", $clean);
            $this->assertStringNotContainsString("\r", $clean);
        }
    }

    public function test_player_names_are_capped_and_keep_inner_spaces(): void
    {
        $this->assertSame('Koen the Great', Terraria::playerName('Koen the Great'));
        $this->assertSame(Terraria::MAX_PLAYER_NAME, mb_strlen((string) Terraria::playerName(str_repeat('x', 500))));
        $this->assertNull(Terraria::playerName("\n"));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function newWorlds(): iterable
    {
        yield 'plain' => ['MyWorld', true];
        yield 'spaces and digits' => ['World 2', true];
        yield 'apostrophe' => ["Anton's World", true];
        yield 'dash underscore dot' => ['my-world_1.0', true];
        yield 'unicode letters' => ['Wêreld', true];
        yield 'forty characters' => [str_repeat('a', 40), true];
        yield 'forty one characters' => [str_repeat('a', 41), false];
        yield 'empty' => ['', false];
        yield 'parent folder' => ['..', false];
        yield 'traversal' => ['../etc/passwd', false];
        yield 'inner traversal' => ['a/../b', false];
        yield 'double dot in a name' => ['a..b', false];
        yield 'slash' => ['a/b', false];
        yield 'backslash' => ['a\\b', false];
        yield 'leading dot' => ['.hidden', false];
        yield 'trailing dot' => ['world.', false];
        yield 'leading space' => [' world', false];
        yield 'newline' => ["world\nexit", false];
        yield 'null byte' => ["world\x00", false];
        yield 'extension' => ['world.wld', true];
        yield 'url encoded' => ['%2e%2e%2f', false];
        yield 'dollar and semicolon' => ['a;rm -rf', false];
        yield 'quote' => ['a"b', false];
    }

    #[DataProvider('newWorlds')]
    public function test_new_world_names(string $name, bool $valid): void
    {
        $this->assertSame($valid, Terraria::validNewWorld($name), json_encode($name));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function fileNames(): iterable
    {
        yield 'plain' => ['MyWorld.wld', true];
        yield 'with spaces and brackets' => ['My World (2).wld', true];
        yield 'slash' => ['../MyWorld.wld', false];
        yield 'nested' => ['a/b.wld', false];
        yield 'backslash' => ['a\\b.wld', false];
        yield 'dots' => ['a..b.wld', false];
        yield 'hidden' => ['.wld', false];
        yield 'empty' => ['', false];
        yield 'newline' => ["a\nb.wld", false];
        yield 'too long' => [str_repeat('a', 256), false];
    }

    #[DataProvider('fileNames')]
    public function test_plain_file_names(string $name, bool $valid): void
    {
        $this->assertSame($valid, Terraria::plainFileName($name), json_encode($name));
    }

    /** @return iterable<string, array{?string, ?string}> */
    public static function paths(): iterable
    {
        yield 'container path' => ['/home/container/saves/Worlds', '/saves/Worlds'];
        yield 'trailing slash' => ['/home/container/saves/Worlds/', '/saves/Worlds'];
        yield 'relative' => ['saves/Worlds', '/saves/Worlds'];
        yield 'dot segments' => ['./saves/./Worlds', '/saves/Worlds'];
        yield 'double slashes' => ['/home/container//saves///Worlds', '/saves/Worlds'];
        yield 'the world file' => ['/home/container/saves/Worlds/My World.wld', '/saves/Worlds/My World.wld'];
        yield 'server root' => ['/home/container', '/'];
        yield 'parent' => ['/home/container/../etc', null];
        yield 'parent in the middle' => ['saves/../../etc', null];
        yield 'only parent' => ['..', null];
        yield 'other absolute path' => ['/etc/passwd', null];
        yield 'prefix lookalike' => ['/home/container-evil/x', null];
        yield 'windows separators' => ['saves\\..\\Worlds', null];
        yield 'newline' => ["saves\n/Worlds", null];
        yield 'empty' => ['', null];
        yield 'null' => [null, null];
    }

    #[DataProvider('paths')]
    public function test_server_paths(?string $input, ?string $expected): void
    {
        $this->assertSame($expected, Terraria::serverPath($input));
    }

    public function test_directories_fall_back_to_the_defaults(): void
    {
        $this->assertSame('/saves/Worlds', Terraria::worldsDirectory('/home/container/saves/Worlds'));
        $this->assertSame('/saves/Worlds', Terraria::worldsDirectory(null));
        $this->assertSame('/saves/Worlds', Terraria::worldsDirectory('/etc'));
        $this->assertSame('/saves/Worlds', Terraria::worldsDirectory('../../x'));
        $this->assertSame('/Worlds', Terraria::worldsDirectory('Worlds'));

        $this->assertSame('/saves/Backups', Terraria::backupsDirectory('/saves/Worlds'));
        $this->assertSame('/Backups', Terraria::backupsDirectory('/Worlds'));
        $this->assertSame('/a/b/Backups', Terraria::backupsDirectory('/a/b/Worlds'));
    }

    public function test_banlist_path(): void
    {
        $this->assertSame('/banlist.txt', Terraria::banlistPath(null));
        $this->assertSame('/banlist.txt', Terraria::banlistPath(''));
        $this->assertSame('/banlist.txt', Terraria::banlistPath('banlist.txt'));
        $this->assertSame('/config/bans.txt', Terraria::banlistPath('/home/container/config/bans.txt'));
        $this->assertSame('/banlist.txt', Terraria::banlistPath('../../etc/passwd'));
        $this->assertSame('/banlist.txt', Terraria::banlistPath('/etc/passwd'));
    }
}
