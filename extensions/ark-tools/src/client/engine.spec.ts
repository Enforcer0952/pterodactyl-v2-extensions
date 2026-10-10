import { describe, expect, it } from 'vitest';
import { assist, complete, type CommandDef } from './engine';
import data from './data/commands.json';
import { parseModInput } from './api';
import { resolveSteamId } from './PlayersCard';

const commands: CommandDef[] = data.map((command) => ({ ...command, source: 'ARK' }));
const players = [
    { name: 'Rex Rider', steam_id: '76561198000000001', online: true },
    { name: 'Dodo', steam_id: '76561198000000002' },
];

describe('ARK command suggestions', () => {
    it('suggests commands case-insensitively', () => {
        const result = assist('kick', commands, players);
        expect(result.suggestions.map((s) => s.value)).toContain('KickPlayer');
    });

    it('shows syntax and suggests steam ids with names', () => {
        const result = assist('KickPlayer ', commands, players);
        expect(result.suggestions).toEqual([
            { value: '76561198000000001', detail: 'Rex Rider (online)' },
            { value: '76561198000000002', detail: 'Dodo' },
        ]);
        expect(result.hints[0].parts.map((p) => p.text).join(' ')).toBe('KickPlayer <SteamID>');
    });

    it('filters steam ids by player name', () => {
        expect(assist('banplayer dod', commands, players).suggestions.map((s) => s.value)).toEqual(['76561198000000002']);
    });

    it('quotes names with spaces', () => {
        expect(assist('ServerChatToPlayer ', commands, players).suggestions[0].value).toBe('"Rex Rider"');
    });

    it('stops suggesting inside a message', () => {
        expect(assist('Broadcast hello the', commands, players).suggestions).toEqual([]);
    });

    it('completes the current word without a slash', () => {
        expect(complete('savew', 'SaveWorld')).toBe('SaveWorld ');
        expect(complete('KickPlayer 7656', '76561198000000001')).toBe('KickPlayer 76561198000000001 ');
    });
});

describe('input parsing', () => {
    it('reads workshop ids and links', () => {
        expect(parseModInput('https://steamcommunity.com/sharedfiles/filedetails/?id=731604991, 1999447172 731604991')).toEqual([
            '731604991',
            '1999447172',
        ]);
    });

    it('resolves steam ids and known names', () => {
        const known = [{ steam_id: '76561198000000002', name: 'Dodo', last_seen: '' }];
        expect(resolveSteamId('https://steamcommunity.com/profiles/76561198000000009/', known)).toBe('76561198000000009');
        expect(resolveSteamId('dodo', known)).toBe('76561198000000002');
        expect(resolveSteamId('nobody', known)).toBeNull();
    });
});
