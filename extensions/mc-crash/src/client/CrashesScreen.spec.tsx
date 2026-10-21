import { afterEach, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { createExtensionTestHost, createTestServer } from '@pterodactyl/sdk/testing';
import { http } from '@pterodactyl/sdk';
import CrashesScreen from './screens/CrashesScreen';

// Screens are not registered in the test host, so links are plain anchors that carry their destination.
vi.mock('@pterodactyl/sdk', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@pterodactyl/sdk')>()),
    PanelLink: ({ destination, children, className }: { destination: unknown; children?: React.ReactNode; className?: string }) => (
        <a className={className} data-destination={JSON.stringify(destination)}>
            {children}
        </a>
    ),
}));

afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
});

const confirmedCulprit = {
    tier: 'confirmed',
    jars: [
        { file: 'ChestShopLite-2.1.0.jar', name: 'ChestShopLite', id: 'chestshoplite', version: '2.1.0', kind: 'plugin', installed: true },
    ],
    basis: 'The class SignListener (com.chestshoplite.listener) is inside ChestShopLite-2.1.0.jar.',
};

const reports = (patch: Record<string, unknown> = {}) => ({
    reports: [
        {
            path: 'crash-reports/crash-2026-10-12_21.30.14-server.txt',
            name: 'crash-2026-10-12_21.30.14-server.txt',
            kind: 'crash',
            size: 4096,
            modified: '2026-10-12T21:30:14',
            time: '2026-10-12T21:30:14',
            status: 'ok',
            verdict: {
                rule: null,
                severity: 'crash',
                title: 'Crash',
                verdict: 'NullPointerException: Cannot invoke "Sign.getLine(int)" because "sign" is null',
                fix: 'Update ChestShopLite to the newest version made for your Minecraft version.',
                culprit: confirmedCulprit,
                exception: 'java.lang.NullPointerException',
                message: 'Cannot invoke',
                also: [],
                meta: { kind: 'crash', description: 'Exception in server tick loop', minecraft: '1.21.1', java: '21.0.4' },
                time: '2026-10-12T21:30:14',
            },
        },
        {
            path: 'crash-reports/crash-2026-10-01_08.00.00-server.txt',
            name: 'crash-2026-10-01_08.00.00-server.txt',
            kind: 'crash',
            size: 3000,
            modified: '2026-10-01T08:00:00',
            time: '2026-10-01T08:00:00',
            status: 'ok',
            verdict: {
                rule: 'mixin-conflict',
                severity: 'crash',
                title: 'Mixin conflict',
                verdict: 'Two mods change the same game code: modernfix clashes with another mod (Redirect conflict).',
                fix: 'Remove or update one of the two mods.',
                culprit: {
                    tier: 'likely',
                    jars: [
                        {
                            file: 'modernfix-forge-5.19.jar',
                            name: 'ModernFix',
                            id: 'modernfix',
                            version: '5.19',
                            kind: 'mod',
                            installed: true,
                        },
                    ],
                    basis: 'The mixin file is called modernfix, which is usually the mod id. This is a guess.',
                },
                exception: null,
                message: null,
                also: [],
                meta: { kind: 'crash', description: null, minecraft: null, java: null },
                time: '2026-10-01T08:00:00',
            },
        },
    ],
    pending: 0,
    index: { total: 2, pending: 0 },
    links: true,
    sharing: true,
    ...patch,
});

const logs = () => ({
    sources: [
        {
            path: 'logs/latest.log',
            name: 'latest.log',
            live: true,
            size: 2048,
            modified: '2026-10-13T00:03:00',
            status: 'scanned',
            note: null,
            entries: 7,
        },
        {
            path: 'logs/2026-10-12-1.log.gz',
            name: '2026-10-12-1.log.gz',
            live: false,
            size: 900,
            modified: '2026-10-12T23:59:00',
            status: 'too_large',
            note: 'Too big to scan (40 MB).',
            entries: 0,
        },
    ],
    groups: [
        {
            id: 'plugin-exception:ChestShopLite',
            rule: 'plugin-exception',
            severity: 'error',
            level: 'ERROR',
            title: 'ChestShopLite threw an error while handling PlayerInteractEvent: NullPointerException.',
            fix: 'Update ChestShopLite.',
            culprit: confirmedCulprit,
            exception: 'java.lang.NullPointerException',
            count: 4012,
            first: '2026-10-12T23:58:44',
            last: '2026-10-13T00:01:05',
            stat: null,
            noise: false,
            sample: '[23:58:44 ERROR]: Could not pass event PlayerInteractEvent to ChestShopLite v2.1.0\njava.lang.NullPointerException',
        },
        {
            id: 'noise:moved-too-quickly',
            rule: null,
            severity: 'info',
            level: 'WARN',
            title: 'Players moving too fast (movement warnings)',
            fix: null,
            culprit: null,
            exception: null,
            count: 90,
            first: '2026-10-12T23:59:10',
            last: '2026-10-13T00:00:10',
            stat: null,
            noise: true,
            sample: 'Steve moved too quickly!',
        },
    ],
    pending: 0,
    index: { total: 2, pending: 0 },
    links: true,
    sharing: true,
});

