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
        { name: 'MyWorld', size: 52428800, modified: '2026-10-13T10:00:00+00:00', active: true, has_bak: true },
        { name: 'Second', size: 1048576, modified: '2026-10-12T10:00:00+00:00', active: false, has_bak: false },
    ],
    backups: [{ file: 'Second-20261012-100000.wld', world: 'Second', stamp: '20261012-100000', size: 1048576, modified: null }],
    active: 'MyWorld',
    worlds_directory: 'saves/Worlds',
    backups_directory: 'saves/Backups',
    keep: 5,
    config_exists: true,
    variables: { name: true, name_editable: true },
});

function setup(state = 'offline') {
    const get = vi.spyOn(http, 'get').mockResolvedValue({ data: overview(state) });
    const post = vi.spyOn(http, 'post').mockResolvedValue({ data: { ...overview(state), result: { name: 'Second' } } });
    const server = createTestServer({ identifier: 'abc12345', permissions: ['*'] });
    server.attributes.egg_tags = ['terraria'];
    const host = createExtensionTestHost({ extensionId: 'terraria-tools', prefix: 'terra', server });
    render(<WorldsScreen />, { wrapper: host.Wrapper });
    return { host, get, post };
}

const base = '/api/client/servers/abc12345/extensions/terraria-tools/worlds';

it('lists worlds with the active one marked, and backups', async () => {
    const { host } = setup();

    expect(await screen.findByText('MyWorld')).toBeTruthy();
    expect(screen.getByText('Active')).toBeTruthy();
    expect(screen.getByText('Worlds (2)')).toBeTruthy();
    expect(screen.getByText('Backups (1)')).toBeTruthy();
    // The active world can be backed up but not switched to or deleted.
    expect(screen.getAllByRole('button', { name: 'Use' })).toHaveLength(1);

    host.dispose();
});

it('switches the world after a confirm', async () => {
    const { host, post } = setup();

    fireEvent.click(await screen.findByRole('button', { name: 'Use' }));
    expect(await screen.findByText('Use Second?')).toBeTruthy();
    expect(post).not.toHaveBeenCalled();

    fireEvent.click(screen.getByRole('button', { name: 'Use this world' }));
    await waitFor(() => expect(post).toHaveBeenCalledWith(`${base}/switch`, { name: 'Second' }));

    host.dispose();
});

it('backs up a world', async () => {
    const { host, post } = setup('running');

    const row = (await screen.findByText('MyWorld')).closest('li') as HTMLElement;
    fireEvent.click(Array.from(row.querySelectorAll('button')).find((button) => button.textContent === 'Back up') as HTMLElement);
    await waitFor(() => expect(post).toHaveBeenCalledWith(`${base}/backup`, { name: 'MyWorld' }));

    host.dispose();
});

it('disables switching, restoring and deleting while the server runs', async () => {
    const { host } = setup('running');

    expect(await screen.findByText('MyWorld')).toBeTruthy();
    expect((screen.getByRole('button', { name: 'Use' }) as HTMLButtonElement).disabled).toBe(true);
    expect((screen.getByRole('button', { name: 'Restore' }) as HTMLButtonElement).disabled).toBe(true);

    host.dispose();
});

it('checks the name of a new world', async () => {
    const { host, post } = setup();

    const input = (await screen.findByLabelText('World name')) as HTMLInputElement;
    fireEvent.change(input, { target: { value: 'second' } });
    expect(screen.getByText('A world with this name already exists.')).toBeTruthy();

    fireEvent.change(input, { target: { value: '../etc' } });
    expect(screen.getByText(/Use letters, numbers/)).toBeTruthy();
    expect((screen.getByRole('button', { name: 'Set up world' }) as HTMLButtonElement).disabled).toBe(true);

    fireEvent.change(input, { target: { value: 'Fresh' } });
    fireEvent.click(screen.getByRole('button', { name: 'Set up world' }));
    fireEvent.click(await screen.findByRole('button', { name: 'Set up' }));
    await waitFor(() => expect(post).toHaveBeenCalledWith(`${base}/new`, { name: 'Fresh', size: 2, difficulty: 0, seed: '' }));

    host.dispose();
});
