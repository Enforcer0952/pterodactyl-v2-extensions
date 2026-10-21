import { describe, expect, it } from 'vitest';
import type { Culprit, JarRef } from './api';
import {
    contentScreen,
    formatBytes,
    formatWhen,
    hiddenSummary,
    indexProgress,
    isBusy,
    isJavaOrProxy,
    jarLabel,
    jarNames,
    shareWarning,
    tierLabel,
    timesText,
} from './logic';

const jar = (patch: Partial<JarRef> = {}): JarRef => ({
    file: 'LuckPerms-Bukkit-5.4.jar',
    name: 'LuckPerms',
    id: 'luckperms',
    version: '5.4',
    kind: 'plugin',
    installed: true,
    ...patch,
});

describe('server gating', () => {
    it('lets Java servers and proxies through, not Bedrock', () => {
        expect(isJavaOrProxy(['minecraft'], null)).toBe(true);
        expect(isJavaOrProxy([], ['eula'])).toBe(true);
        expect(isJavaOrProxy(['velocity'], [])).toBe(true);
        expect(isJavaOrProxy(['BungeeCord'], undefined)).toBe(true);
        expect(isJavaOrProxy(['minecraft', 'bedrock'], null)).toBe(false);
        expect(isJavaOrProxy(['ark'], [])).toBe(false);
        expect(isJavaOrProxy(null, null)).toBe(false);
    });
});

describe('formatting', () => {
    it('shows server-local times without converting them', () => {
        expect(formatWhen('2026-10-12T21:30:14')).toBe('12 Oct 2026, 21:30');
        expect(formatWhen('2026-01-02T00:05:00')).toBe('2 Jan 2026, 00:05');
        expect(formatWhen('21:30:14')).toBe('21:30');
        expect(formatWhen(null)).toBe('');
        expect(formatWhen('whenever')).toBe('whenever');
    });

    it('writes sizes and counts', () => {
        expect(formatBytes(500)).toBe('500 B');
        expect(formatBytes(1536)).toBe('1.5 KB');
        expect(formatBytes(52428800)).toBe('50.0 MB');
        expect(formatBytes(null)).toBe('');
        expect(timesText(1)).toBe('once');
        expect(timesText(4012)).toBe('4,012 times');
    });
});

describe('culprit wording', () => {
    const culprit = (tier: Culprit['tier'], jars: JarRef[]): Culprit => ({ tier, jars, basis: 'x' });

    it('names one or several jars', () => {
        expect(jarNames(culprit('likely', [jar()]))).toBe('LuckPerms');
        expect(jarNames(culprit('likely', [jar(), jar({ name: 'Vault' })]))).toBe('LuckPerms or Vault');
        expect(jarNames(culprit('likely', [jar(), jar({ name: 'Vault' }), jar({ name: 'Essentials' })]))).toBe(
            'LuckPerms, Vault or Essentials',
        );
        expect(jarLabel(jar())).toBe('LuckPerms 5.4');
        expect(jarLabel(jar({ version: null }))).toBe('LuckPerms');
    });

    it('calls a guess likely and never confirmed', () => {
        expect(tierLabel(culprit('confirmed', [jar()]))).toBe('Confirmed');
        expect(tierLabel(culprit('likely', [jar()]))).toBe('Likely');
    });

    it('links to Plugins or Mods only for installed jars of a known kind', () => {
        expect(contentScreen(jar())).toBe('plugins');
        expect(contentScreen(jar({ kind: 'mod' }))).toBe('mods');
        expect(contentScreen(jar({ kind: null }))).toBeNull();
        expect(contentScreen(jar({ installed: false }))).toBeNull();
    });
});

describe('progress', () => {
    it('keeps asking while the server still reads jars or files', () => {
        expect(isBusy(undefined)).toBe(false);
        expect(isBusy({ pending: 0, index: { total: 4, pending: 0 } })).toBe(false);
        expect(isBusy({ pending: 2, index: { total: 4, pending: 0 } })).toBe(true);
        expect(isBusy({ pending: 0, index: { total: 4, pending: 1 } })).toBe(true);
        expect(indexProgress({ total: 12, pending: 9 })).toBe('3 of 12 plugins and mods read');
    });
});

describe('sharing', () => {
    it('warns that the link is public', () => {
        const text = shareWarning('latest.log');
        expect(text).toContain('latest.log');
        expect(text).toContain('public');
        expect(text).toContain('IP addresses');
        expect(text).toContain('removed first');
        expect(text).not.toContain('!');
    });

    it('summarises what was removed', () => {
        expect(hiddenSummary({ ips: 0, commands: 0, jvm_args: 0 })).toBe('');
        expect(hiddenSummary({ ips: 1, commands: 0, jvm_args: 0 })).toBe('1 IP address');
        expect(hiddenSummary({ ips: 3, commands: 2, jvm_args: 1 })).toBe(
            '3 IP addresses, 2 login or register commands, 1 JVM option value',
        );
    });
});