function setup(permissions: string[] = ['*'], reportData = reports()) {
    const get = vi.spyOn(http, 'get').mockImplementation(async (url: string) => {
        if (url.endsWith('/logs')) return { data: logs() };
        if (url.endsWith('/share/preview'))
            return {
                data: {
                    text: 'Description: Exception in server tick loop\nconnected from [ip hidden]',
                    shown_all: true,
                    bytes: 70,
                    lines: 2,
                    hash: 'a'.repeat(40),
                    hidden: { ips: 3, commands: 1, jvm_args: 0 },
                },
            };
        if (url.endsWith('/raw'))
            return { data: { text: '---- Minecraft Crash Report ----\nDescription: Exception in server tick loop', truncated: false } };
        return { data: reportData };
    });
    const post = vi
        .spyOn(http, 'post')
        .mockImplementation(async (url: string) =>
            url.endsWith('/share') ? { data: { url: 'https://mclo.gs/AbC123' } } : { data: { new: 0 } },
        );

    const server = createTestServer({ identifier: 'abc12345', permissions, owner: permissions.includes('*') });
    const host = createExtensionTestHost({ extensionId: 'mc-crash', prefix: 'mccr', server });
    render(<CrashesScreen />, { wrapper: host.Wrapper });

    return { host, get, post };
}

it('shows the verdict, the culprit with its certainty, and the fix for each report', async () => {
    const { host, get } = setup();

    expect(await screen.findByText(/NullPointerException: Cannot invoke/)).toBeTruthy();
    expect(get).toHaveBeenCalledWith('/api/client/servers/abc12345/extensions/mc-crash/reports');
    expect(screen.getByText('Confirmed')).toBeTruthy();
    expect(screen.getByText(/ChestShopLite 2.1.0/)).toBeTruthy();
    expect(screen.getByText(/Update ChestShopLite to the newest version/)).toBeTruthy();
    expect(screen.getByText('12 Oct 2026, 21:30', { exact: false })).toBeTruthy();

    // A guess says so and is not called confirmed.
    expect(screen.getByText('Likely')).toBeTruthy();
    expect(screen.getByText(/This is a guess/)).toBeTruthy();
    expect(screen.getAllByText('Confirmed')).toHaveLength(1);

    host.dispose();
});

it('opens the culprit in Plugins or Mods when mc-content is installed', async () => {
    const { host } = setup();

    await screen.findByText(/NullPointerException: Cannot invoke/);
    expect(JSON.parse(screen.getByText('Open in Plugins').getAttribute('data-destination') ?? '')).toEqual({
        extension: 'mc-content',
        screen: 'plugins',
        params: { id: 'abc12345' },
    });
    expect(JSON.parse(screen.getByText('Open in Mods').getAttribute('data-destination') ?? '')).toEqual({
        extension: 'mc-content',
        screen: 'mods',
        params: { id: 'abc12345' },
    });

    host.dispose();
});

it('only names the jar when mc-content is not installed', async () => {
    const { host } = setup(['*'], reports({ links: false }));

    await screen.findByText(/NullPointerException: Cannot invoke/);
    expect(screen.queryByText('Open in Plugins')).toBeNull();
    expect(screen.getByText(/ChestShopLite 2.1.0/)).toBeTruthy();

    host.dispose();
});

it('marks the page as viewed when it opens', async () => {
    const { host, post } = setup();

    await waitFor(() => expect(post).toHaveBeenCalledWith('/api/client/servers/abc12345/extensions/mc-crash/viewed'));

    host.dispose();
});

