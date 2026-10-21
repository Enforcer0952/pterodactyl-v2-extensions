import { describe, expect, it } from 'vitest';
import {
    badForcedHosts,
    cleanDraft,
    countChanges,
    draftFrom,
    isHostName,
    moveItem,
    parseAddress,
    plainMotd,
    renameServer,
    sameDraft,
    suggestName,
    validateEntry,
    type BackendRow,
    type Draft,
} from './api';

const row = (name: string, address: string): BackendRow => ({
    name,
    address,
    motd: '',
    motd_plain: '',
    restricted: false,
    host: null,
    port: null,
    local: true,
    server: null,
    problems: [],
});

const saved = draftFrom({
    servers: [row('lobby', 'localhost:12550'), row('Survival', 'localhost:12553')],
    priorities: ['lobby', 'Survival'],
    forced_hosts: [{ host: 'play.example.com', server: 'Survival' }],
});

describe('plainMotd', () => {
    it('strips colour and format codes', () => {
        expect(plainMotd('&1Lobby &r&7- spawn')).toBe('Lobby - spawn');
        expect(plainMotd('§aSurvival')).toBe('Survival');
        expect(plainMotd('&#ff8800Minetopia &lCity')).toBe('Minetopia City');
        expect(plainMotd('&x&f&f&0&0&0&0Hex')).toBe('Hex');
        expect(plainMotd('Fish & Chips')).toBe('Fish & Chips');
        expect(plainMotd('Stray § sign')).toBe('Stray sign');
        expect(plainMotd('One\nTwo')).toBe('One Two');
        expect(plainMotd('&a&l')).toBe('');
    });
});

describe('parseAddress', () => {
    it('reads host and port', () => {
        expect(parseAddress('localhost:25566')).toEqual({ host: 'localhost', port: 25566 });
        expect(parseAddress(' 127.0.0.1:1 ')).toEqual({ host: '127.0.0.1', port: 1 });
        expect(parseAddress('[2001:DB8::1]:25565')).toEqual({ host: '[2001:db8::1]', port: 25565 });
    });

    it('refuses anything else', () => {
        for (const text of [
            '',
            'localhost',
            'localhost:',
            ':25565',
            'localhost:0',
            'localhost:65536',
            'a b:25565',
            'localhost:1e3',
            'http://x:1',
            '-x:25565',
            '::1:25565',
            '[x]:1',
        ]) {
            expect(parseAddress(text), text).toBeNull();
        }
    });

    it('checks host names', () => {
        expect(isHostName('play.example.com')).toBe(true);
        expect(isHostName('bad host')).toBe(false);
        expect(isHostName('')).toBe(false);
    });
});

describe('validateEntry', () => {
    it('accepts a good entry', () => {
        expect(validateEntry({ name: 'Lobby-2', address: 'localhost:25566', motd: '&aHi' }, ['lobby'])).toEqual({});
    });

    it('explains what is wrong', () => {
        const errors = validateEntry({ name: 'has space', address: 'localhost', motd: 'a\nb' }, []);
        expect(Object.keys(errors).sort()).toEqual(['address', 'motd', 'name']);
        expect(validateEntry({ name: 'lobby', address: 'localhost:1', motd: '' }, ['lobby']).name).toMatch(/already/);
        expect(validateEntry({ name: '12345', address: 'localhost:1', motd: '' }, []).name).toMatch(/not a digit/);
        expect(validateEntry({ name: '1a', address: 'localhost:1', motd: '' }, []).name).toBeUndefined();
        expect(validateEntry({ name: 'x'.repeat(33), address: 'localhost:1', motd: '' }, []).name).toBeDefined();
        expect(validateEntry({ name: 'x', address: 'localhost:1', motd: 'y'.repeat(257) }, []).motd).toBeDefined();
    });
});

