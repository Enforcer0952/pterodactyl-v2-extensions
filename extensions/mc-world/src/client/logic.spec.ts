import { describe, expect, it } from 'vitest';
import type { PropertyDef } from './api';
import { buildEdits, formatBytes, formatStamp, foldersSentence, isJavaServer, isStopped, validKey, validateProperty } from './logic';

const def = (patch: Partial<PropertyDef>): PropertyDef => ({
    key: 'x',
    group: 'general',
    type: 'string',
    label: 'X',
    help: '',
    default: '',
    ...patch,
});

describe('isJavaServer', () => {
    it('accepts the minecraft tag or the eula feature', () => {
        expect(isJavaServer(['minecraft'], [])).toBe(true);
        expect(isJavaServer([], ['EULA'])).toBe(true);
        expect(isJavaServer(null, null)).toBe(false);
        expect(isJavaServer(['ark'], [])).toBe(false);
    });

    it('leaves out Bedrock and proxies', () => {
        expect(isJavaServer(['minecraft', 'bedrock'], ['eula'])).toBe(false);
        expect(isJavaServer(['minecraft', 'proxy'], ['eula'])).toBe(false);
    });
});

describe('formatting', () => {
    it('formats sizes', () => {
        expect(formatBytes(null)).toBe('');
        expect(formatBytes(512)).toBe('512 B');
        expect(formatBytes(1536)).toBe('1.5 KB');
        expect(formatBytes(250 * 1024 * 1024)).toBe('250 MB');
        expect(formatBytes(5 * 1024 ** 3)).toBe('5.0 GB');
    });

    it('formats backup stamps', () => {
        expect(formatStamp('20261013-120500')).toBe('2026-10-13 12:05:00');
        expect(formatStamp('odd')).toBe('odd');
    });

    it('lists folders in a sentence', () => {
        expect(foldersSentence(['world'])).toBe('world');
        expect(foldersSentence(['world', 'world_nether', 'world_the_end'])).toBe('world, world_nether and world_the_end');
    });

    it('knows when the server is stopped', () => {
        expect(isStopped('offline')).toBe(true);
        expect(isStopped('running')).toBe(false);
        expect(isStopped('stopping')).toBe(false);
    });
});

describe('validateProperty', () => {
    it('checks numbers against their range', () => {
        const view = def({ key: 'view-distance', type: 'int', min: 2, max: 32 });
        expect(validateProperty(view, '10')).toBeNull();
        expect(validateProperty(view, '33')).toBe('At most 32.');
        expect(validateProperty(view, '1')).toBe('At least 2.');
        expect(validateProperty(view, '1.5')).toBe('Enter a whole number.');
        expect(validateProperty(view, '')).toBe('Enter a whole number.');
    });

    it('checks switches and selects', () => {
        expect(validateProperty(def({ type: 'bool' }), 'true')).toBeNull();
        expect(validateProperty(def({ type: 'bool' }), 'True')).not.toBeNull();
        const difficulty = def({ key: 'difficulty', type: 'select', options: [{ value: 'easy', label: 'Easy' }] });
        expect(validateProperty(difficulty, 'easy')).toBeNull();
        expect(validateProperty(difficulty, 'brutal')).not.toBeNull();
        expect(validateProperty(def({ key: 'level-type', type: 'select', options: [] }), 'minecraft:flat')).toBeNull();
        expect(validateProperty(def({ key: 'level-type', type: 'select', options: [] }), 'a b')).not.toBeNull();
    });

    it('checks the world name and seed', () => {
        const name = def({ key: 'level-name' });
        expect(validateProperty(name, 'Hardcore 3')).toBeNull();
        expect(validateProperty(name, '../world')).not.toBeNull();
        expect(validateProperty(name, 'world ')).not.toBeNull();
        expect(validateProperty(def({ key: 'level-seed' }), '')).toBeNull();
        expect(validateProperty(def({ key: 'level-seed' }), '1\n2')).not.toBeNull();
    });

    it('allows line breaks in a motd but no other control characters', () => {
        const motd = def({ key: 'motd', type: 'text' });
        expect(validateProperty(motd, '§aHello\n§bWorld')).toBeNull();
        expect(validateProperty(motd, 'a\tb')).not.toBeNull();
        expect(validateProperty(motd, 'a\u0000b')).not.toBeNull();
    });
});

describe('buildEdits', () => {
    it('keeps only keys that really changed', () => {
        const saved = { a: '1', b: '2', c: '3' };
        expect(buildEdits(saved, { a: '1', b: '9', c: null, d: 'new', gone: null })).toEqual([
            { key: 'b', value: '9' },
            { key: 'c', remove: true },
            { key: 'd', value: 'new' },
        ]);
    });
});

describe('validKey', () => {
    it('allows property-style keys only', () => {
        expect(validKey('rcon.password')).toBe(true);
        expect(validKey('max-tick-time')).toBe(true);
        for (const bad of ['', 'a b', 'a=b', 'a\nb', '#x']) expect(validKey(bad)).toBe(false);
    });
});
