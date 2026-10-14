import { expect, it } from 'vitest';
import { backupKind, compareVersions, formatBytes, formatCount, formatDate, needsPermitConfirm, parseFolderStamp } from './format';

it('formats sizes', () => {
    expect(formatBytes(0)).toBe('');
    expect(formatBytes(512)).toBe('512 B');
    expect(formatBytes(1536)).toBe('1.5 KB');
    expect(formatBytes(52428800)).toBe('50.0 MB');
    expect(formatBytes(-1)).toBe('');
});

it('formats counts', () => {
    expect(formatCount(950)).toBe('950');
    expect(formatCount(1200)).toBe('1.2K');
    expect(formatCount(45000)).toBe('45K');
    expect(formatCount(2306409)).toBe('2.3M');
});

it('formats dates and leaves bad ones empty', () => {
    expect(formatDate(null)).toBe('');
    expect(formatDate('nonsense')).toBe('');
    expect(formatDate('2026-10-14T10:00:00Z')).not.toBe('');
});

it('compares versions', () => {
    expect(compareVersions('1.10.0', '1.9.9')).toBeGreaterThan(0);
    expect(compareVersions('0.14.13', '0.14.14')).toBeLessThan(0);
    expect(compareVersions('5.4.2351', '5.4.2351')).toBe(0);
});

it('reads the stamp from a backup folder name', () => {
    expect(parseFolderStamp('My World-20261014-183005')?.toISOString()).toBe('2026-10-14T18:30:05.000Z');
    expect(parseFolderStamp('Dedicated-before-restore-20261014-183005')?.toISOString()).toBe('2026-10-14T18:30:05.000Z');
    expect(parseFolderStamp('World-20261342-183005')).toBeNull();
    expect(parseFolderStamp('nothing')).toBeNull();
});

it('names backup kinds', () => {
    expect(backupKind('')).toBe('Backup');
    expect(backupKind('before-restore')).toBe('Before a restore');
    expect(backupKind('before-delete')).toBe('Before a delete');
});

it('spots the permit confirmation message', () => {
    expect(needsPermitConfirm('The permitted list is empty, so everyone may join now.')).toBe(true);
    expect(needsPermitConfirm('Something else')).toBe(false);
});
