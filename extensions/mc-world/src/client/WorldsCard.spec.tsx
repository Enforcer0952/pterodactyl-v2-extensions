import { afterEach, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { createExtensionTestHost, createTestServer } from '@pterodactyl/sdk/testing';
import { http } from '@pterodactyl/sdk';
import WorldsCard from './WorldsCard';

afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
});

const overview = (patch: Record<string, unknown> = {}) => ({
    state: 'offline',
    properties_exists: true,
    active: 'world',
    active_valid: true,
    hardcore: false,
    worlds: [
        {
            name: 'world',
            active: true,
            modified: '2026-10-13T09:00:00Z',
            folders: ['world', 'world_nether', 'world_the_end'],
            layout: 'split',
        },
        { name: 'creative', active: false, modified: '2026-10-01T09:00:00Z', folders: ['creative'], layout: 'single' },
    ],
    backups: [
        {
            name: 'world-20261012-080000.tar.gz',
            world: 'world',
            stamp: '20261012-080000',
            size: 52428800,
            modified: '2026-10-12T08:00:00Z',
        },
    ],
    keep: 5,
    ...patch,
});

function setup(data = overview(), permissions: string[] = ['*']) {
    const get = vi.spyOn(http, 'get').mockResolvedValue({ data });
    const post = vi.spyOn(http, 'post').mockResolvedValue({ data: overview({ worlds: [] }) });
    const server = createTestServer({ identifier: 'abc12345', permissions, owner: permissions.includes('*') });
    const host = createExtensionTestHost({ extensionId: 'mc-world', prefix: 'mcw', server });
    render(<WorldsCard identifier={'abc12345'} />, { wrapper: host.Wrapper });

    return { host, get, post };
}

it('lists worlds with their folders and backups', async () => {
    const { host, get } = setup();

    expect(await screen.findByText('creative')).toBeTruthy();
    expect(get).toHaveBeenCalledWith('/api/client/servers/abc12345/extensions/mc-world/worlds');
    expect(screen.getByText('Active')).toBeTruthy();
    expect(screen.getByText(/world, world_nether, world_the_end/)).toBeTruthy();
    expect(screen.getByText(/2026-10-12 08:00:00 · 50.0 MB/)).toBeTruthy();
    expect(screen.getByText(/newest 5 backups of each world/)).toBeTruthy();
    expect(screen.queryByText('New hardcore run')).toBeNull();

    host.dispose();
});

it('shows the exact folders in the reset confirm and sends the seed', async () => {
    const { host, post } = setup();

    fireEvent.click(await screen.findByRole('button', { name: 'Reset' }));
    const dialog = await screen.findByRole('dialog');
    const folders = within(dialog).getByLabelText('Folders');
    expect(
        within(folders)
            .getAllByRole('listitem')
            .map((item) => item.textContent),
    ).toEqual(['world', 'world_nether', 'world_the_end']);

    fireEvent.change(within(dialog).getByLabelText('New seed'), { target: { value: '12345' } });
    fireEvent.click(within(dialog).getByRole('button', { name: /Delete world, world_nether and world_the_end/ }));

    await waitFor(() =>
        expect(post).toHaveBeenCalledWith('/api/client/servers/abc12345/extensions/mc-world/worlds/reset', {
            world: 'world',
            seed: '12345',
            backup: true,
            start: false,
        }),
    );

    host.dispose();
});

it('blocks a reset while the server runs and offers to stop it', async () => {
    const { host, post } = setup(overview({ state: 'running' }));

    fireEvent.click(await screen.findByRole('button', { name: 'Reset' }));
    const dialog = await screen.findByRole('dialog');
    expect(within(dialog).getByText('The server must be stopped')).toBeTruthy();
    const confirm = within(dialog).getByRole('button', { name: /Delete world/ }) as HTMLButtonElement;
    expect(confirm.disabled).toBe(true);

    fireEvent.click(within(dialog).getByRole('button', { name: 'Stop server' }));
    await waitFor(() => expect(post).toHaveBeenCalledWith('/api/client/servers/abc12345/power', { signal: 'stop' }));

    host.dispose();
});

