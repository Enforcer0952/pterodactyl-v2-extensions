import { expect, it } from 'vitest';
import {
    MB,
    ago,
    axisTime,
    checkFit,
    formatBytes,
    formatCpu,
    formatDays,
    formatLimit,
    formatMb,
    levelFor,
    percentOf,
    type FitResource,
} from './format';

it('formats sizes in GB and MB', () => {
    expect(formatBytes(0)).toBe('0 B');
    expect(formatBytes(512)).toBe('512 B');
    expect(formatBytes(64 * MB)).toBe('64 MB');
    expect(formatBytes(1.5 * 1024 * MB)).toBe('1.5 GB');
    expect(formatBytes(16 * 1024 * MB)).toBe('16 GB');
    expect(formatBytes(150 * 1024 * MB)).toBe('150 GB');
    expect(formatBytes(2.25 * 1024 * 1024 * MB)).toBe('2.3 TB');
    expect(formatBytes(null)).toBe('Unknown');
    expect(formatBytes(undefined)).toBe('Unknown');
    expect(formatBytes(-5)).toBe('0 B');
    expect(formatMb(4096)).toBe('4 GB');
});

it('shows 0 as unlimited', () => {
    expect(formatLimit(0)).toBe('Unlimited');
    expect(formatLimit(2048 * MB)).toBe('2 GB');
    expect(formatCpu(12.34)).toBe('12.3%');
    expect(formatCpu(250.4)).toBe('250%');
    expect(formatCpu(null)).toBe('Unknown');
});

it('uses the panel thresholds for colour', () => {
    expect(levelFor(null)).toBe('muted');
    expect(levelFor(40)).toBe('success');
    expect(levelFor(75)).toBe('success');
    expect(levelFor(76)).toBe('warning');
    expect(levelFor(90)).toBe('warning');
    expect(levelFor(91)).toBe('destructive');
});

it('computes percentages without dividing by zero', () => {
    expect(percentOf(50, 200)).toBe(25);
    expect(percentOf(50, 0)).toBeNull();
    expect(percentOf(null, 10)).toBeNull();
    expect(percentOf(500, 100)).toBe(500);
});

it('says how long ago', () => {
    const now = Date.parse('2026-10-14T12:00:00Z');
    expect(ago(null, now)).toBe('never');
    expect(ago('2026-10-14T11:59:50Z', now)).toBe('just now');
    expect(ago('2026-10-14T11:57:00Z', now)).toBe('3 min ago');
    expect(ago('2026-10-14T09:00:00Z', now)).toBe('3 h ago');
    expect(ago('2026-10-10T12:00:00Z', now)).toBe('4 days ago');
    expect(formatDays(0.3)).toBe('less than a day');
    expect(formatDays(1.2)).toBe('1 day');
    expect(formatDays(12.6)).toBe('13 days');
});

it('labels the chart axis by range', () => {
    const t = Date.parse('2026-10-14T12:00:00Z') / 1000;
    expect(axisTime(t, 86_400)).toMatch(/\d{1,2}[:.]\d{2}/);
    expect(axisTime(t, 604_800)).toMatch(/Oct|14/);
});

const GB = 1024 * MB;
const room = (extra: Partial<FitResource> = {}): FitResource => ({
    host_total: 16 * GB,
    host_free: 6 * GB,
    panel_limit: 16 * GB,
    unallocated: 8 * GB,
    committed: 8 * GB,
    unlimited: 0,
    ...extra,
});

it('has no complaint when the limit fits', () => {
    expect(checkFit('memory', 4096, 0, room())).toEqual([]);
});

it('warns when it is more than is free on the host', () => {
    const problems = checkFit('memory', 7 * 1024, 0, room());
    expect(problems.map((p) => p.id)).toEqual(['host-free']);
    expect(problems[0].text).toContain('7 GB more memory');
    expect(problems[0].text).toContain('6 GB is free on the host');
});

it('warns when it is more than the node allows', () => {
    const problems = checkFit('disk', 9 * 1024, 0, room({ host_free: null, host_total: null }));
    expect(problems.map((p) => p.id)).toEqual(['allocation']);
    expect(problems[0].text).toContain('disk space');
    expect(problems[0].text).toContain('leaves 8 GB');
});

it('warns when servers could use more than the host has', () => {
    const problems = checkFit('memory', 5 * 1024, 0, room({ host_free: 10 * GB, unallocated: 20 * GB, committed: 13 * GB }));
    expect(problems.map((p) => p.id)).toEqual(['overcommit']);
    expect(problems[0].text).toContain('18 GB');
});

it('only needs room for the difference when a limit is raised', () => {
    // The server already has 4 GB: going to 8 GB needs 4 GB more, which fits in 6 GB free.
    expect(checkFit('memory', 8192, 4 * GB, room())).toEqual([]);
    // Lowering a limit never complains.
    expect(checkFit('memory', 1024, 4 * GB, room({ host_free: 0, unallocated: 0 }))).toEqual([]);
});

it('treats 0 and bad input as unlimited, not as a problem', () => {
    expect(checkFit('memory', 0, 0, room({ host_free: 0 }))).toEqual([]);
    expect(checkFit('memory', Number.NaN, 0, room({ host_free: 0 }))).toEqual([]);
    expect(checkFit('memory', -1, 0, room({ host_free: 0 }))).toEqual([]);
});
