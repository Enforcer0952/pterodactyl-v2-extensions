import { describe, expect, it } from 'vitest';
import { formatBytes, formatCount, loaderLabel, versionRange } from './format';

describe('format helpers', () => {
    it('shortens download counts', () => {
        expect(formatCount(950)).toBe('950');
        expect(formatCount(12_400)).toBe('12.4k');
        expect(formatCount(3_000_000)).toBe('3M');
    });

    it('formats file sizes', () => {
        expect(formatBytes(512)).toBe('512 B');
        expect(formatBytes(2048)).toBe('2 KiB');
        expect(formatBytes(5 * 1024 * 1024)).toBe('5.0 MiB');
    });

    it('labels loaders and version ranges', () => {
        expect(loaderLabel('neoforge')).toBe('NeoForge');
        expect(versionRange(['1.20.1', '1.20.4', '1.21', '1.21.4'])).toBe('1.20.1 – 1.21.4');
        expect(versionRange(['1.21.1'])).toBe('1.21.1');
    });
});
