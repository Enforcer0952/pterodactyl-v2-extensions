import { describe, expect, it } from 'vitest';
import { clampInt, describeEvent, describeVia, formatUptime, isMinecraft, tripSummary } from './format';
import type { GuardEvent } from './api';

const event = (overrides: Partial<GuardEvent>): GuardEvent => ({
    id: 1,
    kind: 'crash',
    status: 'confirmed',
    via: 'uptime-drop',
    uptime_before: 600_000,
    note: null,
    occurred_at: '2026-10-13T10:00:00+00:00',
    ...overrides,
});

describe('formatUptime', () => {
    it('reads like a person would', () => {
        expect(formatUptime(null)).toBe('unknown');
        expect(formatUptime(0)).toBe('unknown');
        expect(formatUptime(45_000)).toBe('45 s');
        expect(formatUptime(60_000)).toBe('1 min');
        expect(formatUptime(125 * 60_000)).toBe('2 h 5 min');
        expect(formatUptime(2 * 3_600_000)).toBe('2 h');
        expect(formatUptime(26 * 3_600_000)).toBe('1 d 2 h');
        expect(formatUptime(48 * 3_600_000)).toBe('2 d');
    });
});

describe('describing events', () => {
    it('says how a crash was found and how long the server had run', () => {
        expect(describeEvent(event({}))).toEqual({ title: 'Crash', detail: 'Restarted by itself after 10 min' });
        expect(describeEvent(event({ via: 'went-offline', uptime_before: 3_000 })).detail).toBe('Went offline after 3 s');
        expect(describeEvent(event({ via: 'restarted', uptime_before: null })).detail).toBe('Fell back to starting after unknown');
    });

    it('marks a crash that is still being checked', () => {
        const pending = describeEvent(event({ status: 'pending' }));
        expect(pending.title).toBe('Possible crash');
        expect(pending.detail).toContain('Checking');
    });

    it('titles the other entries', () => {
        expect(describeEvent(event({ kind: 'trip', note: '3 crashes in 10 minutes.' }))).toEqual({
            title: 'Server stopped by Crash Guard',
            detail: '3 crashes in 10 minutes.',
        });
        expect(describeEvent(event({ kind: 'blocked' })).title).toBe('Kept off');
        expect(describeEvent(event({ kind: 'release' })).title).toBe('Started again');
        expect(describeEvent(event({ kind: 'cleared' })).title).toBe('Cleared');
    });

    it('falls back for a reason it does not know', () => {
        expect(describeVia('something-new')).toBe('Crash');
        expect(describeVia(null)).toBe('Crash');
    });
});

describe('tripSummary', () => {
    it('matches the banner text', () => {
        expect(tripSummary(3, 10)).toBe('Crash Guard stopped this server after 3 crashes in 10 minutes');
        expect(tripSummary(2, 1)).toBe('Crash Guard stopped this server after 2 crashes in 1 minute');
    });
});

describe('isMinecraft', () => {
    it('uses the minecraft tag or the eula feature', () => {
        expect(isMinecraft(['Minecraft'], null)).toBe(true);
        expect(isMinecraft([], ['EULA'])).toBe(true);
        expect(isMinecraft(['ark'], ['steam'])).toBe(false);
        expect(isMinecraft(undefined, undefined)).toBe(false);
    });
});

describe('clampInt', () => {
    it('keeps a typed number inside the limits', () => {
        expect(clampInt('5', 2, 20, 3)).toBe(5);
        expect(clampInt('1', 2, 20, 3)).toBe(2);
        expect(clampInt('99', 2, 20, 3)).toBe(20);
        expect(clampInt(' 7 ', 2, 20, 3)).toBe(7);
    });

    it('uses the fallback for anything that is not a whole number', () => {
        expect(clampInt('', 2, 20, 3)).toBe(3);
        expect(clampInt('abc', 2, 20, 3)).toBe(3);
        expect(clampInt('1.5', 2, 20, 3)).toBe(3);
        expect(clampInt('-4', 2, 20, 3)).toBe(3);
    });
});
