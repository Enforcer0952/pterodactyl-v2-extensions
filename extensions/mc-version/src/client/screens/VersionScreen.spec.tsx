import { afterEach, expect, it, vi } from 'vitest';
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { createExtensionTestHost, createTestServer } from '@pterodactyl/sdk/testing';
import { SocketEvent, http } from '@pterodactyl/sdk';
import type { Overview } from '../api';
import VersionScreen from './VersionScreen';

afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
});

const SOFTWARE = [
    { id: 'paper', label: 'Paper' },
    { id: 'purpur', label: 'Purpur' },
    { id: 'fabric', label: 'Fabric' },
    { id: 'vanilla', label: 'Vanilla' },
] as Overview['software'];

const IMAGES = [8, 11, 16, 17, 21, 25].map((java) => ({ label: `Java ${java}`, image: `ghcr.io/pterodactyl/yolks:java_${java}`, java }));

const overview = (patch: Partial<Overview> = {}): Overview => ({
    state: 'offline',
    jar: { name: 'server.jar', exists: true, size: 55143262, modified: '2026-10-13T09:00:00Z' },
    detected: { software: 'fabric', label: 'Fabric', version: '1.16.5', build: null, source: 'log' },
    pending: null,
    variables: [
        { name: 'MINECRAFT_VERSION', value: '1.21.4', editable: true },
        { name: 'BUILD_NUMBER', value: 'latest', editable: true },
    ],
    egg: { name: 'Paper', software: 'paper' },
    mismatches: [
        { code: 'egg_software', severity: 'warning', message: "The files look like Fabric, but this server's egg is made for Paper." },
        { code: 'version_variable', severity: 'warning', message: 'MINECRAFT_VERSION says 1.21.4, but the server last ran 1.16.5.' },
    ],
    java: {
        image: 'ghcr.io/pterodactyl/yolks:java_16',
        java: 16,
        images: IMAGES,
        recommended: IMAGES[1],
        for_version: '1.16.5',
        status: 'works',
        locked: false,
    },
    copies: [
        {
            name: 'server.jar.bak-20261013153000',
            created: '2026-10-13T15:30:00+00:00',
            size: 53944842,
            software: 'paper',
            version: '1.21.4',
        },
    ],
    software: SOFTWARE,
    can: { apply: true, java: true, rollback: true, stop: true, delete: true },
    ...patch,
});

const plan = {
    target: {
        software: 'paper',
        label: 'Paper',
        version: '1.21.4',
        build: '232',
        channel: 'STABLE',
        stable: true,
        size: 55143262,
        checksum: 'sha256',
    },
    jar: 'server.jar',
    keeps_copy: true,
    warnings: [
        { code: 'downgrade', severity: 'danger', message: 'This is a downgrade from 1.21.5 to 1.21.4.' },
        { code: 'family_switch', severity: 'danger', message: 'Mods do not run on Paper.' },
    ],
    needs_confirmation: true,
    needs_backup: true,
    verification: { content: false, note: 'Not verified: this jar is too large to read back, so only its size is checked.' },
    variables: [{ name: 'BUILD_NUMBER', from: 'latest', to: '232' }],
    java: { recommended: IMAGES[4], current: IMAGES[2].image, change: true, locked: false },
};

function setup(data: Overview = overview()) {
    const get = vi.spyOn(http, 'get').mockImplementation(async (url: string) => {
        if (url.endsWith('/versions')) {
            return {
                data: {
                    versions: [
                        { id: '1.21.4', stable: true },
                        { id: '1.21.1', stable: true },
                    ],
                },
            };
        }
        if (url.endsWith('/plan')) return { data: plan };
        return { data };
    });
    const post = vi.spyOn(http, 'post').mockResolvedValue({ data: { notes: [], overview: overview({ detected: data.detected }) } });
    const put = vi
        .spyOn(http, 'put')
        .mockResolvedValue({ data: { overview: overview({ java: { ...data.java, image: IMAGES[1].image, java: 11, status: 'ok' } }) } });

    const server = createTestServer({ identifier: 'abc12345', permissions: ['*'], owner: true });
    const host = createExtensionTestHost({ extensionId: 'mc-version', prefix: 'mcver', server });
    render(<VersionScreen />, { wrapper: host.Wrapper });

    return { host, get, post, put };
}

it('shows what runs and flags the differences', async () => {
    const { host } = setup();

    expect(await screen.findByText('Fabric')).toBeTruthy();
    expect(screen.getByText('1.16.5')).toBeTruthy();
    expect(screen.getByText('MINECRAFT_VERSION')).toBeTruthy();
    expect(screen.getByText(/the files look like Fabric/i)).toBeTruthy();
    expect(screen.getByText(/MINECRAFT_VERSION says 1.21.4/)).toBeTruthy();
    expect(screen.queryByText('Stop the server first')).toBeNull();

    host.dispose();
});