describe('suggestName', () => {
    it('makes a valid name from a panel name', () => {
        expect(suggestName('The Kingdom', [])).toBe('TheKingdom');
        expect(suggestName('Anton X Senna', [])).toBe('AntonXSenna');
        expect(suggestName('E-Active', [])).toBe('E-Active');
        expect(suggestName('日本語', [])).toBe('server');
        expect(suggestName('2024', [])).toBe('server2024');
    });

    it('keeps names unique and short', () => {
        expect(suggestName('Lobby', ['lobby'])).toBe('Lobby2');
        expect(suggestName('Lobby', ['Lobby', 'Lobby2'])).toBe('Lobby3');
        expect(suggestName('x'.repeat(40), [])).toHaveLength(32);
        expect(suggestName('x'.repeat(40), ['x'.repeat(32)])).toHaveLength(32);
    });
});

describe('draft helpers', () => {
    it('moves items inside the list only', () => {
        expect(moveItem(['a', 'b', 'c'], 0, 1)).toEqual(['b', 'a', 'c']);
        expect(moveItem(['a', 'b', 'c'], 2, -1)).toEqual(['a', 'c', 'b']);
        expect(moveItem(['a', 'b'], 0, -1)).toEqual(['a', 'b']);
        expect(moveItem(['a', 'b'], 1, 1)).toEqual(['a', 'b']);
    });

    it('renames a server in the priorities and forced hosts', () => {
        const next = renameServer(saved, 'Survival', 'Smp');
        expect(next.priorities).toEqual(['lobby', 'Smp']);
        expect(next.forced_hosts).toEqual([{ host: 'play.example.com', server: 'Smp' }]);
        expect(renameServer(saved, 'lobby', 'lobby')).toBe(saved);
    });

    it('counts changes against the saved file', () => {
        expect(countChanges(saved, saved)).toBe(0);

        const edited: Draft = {
            ...saved,
            servers: saved.servers.map((entry) => (entry.name === 'lobby' ? { ...entry, restricted: true } : entry)),
        };
        expect(countChanges(saved, edited)).toBe(1);

        const added: Draft = {
            ...saved,
            servers: [...saved.servers, { name: 'New', address: 'localhost:2', motd: '', restricted: false, was: null }],
        };
        expect(countChanges(saved, added)).toBe(1);

        const removed: Draft = { ...saved, servers: saved.servers.slice(1) };
        expect(countChanges(saved, removed)).toBe(1);

        const renamed: Draft = {
            ...renameServer(saved, 'Survival', 'Smp'),
            servers: saved.servers.map((entry) => (entry.name === 'Survival' ? { ...entry, name: 'Smp' } : entry)),
        };
        expect(countChanges(saved, renamed)).toBe(3);

        expect(countChanges(saved, { ...saved, priorities: ['Survival', 'lobby'] })).toBe(1);
    });

    it('treats drafts that save the same file as equal', () => {
        expect(
            sameDraft(
                saved,
                draftFrom({
                    servers: saved.servers.map((entry) => row(entry.name, entry.address)),
                    priorities: saved.priorities,
                    forced_hosts: [{ host: 'PLAY.example.com', server: 'Survival' }],
                }),
            ),
        ).toBe(true);
        expect(sameDraft(saved, { ...saved, priorities: ['lobby'] })).toBe(false);
    });
});

describe('forced hosts', () => {
    it('finds hosts that are invalid or listed twice', () => {
        const hosts = [
            { host: 'ok.example.com', server: 'lobby' },
            { host: 'OK.example.com', server: 'lobby' },
            { host: 'bad host', server: 'lobby' },
            { host: '', server: 'lobby' },
        ];
        expect(badForcedHosts(hosts)).toEqual([1, 2]);
    });

    it('leaves out rows that are not filled in', () => {
        const draft = {
            ...saved,
            forced_hosts: [
                { host: '', server: 'lobby' },
                { host: 'a.example.com', server: 'lobby' },
            ],
        };
        expect(cleanDraft(draft).forced_hosts).toEqual([{ host: 'a.example.com', server: 'lobby' }]);
    });
});
