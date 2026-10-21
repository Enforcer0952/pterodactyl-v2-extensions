import { describe, expect, it } from 'vitest';
import { compatibleLoaders, installBlockedReason } from './compat';
import { isAllowedImage } from './images';

describe('installBlockedReason', () => {
    it('allows installs when the files say nothing', () => {
        expect(installBlockedReason(null, 'plugins', 'paper')).toBeNull();
    });

    it('blocks the wrong kind of content', () => {
        expect(installBlockedReason({ loader: 'fabric', kind: 'mods' }, 'plugins', 'paper')).toMatch(/loads mods/);
    });

    it('blocks a loader the server cannot run', () => {
        expect(installBlockedReason({ loader: 'neoforge', kind: 'mods' }, 'mods', 'forge')).toMatch(/neoforge/);
        expect(installBlockedReason({ loader: 'paper', kind: 'plugins' }, 'plugins', 'velocity')).not.toBeNull();
    });

    it('allows compatible loaders', () => {
        expect(installBlockedReason({ loader: 'paper', kind: 'plugins' }, 'plugins', 'spigot')).toBeNull();
        expect(installBlockedReason({ loader: 'quilt', kind: 'mods' }, 'mods', 'fabric')).toBeNull();
        expect(compatibleLoaders('purpur')).toContain('paper');
    });
});

describe('isAllowedImage', () => {
    it('accepts the Modrinth CDN and raw GitHub only', () => {
        expect(isAllowedImage('https://cdn.modrinth.com/data/a/b.png')).toBe(true);
        expect(isAllowedImage('https://raw.githubusercontent.com/a/b/main/x.png')).toBe(true);
        expect(isAllowedImage('http://cdn.modrinth.com/a.png')).toBe(false);
        expect(isAllowedImage('https://evil.example/a.png')).toBe(false);
        expect(isAllowedImage('https://cdn.modrinth.com.evil.example/a.png')).toBe(false);
        expect(isAllowedImage('javascript:alert(1)')).toBe(false);
        expect(isAllowedImage('')).toBe(false);
    });
});