it('loads the raw report only when the folded section is opened', async () => {
    const { host, get } = setup();

    await screen.findByText(/NullPointerException: Cannot invoke/);
    expect(get.mock.calls.some(([url]) => String(url).endsWith('/raw'))).toBe(false);

    fireEvent.click(screen.getAllByText('Raw report')[0]);
    expect(await screen.findByText(/Description: Exception in server tick loop/)).toBeTruthy();
    expect(get).toHaveBeenCalledWith('/api/client/servers/abc12345/extensions/mc-crash/raw', {
        params: { path: 'crash-reports/crash-2026-10-12_21.30.14-server.txt' },
    });

    host.dispose();
});

it('uploads to mclo.gs only after the public-link warning is confirmed', async () => {
    const { host, post } = setup();

    await screen.findByText(/NullPointerException: Cannot invoke/);
    fireEvent.click(screen.getAllByText('Raw report')[0]);
    fireEvent.click(await screen.findByRole('button', { name: 'Share' }));

    const dialog = await screen.findByRole('dialog');
    expect(within(dialog).getByText(/Anyone who has the link can read it/)).toBeTruthy();
    // The user sees the redacted text and what was removed before anything is sent.
    expect(await within(dialog).findByText(/connected from \[ip hidden\]/)).toBeTruthy();
    expect(within(dialog).getByText(/Removed: 3 IP addresses, 1 login or register command/)).toBeTruthy();
    expect(post.mock.calls.some(([url]) => String(url).endsWith('/share'))).toBe(false);

    await waitFor(() => expect((within(dialog).getByRole('button', { name: 'Upload' }) as HTMLButtonElement).disabled).toBe(false));
    fireEvent.click(within(dialog).getByRole('button', { name: 'Upload' }));
    expect(await screen.findByText('https://mclo.gs/AbC123')).toBeTruthy();
    expect(post).toHaveBeenCalledWith('/api/client/servers/abc12345/extensions/mc-crash/share', {
        path: 'crash-reports/crash-2026-10-12_21.30.14-server.txt',
        hash: 'a'.repeat(40),
    });

    host.dispose();
});

it('has no Share button without the share permission, or when the admin turned sharing off', async () => {
    const first = setup(['ext.mc-crash.view', 'file.read-content']);
    await screen.findByText(/NullPointerException: Cannot invoke/);
    fireEvent.click(screen.getAllByText('Raw report')[0]);
    await screen.findByText(/Description: Exception in server tick loop/);
    expect(screen.queryByRole('button', { name: 'Share' })).toBeNull();
    first.host.dispose();
    cleanup();

    const second = setup(['*'], reports({ sharing: false }));
    await screen.findByText(/NullPointerException: Cannot invoke/);
    fireEvent.click(screen.getAllByText('Raw report')[0]);
    await screen.findByText(/Description: Exception in server tick loop/);
    expect(screen.queryByRole('button', { name: 'Share' })).toBeNull();
    second.host.dispose();
});

it('groups log errors with a count and first and last seen, and folds the noise', async () => {
    const { host } = setup();

    fireEvent.click(await screen.findByRole('tab', { name: 'Log errors' }));
    expect(await screen.findByText(/ChestShopLite threw an error while handling PlayerInteractEvent/)).toBeTruthy();
    expect(screen.getByText('4,012 times')).toBeTruthy();
    expect(screen.getByText(/first 12 Oct 2026, 23:58/)).toBeTruthy();
    expect(screen.getByText(/last 13 Oct 2026, 00:01/)).toBeTruthy();
    expect(screen.getByText(/Harmless noise \(1 kind, 90 lines\)/)).toBeTruthy();

    // A file that was too big is said so.
    fireEvent.click(screen.getByText('Scanned files (2)'));
    expect(screen.getByText(/Too big to scan \(40 MB\)/)).toBeTruthy();

    host.dispose();
});

it('says so when nothing crashed', async () => {
    const { host } = setup(['*'], reports({ reports: [] }));

    expect(await screen.findByText(/No crash reports found/)).toBeTruthy();

    host.dispose();
});

it('shows progress while jars are still being read', async () => {
    const { host } = setup(['*'], reports({ index: { total: 12, pending: 9 } }));

    expect(await screen.findByText(/3 of 12 plugins and mods read/)).toBeTruthy();

    host.dispose();
});
