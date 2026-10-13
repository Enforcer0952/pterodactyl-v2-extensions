import { describe, expect, it } from 'vitest';
import { JAVA_TEXT, formatBytes, formatWhen, javaName, keepSelection, sourceLabel, versionOptions } from './format';
import { showsVersion } from './index';

describe('formatBytes', () => {
    it('uses the largest fitting unit', () => {
        expect(formatBytes(0)).toBe('0 B');
        expect(formatBytes(1023)).toBe('1023 B');
        expect(formatBytes(1536)).toBe('1.5 KB');
        expect(formatBytes(55143262)).toBe('52.6 MB');
        expect(formatBytes(2 * 1024 ** 3)).toBe('2.0 GB');
    });

    it('is empty without a size', () => {
        expect(formatBytes(null)).toBe('');
        expect(formatBytes(undefined)).toBe('');
    });
});

describe('formatWhen', () => {
    it('ignores values that are not dates', () => {
        expect(formatWhen(null)).toBe('');
        expect(formatWhen('nonsense')).toBe('');
        expect(formatWhen('2026-10-13T15:30:00+00:00')).not.toBe('');
    });
});

describe('labels', () => {
    it('names where a version was read from', () => {
        expect(sourceLabel('log')).toContain('log');
        expect(sourceLabel('history')).toContain('version_history.json');
        expect(sourceLabel('folder')).toContain('versions folder');
        expect(sourceLabel(null)).toBe('');
    });

    it('names a Java image', () => {
        expect(javaName({ java: 21 })).toBe('Java 21');
        expect(javaName(null)).toBe('Unknown');
    });

    it('explains how the Java version fits', () => {
        expect(JAVA_TEXT.ok.text(21, 21, '1.21.4')).toBe('Java 21 is the right version for 1.21.4.');
        expect(JAVA_TEXT.works.text(16, 11, '1.16.5')).toContain('Java 11 is the one it is made for');
        expect(JAVA_TEXT.too_old.tone).toBe('danger');
        expect(JAVA_TEXT.too_old.text(16, 21, '1.20.6')).toContain('will not start');
    });
});

describe('version picker', () => {
    const versions = [
        { id: '26.4-snapshot-4', stable: false },
        { id: '26.3', stable: true },
        { id: '1.21.1', stable: true },
    ];

    it('marks the current version and snapshots', () => {
        expect(versionOptions(versions, '1.21.1').map((option) => option.label)).toEqual([
            '26.4-snapshot-4 (snapshot)',
            '26.3',
            '1.21.1 (current)',
        ]);
    });

    it('keeps a choice only while the list still offers it', () => {
        expect(keepSelection('26.3', versions)).toBe('26.3');
        expect(keepSelection('1.16.5', versions)).toBe('');
        expect(keepSelection('', versions)).toBe('');
    });
});

describe('showsVersion', () => {
    it('shows for Java servers by tag or by the eula feature', () => {
        expect(showsVersion(['minecraft'], null)).toBe(true);
        expect(showsVersion([], ['eula'])).toBe(true);
        expect(showsVersion(['Minecraft'], [])).toBe(true);
    });

    it('hides for proxies, Bedrock and other games', () => {
        expect(showsVersion(['minecraft', 'proxy'], ['eula'])).toBe(false);
        expect(showsVersion(['minecraft', 'bedrock'], null)).toBe(false);
        expect(showsVersion(['ark'], null)).toBe(false);
        expect(showsVersion([], null)).toBe(false);
    });
});
