import { describe, expect, it } from 'vitest';
import { assist, complete, type CommandDef } from './engine';
import data from './data/commands.json';

const commands: CommandDef[] = data.map((command) => ({ ...command, source: 'Terraria' }));
const players = [
    { name: 'Anton', online: true },
    { name: 'Koen the Great', online: true },
];

describe('Terraria command suggestions', () => {
    it('suggests commands case-insensitively', () => {
        expect(assist('KI', commands, players).suggestions.map((s) => s.value)).toEqual(['kick']);
        expect(assist('mi', commands, players).suggestions.map((s) => s.value)).toEqual(['midnight']);
    });

    it('lists every vanilla command', () => {
        const names = assist('a', commands, players).suggestions.map((s) => s.value);
        expect(names).toEqual([]);
        const all = commands.map((command) => command.name);
        for (const name of [
            'help',
            'playing',
            'kick',
            'ban',
            'password',
            'save',
            'exit',
            'exit-nosave',
            'say',
            'time',
            'dawn',
            'noon',
            'dusk',
            'midnight',
            'settle',
            'version',
            'maxplayers',
            'motd',
        ]) {
            expect(all).toContain(name);
        }
    });

    it('shows the syntax of the command', () => {
        const result = assist('kick', commands, players);
        expect(result.hints[0].parts.map((p) => p.text).join(' ')).toBe('kick <Player>');
        expect(result.description).toMatch(/Disconnect/);
    });

    it('suggests online players for kick and ban', () => {
        expect(assist('kick ', commands, players).suggestions.map((s) => s.value)).toEqual(['Anton', 'Koen the Great']);
        expect(assist('ban an', commands, players).suggestions.map((s) => s.value)).toEqual(['Anton']);
    });

    it('matches names with spaces across words, without quotes', () => {
        const result = assist('kick koen th', commands, players);
        expect(result.suggestions).toEqual([{ value: 'Koen the Great', detail: 'online', start: 5, final: true }]);
        expect(complete('kick koen th', result.suggestions[0])).toBe('kick Koen the Great');
    });

    it('stops suggesting once the name is complete', () => {
        expect(assist('kick Anton', commands, players).suggestions).toEqual([]);
    });

    it('does not suggest anything inside a message', () => {
        expect(assist('say hello an', commands, players).suggestions).toEqual([]);
    });

    it('ignores unknown commands', () => {
        expect(assist('nothing ', commands, players)).toEqual({ suggestions: [], hints: [] });
    });

    it('completes the current word', () => {
        expect(complete('sav', 'save')).toBe('save ');
        expect(complete('kick An', { value: 'Anton', start: 5, final: true })).toBe('kick Anton');
    });
});