it('recommends the Java version for the detected Minecraft version and switches in one click', async () => {
    const { host, put } = setup();

    expect(await screen.findByText(/Java 11 is the one it is made for/)).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Switch to Java 11' }));

    await waitFor(() =>
        expect(put).toHaveBeenCalledWith('/api/client/servers/abc12345/extensions/mc-version/java', {
            image: 'ghcr.io/pterodactyl/yolks:java_11',
        }),
    );
    expect(await screen.findByText(/Java 11 is the right version for 1.16.5/)).toBeTruthy();

    host.dispose();
});

it('says the Java image is too old when the server cannot start on it', async () => {
    const data = overview();
    const { host } = setup({
        ...data,
        java: { ...data.java, java: 8, image: IMAGES[0].image, for_version: '1.21.4', recommended: IMAGES[4], status: 'too_old' },
    });

    expect(await screen.findByText(/Java 8 is too old for 1.21.4/)).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Switch to Java 21' })).toBeTruthy();

    host.dispose();
});

it('asks to stop a running server and keeps restore disabled', async () => {
    const { host } = setup(overview({ state: 'running' }));

    expect(await screen.findByText('Stop the server first')).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Stop' })).toBeTruthy();
    expect((screen.getByRole('button', { name: 'Restore' }) as HTMLButtonElement).disabled).toBe(true);

    await act(async () => host.emitWebsocket(SocketEvent.STATUS, 'offline'));
    await waitFor(() => expect(screen.queryByText('Stop the server first')).toBeNull());

    host.dispose();
});

it('lists the previous jar and restores it after confirming', async () => {
    const { host, post } = setup();

    expect(await screen.findByText('server.jar.bak-20261013153000')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Restore' }));

    const dialog = await screen.findByRole('dialog');
    expect(within(dialog).getByText(/becomes server.jar again, which is Paper 1.21.4/)).toBeTruthy();
    fireEvent.click(within(dialog).getAllByRole('button', { name: 'Restore' })[0]);

    await waitFor(() =>
        expect(post).toHaveBeenCalledWith('/api/client/servers/abc12345/extensions/mc-version/rollback', { restore_java: 1 }),
    );

    host.dispose();
});

it('reviews a switch, needs the risks acknowledged and applies it', async () => {
    const { host, post } = setup();

    await screen.findByText('Fabric');
    const picker = screen.getByRole('combobox', { name: /Minecraft version/ });
    await waitFor(() => expect((picker as HTMLInputElement).disabled).toBe(false));
    const trigger = picker.closest('label')?.querySelector('button') as HTMLElement;
    fireEvent.click(trigger);
    fireEvent.click(await screen.findByRole('option', { name: '1.21.4' }));
    fireEvent.click(screen.getByRole('button', { name: 'Review change' }));

    const dialog = await screen.findByRole('dialog');
    expect(await within(dialog).findByText(/Mods do not run on Paper/)).toBeTruthy();
    expect(within(dialog).getByText(/BUILD_NUMBER changes from latest to 232/)).toBeTruthy();
    expect(within(dialog).getByText(/Java changes to Java 21/)).toBeTruthy();

    const apply = within(dialog).getByRole('button', { name: 'Switch' }) as HTMLButtonElement;
    expect(apply.disabled).toBe(true);

    expect(within(dialog).getByText(/Not verified/)).toBeTruthy();
    fireEvent.click(within(dialog).getByRole('checkbox', { name: 'I understand the risks' }));
    // A downgrade needs the backup box too.
    expect(apply.disabled).toBe(true);
    fireEvent.click(within(dialog).getByRole('checkbox', { name: 'I have a backup of my world' }));
    await waitFor(() => expect(apply.disabled).toBe(false));
    fireEvent.click(apply);

    await waitFor(() =>
        expect(post).toHaveBeenCalledWith('/api/client/servers/abc12345/extensions/mc-version/apply', {
            software: 'fabric',
            version: '1.21.4',
            experimental: 0,
            confirmed: 1,
            set_java: 1,
            backup_confirmed: 1,
        }),
    );

    host.dispose();
});

it('shows the error when the page cannot load', async () => {
    vi.spyOn(http, 'get').mockRejectedValue(new Error('Request failed'));
    const server = createTestServer({ identifier: 'abc12345', permissions: ['*'], owner: true });
    const host = createExtensionTestHost({ extensionId: 'mc-version', prefix: 'mcver', server });
    render(<VersionScreen />, { wrapper: host.Wrapper });

    expect(await screen.findByText(/Request failed/)).toBeTruthy();

    host.dispose();
});
