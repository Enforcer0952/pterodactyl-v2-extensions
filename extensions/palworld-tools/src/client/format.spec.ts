import { expect, it } from 'vitest';
import { formatBytes, formatDuration, parseStamp, shortId } from './format';

it('formats sizes', () => {
    expect(formatBytes(0)).toBe('0 B');
    expect(formatBytes(1536)).toBe('1.5 KB');
    expect(formatBytes(52428800)).toBe('50.0 MB');
    expect(formatBytes(null)).toBe('');
    expect(formatBytes(-1)).toBe('');
});

it('formats durations', () => {
    expect(formatDuration(30)).toBe('30s');
    expect(formatDuration(3600)).toBe('1h 0m');
    expect(formatDuration(3900)).toBe('1h 5m');
    expect(formatDuration(90000)).toBe('1d 1h');
    expect(formatDuration(null)).toBe('');
});

it('reads backup stamps and rejects impossible dates', () => {
    expect(parseStamp('20261014-090807')?.toISOString()).toBe('2026-10-14T09:08:07.000Z');
    expect(parseStamp('20261314-090807')).toBeNull();
    expect(parseStamp('nope')).toBeNull();
});

it('shortens world ids', () => {
    expect(shortId('0123456789ABCDEF0123456789ABCDEF')).toBe('01234567…');
    expect(shortId('short')).toBe('short');
});
