import { afterEach, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { createExtensionTestHost, createTestServer } from '@pterodactyl/sdk/testing';
import { http } from '@pterodactyl/sdk';
import ListsScreen from './ListsScreen';

afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
});

const lists = (permitted: string[] = []) => ({
    state: 'offline',
    directory: '.config/unity3d/IronGate/Valheim',
    style: 'v',
    editable: true,
    lists: {
        admin: {
            file: 'adminlist.txt',
            exists: true,
            entries: [{ id: 'V_76561198051014133', steam_id: '76561198051014133', name: 'Big Bob', last_seen: null }],
        },
        banned: { file: 'bannedlist.txt', exists: false, entries: [] },
        permitted: {
            file: 'permittedlist.txt',
            exists: true,
            entries: permitted.map((id) => ({ id, steam_id: id, name: null, last_seen: null })),
        },
    },
});

function setup(data = lists()) {
    const get = vi.spyOn(http, 'get').mockResolvedValue({ data });
    const post = vi.spyOn(http, 'post').mockResolvedValue({ data: { ...data, added: true, removed: 1 } });
    const server = createTestServer({ identifier: 'abc12345', permissions: ['*'] });
    server.attributes.egg_tags = ['valheim'];
    const host = createExtensionTestHost({ extensionId: 'valheim-tools', prefix: 'vh', server });
    render(<ListsScreen />, { wrapper: host.Wrapper });
    return { host, get, post };
}

const base = '/api/client/servers/abc12345/extensions/valheim-tools/lists';

it('shows the three lists with names next to the IDs', async () => {
    const { host } = setup();

    expect(await screen.findByText('Big Bob')).toBeTruthy();
    expect(screen.getByText('Admins (1)')).toBeTruthy();
    expect(screen.getByText('Banned (0)')).toBeTruthy();
    expect(screen.getByText('bannedlist.txt does not exist yet.')).toBeTruthy();
    expect(screen.getByText(/V_76561198051014133/)).toBeTruthy();

    host.dispose();
});

it('warns that a filled permitted list locks everyone else out', async () => {
    const { host } = setup(lists(['V_76561198000000001']));

    expect(await screen.findByText(/Only they can join this server/)).toBeTruthy();

    host.dispose();
});

it('adds a player to a list', async () => {
    const { host, post } = setup();

    fireEvent.change(await screen.findByLabelText('Add to Banned'), { target: { value: '76561198000000001' } });
    fireEvent.click(screen.getAllByRole('button', { name: 'Add' })[1]);

    await waitFor(() => expect(post).toHaveBeenCalledWith(`${base}/add`, { list: 'banned', id: '76561198000000001', confirm: false }));

    host.dispose();
});

it('asks before removing', async () => {
    const { host, post } = setup();

    fireEvent.click(await screen.findByRole('button', { name: 'Remove' }));
    expect(await screen.findByText('Remove Big Bob?')).toBeTruthy();
    expect(post).not.toHaveBeenCalled();

    fireEvent.click(screen.getAllByRole('button', { name: 'Remove' }).at(-1) as HTMLElement);
    await waitFor(() => expect(post).toHaveBeenCalledWith(`${base}/remove`, { list: 'admin', id: 'V_76561198051014133' }));

    host.dispose();
});
