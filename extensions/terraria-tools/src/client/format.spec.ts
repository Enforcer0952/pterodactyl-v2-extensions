import { describe, expect, it } from 'vitest';
import { consolePresence, formatBytes, parseStamp } from './format';
import { encodeText, isTerraria, validWorldName, validateField, type SettingField } from './api';
import { cleanMessage } from './AdminCard';

describe('formatting', () => {
    it('formats sizes', () => {
        expect(formatBytes(0)).toBe('0 B');
        expect(formatBytes(1536)).toBe('1.5 KB');
        expect(formatBytes(120 * 1024 * 1024)).toBe('120 MB');
        expect(formatBytes(-1)).toBe('');
    });

    it('reads backup stamps as UTC', () => {
        expect(parseStamp('20261013-143005')?.toISOString()).toBe('2026-10-13T14:30:05.000Z');
        expect(parseStamp('nope')).toBeNull();
        expect(parseStamp('20261345-143005')).toBeNull();
    });

    it('spots join and leave lines in the console', () => {
        expect(consolePresence('Anton has joined.')).toBe('joined');
        expect(consolePresence('\u001b[0mKoen the Great has left.')).toBe('left');
        expect(consolePresence('<Anton> has left the building')).toBeNull();
        expect(consolePresence('Server started')).toBeNull();
    });
});

describe('server check', () => {
    it('matches the tag or the startup command', () => {
        expect(isTerraria(['Terraria'], '')).toBe(true);
        expect(isTerraria(['srcds'], './TerrariaServer.bin.x86_64 -config serverconfig.txt')).toBe(true);
        expect(isTerraria(['srcds'], './srcds_run -game cs2')).toBe(false);
        expect(isTerraria(null, undefined)).toBe(false);
    });
});

describe('input rules', () => {
    it('accepts and rejects world names', () => {
        expect(validWorldName('MyWorld')).toBe(true);
        expect(validWorldName("Anton's World 2")).toBe(true);
        expect(validWorldName('../etc')).toBe(false);
        expect(validWorldName('a/b')).toBe(false);
        expect(validWorldName('a..b')).toBe(false);
        expect(validWorldName('.hidden')).toBe(false);
        expect(validWorldName('')).toBe(false);
        expect(validWorldName('x'.repeat(41))).toBe(false);
    });

    it('cleans messages to one line', () => {
        expect(cleanMessage('hello\nsave\r\nexit')).toBe('hello save exit');
        expect(cleanMessage('  hi  ')).toBe('hi');
    });

    it('encodes text as base64 with UTF-8', () => {
        expect(atob(encodeText('maxplayers=8\n'))).toBe('maxplayers=8\n');
        expect(encodeText('motd=é')).toBe('bW90ZD3DqQ==');
    });

    it('validates settings', () => {
        const base: SettingField = {
            key: 'maxplayers',
            label: 'Max players',
            kind: 'int',
            help: '',
            value: '8',
            set: true,
            present: true,
            min: 1,
            max: 255,
            choices: null,
        };
        expect(validateField(base, '8')).toBeNull();
        expect(validateField(base, '0')).not.toBeNull();
        expect(validateField(base, '256')).not.toBeNull();
        expect(validateField(base, 'abc')).not.toBeNull();
        const choice: SettingField = { ...base, key: 'difficulty', kind: 'choice', choices: [{ value: '0', label: 'Classic' }] };
        expect(validateField(choice, '0')).toBeNull();
        expect(validateField(choice, '9')).not.toBeNull();
        const text: SettingField = { ...base, key: 'motd', kind: 'text', max: 5 };
        expect(validateField(text, 'hello')).toBeNull();
        expect(validateField(text, 'hello!')).not.toBeNull();
        expect(validateField(text, 'a\nb')).not.toBeNull();
    });
});
