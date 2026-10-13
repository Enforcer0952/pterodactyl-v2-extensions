import { afterEach, expect, it, vi } from 'vitest';
import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { createExtensionTestHost, createTestServer } from '@pterodactyl/sdk/testing';
import { SocketEvent, http } from '@pterodactyl/sdk';
import PlayersSlot from './PlayersCard';

afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
});

function setup(tags: string[], invocation = './TerrariaServer.bin.x86_64 -config serverconfig.txt') {
    let players = [{ name: 'Anton' }, { name: 'Koen the Great' }];
    const get = vi.spyOn(http, 'get').mockImplementation(async (url: string) =>
        url.endsWith('/players/online')
            ? { data: { state: 'running', players } }
            : {
                  data: {
                      state: 'running',
                      exists: true,
                      path: 'banlist.txt',
                      editable: true,
                      entries: [{ name: 'Griefer', id: '192.168.1.30' }],
                  },
              },
    );
    const post = vi.spyOn(http, 'post').mockResolvedValue({ data: { sent: 'kick', name: 'Anton' } });

    const server = createTestServer({ identifier: 'abc12345', permissions: ['*'] });
    server.attributes.egg_tags = tags;
    server.attributes.invocation = invocation;
    const host = createExtensionTestHost({ extensionId: 'terraria-tools', prefix: 'terra', server });
    render(<PlayersSlot data={server} />, { wrapper: host.Wrapper });

    return { host, get, post, setPlayers: (next: { name: string }[]) => (players = next) };
}

it('lists online players', async () => {
    const { host } = setup(['srcds']);

    expect(await screen.findByText('Anton')).toBeTruthy();
    expect(screen.getByText('Koen the Great')).toBeTruthy();
    expect(screen.getByRole('tab', { name: 'Online (2)' })).toBeTruthy();

    host.dispose();
});

it('kicks a player and removes the row', async () => {
    const { host, post } = setup(['terraria']);

    const row = (await screen.findByText('Anton')).closest('li') as HTMLElement;
    fireEvent.click(Array.from(row.querySelectorAll('button')).find((button) => button.textContent === 'Kick') as HTMLElement);

    await waitFor(() =>
        expect(post).toHaveBeenCalledWith('/api/client/servers/abc12345/extensions/terraria-tools/players/action', {
            action: 'kick',
            name: 'Anton',
        }),
    );
    await waitFor(() => expect(screen.queryByText('Anton')).toBeNull());
    expect(screen.getByText('Koen the Great')).toBeTruthy();

    host.dispose();
});

it('asks before banning', async () => {
    const { host, post } = setup(['terraria']);

    const row = (await screen.findByText('Koen the Great')).closest('li') as HTMLElement;
    fireEvent.click(Array.from(row.querySelectorAll('button')).find((button) => button.textContent === 'Ban') as HTMLElement);

    expect(await screen.findByText('Ban Koen the Great?')).toBeTruthy();
    expect(post).not.toHaveBeenCalled();

    fireEvent.click(screen.getAllByRole('button', { name: 'Ban' }).at(-1) as HTMLElement);
    await waitFor(() =>
        expect(post).toHaveBeenCalledWith('/api/client/servers/abc12345/extensions/terraria-tools/players/action', {
            action: 'ban',
            name: 'Koen the Great',
        }),
    );

    host.dispose();
});

it('shows the ban list and unbans', async () => {
    const { host, post } = setup(['terraria']);

    fireEvent.click(await screen.findByRole('tab', { name: /Bans/ }));
    expect(await screen.findByText('Griefer')).toBeTruthy();
    expect(screen.getByText('192.168.1.30')).toBeTruthy();
    // The server is running, so unban waits for it to stop.
    expect((screen.getByRole('button', { name: 'Unban' }) as HTMLButtonElement).disabled).toBe(true);
    expect(post).not.toHaveBeenCalled();

    host.dispose();
});

it('refreshes after a join line in the console', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    const { host, get, setPlayers } = setup(['terraria']);

    expect(await screen.findByText('Anton')).toBeTruthy();
    const calls = get.mock.calls.length;

    setPlayers([{ name: 'Anton' }, { name: 'Koen the Great' }, { name: 'Sam' }]);
    await act(async () => host.emitWebsocket(SocketEvent.CONSOLE_OUTPUT, 'Sam has joined.'));
    await act(async () => {
        await vi.advanceTimersByTimeAsync(2000);
    });

    expect(await screen.findByText('Sam')).toBeTruthy();
    expect(get.mock.calls.length).toBeGreaterThan(calls);
    expect(get).toHaveBeenLastCalledWith('/api/client/servers/abc12345/extensions/terraria-tools/players/online', {
        params: { refresh: 1 },
    });

    vi.useRealTimers();
    host.dispose();
});

it('stays hidden on other games', async () => {
    const csgo = setup(['srcds'], './srcds_run -game cs2');
    await new Promise((resolve) => setTimeout(resolve, 50));
    expect(screen.queryByText('Players')).toBeNull();
    csgo.host.dispose();
});
