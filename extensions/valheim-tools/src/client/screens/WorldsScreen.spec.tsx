import { afterEach, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { createExtensionTestHost, createTestServer } from '@pterodactyl/sdk/testing';
import { http } from '@pterodactyl/sdk';
import WorldsScreen from './WorldsScreen';

afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
});

const overview = (state: string) => ({
    state,
    worlds: [
        {
            name: 'Dedicated',
            format: 'folder',
            entries: ['Dedicated'],
            complete: true,
            size: 52428800,
            modified: '2026-10-13T10:00:00+00:00',
            active: true,
            automatic_backups: 2,
        },
        {
            name: 'Second',
            format: 'files',
            entries: ['Second.db', 'Second.fwl'],
            complete: true,
            size: 1048576,
            modified: '2026-10-12T10:00:00+00:00',
            active: false,
            automatic_backups: 0,
        },
    ],
    backups: [{ id: 'Second-20261012-100000', world: 'Second', kind: '', size: 1048576, modified: '2026-10-12T10:00:00+00:00' }],
    automatic_backups: [
        {
            id: 'Dedicated_backup_auto-20261013060000',
            world: 'Dedicated',
            stamp: 'auto-20261013060000',
            auto: true,
            format: 'folder',
            size: 100,
            modified: null,
            time: '2026-10-13T06:00:00+00:00',
        },
    ],
    originals: [],
    active: 'Dedicated',
    active_exists: true,
    worlds_directory: '.config/unity3d/IronGate/Valheim/worlds_local',
    backups_directory: 'valheim-backups',
    keep: 5,
    variable: { exists: true, editable: true },
});

function setup(state = 'offline') {
    const get = vi.spyOn(http, 'get').mockResolvedValue({ data: overview(state) });
    const post = vi.spyOn(http, 'post').mockResolvedValue({ data: { ...overview(state), result: { name: 'Second' } } });
    const server = createTestServer({ identifier: 'abc12345', permissions: ['*'] });
    server.attributes.egg_tags = ['valheim'];
    const host = createExtensionTestHost({ extensionId: 'valheim-tools', prefix: 'vh', server });
    render(<WorldsScreen />, { wrapper: host.Wrapper });
    return { host, get, post };
}

const first = async (text: string) => (await screen.findAllByText(text))[0];

const base = '/api/client/servers/abc12345/extensions/valheim-tools/worlds';

it('lists worlds, backups and the automatic backups', async () => {
    const { host } = setup();

    expect(await first('Dedicated')).toBeTruthy();
    expect(screen.getByText('Active')).toBeTruthy();
    expect(screen.getByText('Worlds (2)')).toBeTruthy();
    expect(screen.getByText('Backups (1)')).toBeTruthy();
    expect(screen.getByText("Valheim's automatic backups (1)")).toBeTruthy();
    // The active world cannot be switched to or deleted.
    expect(screen.getAllByRole('button', { name: 'Use' })).toHaveLength(1);

    host.dispose();
});

it('switches the world after a confirm', async () => {
    const { host, post } = setup();

    fireEvent.click(await screen.findByRole('button', { name: 'Use' }));
    expect(await screen.findByText('Use Second?')).toBeTruthy();
    expect(post).not.toHaveBeenCalled();

    fireEvent.click(screen.getByRole('button', { name: 'Use this world' }));
    await waitFor(() => expect(post).toHaveBeenCalledWith(`${base}/switch`, { name: 'Second', create: false }));

    host.dispose();
});

it('backs up a stopped world straight away', async () => {
    const { host, post } = setup();

    const row = (await first('Dedicated')).closest('li') as HTMLElement;
    fireEvent.click(Array.from(row.querySelectorAll('button')).find((button) => button.textContent === 'Back up') as HTMLElement);
    await waitFor(() => expect(post).toHaveBeenCalledWith(`${base}/backup`, { name: 'Dedicated', running: false }));

    host.dispose();
});

it('asks before backing up a running world', async () => {
    const { host, post } = setup('running');

    const row = (await first('Dedicated')).closest('li') as HTMLElement;
    fireEvent.click(Array.from(row.querySelectorAll('button')).find((button) => button.textContent === 'Back up') as HTMLElement);
    expect(await screen.findByText(/Back up Dedicated while the server runs/)).toBeTruthy();
    expect(post).not.toHaveBeenCalled();

    fireEvent.click(screen.getByRole('button', { name: 'Back up anyway' }));
    await waitFor(() => expect(post).toHaveBeenCalledWith(`${base}/backup`, { name: 'Dedicated', running: true }));

    host.dispose();
});

it('restores a backup only while stopped', async () => {
    const { host, post } = setup();

    await first('Second');
    // The first Restore button belongs to the backup made here; the automatic one comes after it.
    fireEvent.click(screen.getAllByRole('button', { name: 'Restore' })[0]);
    expect(await screen.findByText('Restore Second?')).toBeTruthy();

    fireEvent.click(screen.getAllByRole('button', { name: 'Restore' }).at(-1) as HTMLElement);
    await waitFor(() => expect(post).toHaveBeenCalledWith(`${base}/restore`, { id: 'Second-20261012-100000', automatic: false }));

    host.dispose();
});

it('does not offer restore while the server runs', async () => {
    const { host } = setup('running');

    await first('Dedicated');
    for (const button of screen.getAllByRole('button', { name: 'Restore' })) expect((button as HTMLButtonElement).disabled).toBe(true);

    host.dispose();
});
