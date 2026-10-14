import { afterEach, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { createExtensionTestHost, createTestServer } from '@pterodactyl/sdk/testing';
import { http } from '@pterodactyl/sdk';
import SavesScreen from './SavesScreen';

afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
});

const base = '/api/client/servers/abc12345/extensions/palworld-tools';
const WORLD = '0123456789ABCDEF0123456789ABCDEF';

const overview = (state: string) => ({
    state,
    worlds: [
        {
            id: WORLD,
            active: true,
            has_level: true,
            size: 52428800,
            modified: '2026-10-14T10:00:00+00:00',
            players: 3,
            game_backups: [{ name: '2026.10.14-09.00.00', at: '2026-10-14T09:00:00' }],
        },
    ],
    backups: [{ file: `${WORLD}-20261013-100000.tar.gz`, world: WORLD, stamp: '20261013-100000', size: 10485760, modified: null }],
    keep: 5,
    directory: 'palworld-backups',
    saves_directory: 'Pal/Saved/SaveGames/0',
});

function setup(state = 'offline') {
    const get = vi
        .spyOn(http, 'get')
        .mockImplementation(async (url: string) =>
            url.endsWith('/bans')
                ? { data: { state, exists: true, path: 'Pal/Saved/SaveGames/banlist.txt', ids: ['steam_00000000000000001'] } }
                : { data: overview(state) },
        );
    const post = vi
        .spyOn(http, 'post')
        .mockResolvedValue({ data: { ...overview(state), result: { kept: 'palworld-backups/replaced/x' } } });
    const server = createTestServer({ identifier: 'abc12345', permissions: ['*'] });
    const host = createExtensionTestHost({ extensionId: 'palworld-tools', prefix: 'pal', server });
    render(<SavesScreen />, { wrapper: host.Wrapper });
    return { host, get, post };
}

it('lists worlds, backups and bans', async () => {
    const { host } = setup();

    expect((await screen.findAllByText('01234567…')).length).toBe(2);
    expect(screen.getByText('Active')).toBeTruthy();
    expect(screen.getByText('Worlds (1)')).toBeTruthy();
    expect(screen.getByText('Backups (1)')).toBeTruthy();
    expect(await screen.findByText('steam_00000000000000001')).toBeTruthy();
    expect(screen.getByText('Ban list (1)')).toBeTruthy();

    host.dispose();
});

it('backs up a world, also while the server runs', async () => {
    const { host, post } = setup('running');

    fireEvent.click(await screen.findByRole('button', { name: 'Back up' }));
    await waitFor(() => expect(post).toHaveBeenCalledWith(`${base}/saves/backup`, { world: WORLD }));

    host.dispose();
});

it('restores a backup only after a confirm', async () => {
    const { host, post } = setup();

    fireEvent.click(await screen.findByRole('button', { name: 'Restore' }));
    expect(await screen.findByText('Restore 01234567…?')).toBeTruthy();
    expect(post).not.toHaveBeenCalled();
    fireEvent.click(screen.getAllByRole('button', { name: 'Restore' }).at(-1) as HTMLElement);
    await waitFor(() => expect(post).toHaveBeenCalledWith(`${base}/saves/restore`, { file: `${WORLD}-20261013-100000.tar.gz` }));

    host.dispose();
});

it('restores a game backup', async () => {
    const { host, post } = setup();

    fireEvent.click(await screen.findByRole('button', { name: /Show game backups \(1\)/ }));
    const row = (await screen.findByText('2026.10.14-09.00.00')).closest('li') as HTMLElement;
    fireEvent.click(row.querySelector('button') as HTMLElement);
    fireEvent.click(screen.getAllByRole('button', { name: 'Restore' }).at(-1) as HTMLElement);
    await waitFor(() =>
        expect(post).toHaveBeenCalledWith(`${base}/saves/restore-snapshot`, { world: WORLD, snapshot: '2026.10.14-09.00.00' }),
    );

    host.dispose();
});

it('disables restoring and unbanning while the server runs', async () => {
    const { host } = setup('running');

    expect((await screen.findAllByText('01234567…')).length).toBe(2);
    expect((screen.getByRole('button', { name: 'Restore' }) as HTMLButtonElement).disabled).toBe(true);
    expect((await screen.findByRole('button', { name: 'Remove' })).hasAttribute('disabled')).toBe(true);

    host.dispose();
});

it('removes a ban while stopped', async () => {
    const { host, post } = setup();

    fireEvent.click(await screen.findByRole('button', { name: 'Remove' }));
    fireEvent.click(await screen.findByRole('button', { name: 'Remove ban' }));
    await waitFor(() => expect(post).toHaveBeenCalledWith(`${base}/bans/remove`, { user_id: 'steam_00000000000000001' }));

    host.dispose();
});