it('offers a hardcore run on hardcore servers that backs up, uses a random seed and starts', async () => {
    const { host, post } = setup(overview({ hardcore: true }));

    fireEvent.click(await screen.findByRole('button', { name: 'New hardcore run' }));
    const dialog = await screen.findByRole('dialog');
    expect(within(dialog).queryByLabelText('New seed')).toBeNull();
    fireEvent.click(within(dialog).getByRole('button', { name: 'Delete and start a new run' }));

    await waitFor(() =>
        expect(post).toHaveBeenCalledWith('/api/client/servers/abc12345/extensions/mc-world/worlds/reset', {
            world: 'world',
            seed: '',
            backup: true,
            start: true,
        }),
    );

    host.dispose();
});

it('hides actions the user has no permission for', async () => {
    const { host } = setup(overview(), ['file.read']);

    expect(await screen.findByText('creative')).toBeTruthy();
    for (const name of ['Back up', 'Make active', 'Reset', 'Delete', 'Restore']) {
        expect(screen.queryByRole('button', { name })).toBeNull();
    }

    host.dispose();
});

it('says what is moved aside when restoring a backup', async () => {
    const { host, post } = setup();

    fireEvent.click(await screen.findByRole('button', { name: 'Restore' }));
    const dialog = await screen.findByRole('dialog');
    expect(
        within(dialog)
            .getAllByRole('listitem')
            .map((item) => item.textContent),
    ).toEqual(['world', 'world_nether', 'world_the_end']);
    fireEvent.click(within(dialog).getByRole('button', { name: 'Restore' }));

    await waitFor(() =>
        expect(post).toHaveBeenCalledWith('/api/client/servers/abc12345/extensions/mc-world/worlds/restore', {
            backup: 'world-20261012-080000.tar.gz',
        }),
    );

    host.dispose();
});

it('backs up before deleting another world unless the box is cleared', async () => {
    const { host } = setup();
    const del = vi.spyOn(http, 'delete').mockResolvedValue({ data: overview() });

    const open = async () => {
        // The first "Delete" in the worlds list belongs to the non-active world; backups have their own.
        fireEvent.click((await screen.findAllByRole('button', { name: 'Delete' }))[0]);
        return screen.findByRole('dialog');
    };

    let dialog = await open();
    expect(within(dialog).getByRole('checkbox', { name: 'Keep a backup first' }).getAttribute('aria-checked')).toBe('true');
    fireEvent.click(within(dialog).getByRole('button', { name: 'Delete world' }));
    await waitFor(() =>
        expect(del).toHaveBeenCalledWith('/api/client/servers/abc12345/extensions/mc-world/worlds/creative', { params: { backup: 1 } }),
    );

    dialog = await open();
    fireEvent.click(within(dialog).getByRole('checkbox', { name: 'Keep a backup first' }));
    fireEvent.click(within(dialog).getByRole('button', { name: 'Delete world' }));
    await waitFor(() =>
        expect(del).toHaveBeenLastCalledWith('/api/client/servers/abc12345/extensions/mc-world/worlds/creative', { params: { backup: 0 } }),
    );

    host.dispose();
});

it('always keeps the backup when resetting a hardcore world', async () => {
    const { host } = setup(overview({ hardcore: true }));

    fireEvent.click((await screen.findAllByRole('button', { name: 'Reset' }))[0]);
    const dialog = await screen.findByRole('dialog');
    const box = within(dialog).getByRole('checkbox', { name: /A backup is always made first/ });
    expect(box.getAttribute('aria-checked')).toBe('true');
    expect(box.getAttribute('aria-disabled') ?? box.getAttribute('data-disabled') ?? box.getAttribute('disabled')).not.toBeNull();

    host.dispose();
});
