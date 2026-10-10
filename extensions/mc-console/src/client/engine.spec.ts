import { describe, expect, it } from 'vitest';
import { assist, complete, tokenize, usageToSyntax, type CommandDef } from './engine';

const commands: CommandDef[] = [
    { name: 'ban', aliases: [], description: 'Bans a player.', syntax: ['ban <targets:players> [<reason:message>]'], source: 'Minecraft' },
    {
        name: 'gamemode',
        aliases: [],
        description: 'Sets a game mode.',
        syntax: ['gamemode (survival|creative|adventure|spectator) [<target:players>]'],
        source: 'Minecraft',
    },
    {
        name: 'whitelist',
        aliases: [],
        description: 'Manages the whitelist.',
        syntax: ['whitelist add <targets:players>', 'whitelist remove <targets:players>', 'whitelist on', 'whitelist off'],
        source: 'Minecraft',
    },
    {
        name: 'teleport',
        aliases: ['tp'],
        description: 'Teleports.',
        syntax: ['teleport <targets:entities> <location:pos3>'],
        source: 'Minecraft',
    },
    {
        name: 'execute',
        aliases: [],
        description: 'Runs a command.',
        syntax: ['execute as <targets:entities> run <command:command>'],
        source: 'Minecraft',
    },
    { name: 'lp', aliases: ['luckperms'], description: 'LuckPerms.', syntax: ['lp user <user:players> info'], source: 'LuckPerms' },
];
const players = ['Steve', 'Alex'];
const values = (input: string) => assist(input, commands, players).suggestions.map((s) => s.value);

describe('tokenize', () => {
    it('marks optional parts and choices', () => {
        expect(tokenize('effect give <t:entities> [<s:int> [<a:int>]]')).toEqual([
            { kind: 'literal', value: 'effect', optional: false },
            { kind: 'literal', value: 'give', optional: false },
            { kind: 'arg', name: 't', type: 'entities', optional: false },
            { kind: 'arg', name: 's', type: 'int', optional: true },
            { kind: 'arg', name: 'a', type: 'int', optional: true },
        ]);
    });
});

describe('assist', () => {
    it('suggests command names and aliases', () => {
        expect(values('g')).toEqual(['gamemode']);
        expect(values('/t')).toEqual(['tp', 'teleport']);
        expect(values('luck')).toEqual(['luckperms']);
    });

    it('suggests literals, choices and players', () => {
        expect(values('whitelist ')).toEqual(['add', 'remove', 'on', 'off']);
        expect(values('whitelist a')).toEqual(['add']);
        expect(values('gamemode c')).toEqual(['creative']);
        expect(values('gamemode creative S')).toEqual(['Steve']);
        expect(values('ban @')).toEqual(['@a', '@p', '@r', '@s', '@e', '@n']);
    });

    it('stops suggesting inside a reason and shows the syntax', () => {
        const result = assist('ban Steve griefing a', commands, players);
        expect(result.suggestions).toEqual([]);
        expect(result.hints[0].parts.map((p) => p.text).join(' ')).toBe('ban <targets> [<reason>]');
        expect(result.hints[0].parts.find((p) => p.current)?.text).toBe('[<reason>]');
    });

    it('understands coordinates and aliases', () => {
        expect(values('tp Steve ')).toEqual(['~ ~ ~']);
        expect(values('tp Steve 1 2 ')).toEqual([]);
    });

    it('continues into nested commands', () => {
        expect(values('execute as @a run ga')).toEqual(['gamemode']);
        expect(values('execute as @a run gamemode s')).toEqual(['survival', 'spectator']);
    });

    it('works for plugin commands', () => {
        expect(values('lp user A')).toEqual(['Alex']);
        expect(values('lp user Alex ')).toEqual(['info']);
    });
});

describe('helpers', () => {
    it('completes the current word', () => {
        expect(complete('gamemode cr', 'creative')).toBe('gamemode creative ');
        expect(complete('/gam', 'gamemode')).toBe('/gamemode ');
    });

    it('turns plugin usage into syntax', () => {
        expect(usageToSyntax('heal', '/<command> [player]')).toEqual(['heal [<player:players>]']);
        expect(usageToSyntax('warp', null)).toEqual(['warp']);
    });
});
