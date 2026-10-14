import { afterEach, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { createExtensionTestHost, createTestServer } from '@pterodactyl/sdk/testing';
import { http } from '@pterodactyl/sdk';
import PlayersSlot from './PlayersCard';

afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
});

const base = '/api/client/servers/abc12345/extensions/palworld-tools';

const live = {
    state: 'running',
    available: true,
    reason: null,
    message: null,
    port: 8212,
    info: { version: 'v0.6.2', name: 'Pals', description: null },
    metrics: { fps: 57, uptime: 3600, days: 4, players: 2, max_players: 32, base_camps: 3 },
    players: [
        {
            name: 'Anton',
            account_name: 'anton',
            user_id: 'steam_00000000000000001',
            player_id: 'A',
            level: 12,
            ping: 31,
            x: 120,
            y: -40,
            buildings: 5,
        },
        {
            name: 'Koen',
            account_name: 'koen',
            user_id: 'steam_00000000000000002',
            player_id: 'B',
            level: null,
            ping: null,
            x: null,
            y: null,
            buildings: null,
        },
    ],
};

function setup(overview: unknown, options: { tags?: string[]; invocation?: string; permissions?: string[] } = {}) {
    const get = vi.spyOn(http, 'get').mockResolvedValue({ data: overview });
    const post = vi.spyOn(http, 'post').mockResolvedValue({ data: live });
    const server = createTestServer({
        identifier: 'abc12345',
        owner: options.permissions === undefined,
        permissions: options.permissions ?? ['*'],
    });
    server.attributes.egg_tags = options.tags ?? ['steamcmd'];
    server.attributes.invocation = options.invocation ?? './Pal/Binaries/Linux/PalServer-Linux-Shipping Pal -port=8211';
    const host = createExtensionTestHost({ extensionId: 'palworld-tools', prefix: 'pal', server });
    render(<PlayersSlot data={server} />, { wrapper: host.Wrapper });
    return { host, get, post };
}

it('shows the info line and the online players', async () => {
    const { host } = setup(live);

    expect(await screen.findByText('Anton')).toBeTruthy();
    expect(screen.getByText('Palworld v0.6.2 · 57 FPS · Up 1h 0m · Day 4 · 2 of 32 players')).toBeTruthy();
    expect(screen.getByText('Level 12 · 31 ms · X 120, Y -40 · 5 buildings')).toBeTruthy();

    host.dispose();
});

it('kicks a player with a message after a confirm', async () => {
    const { host, post } = setup(live);

    const row = (await screen.findByText('Anton')).closest('li') as HTMLElement;
    fireEvent.click(Array.from(row.querySelectorAll('button')).find((button) => button.textContent === 'Kick') as HTMLElement);
    expect(await screen.findByText('Kick Anton?')).toBeTruthy();
    expect(post).not.toHaveBeenCalled();

    fireEvent.change(screen.getByLabelText('Message to the player (optional)'), { target: { value: 'AFK' } });
    fireEvent.click(screen.getAllByRole('button', { name: 'Kick' }).at(-1) as HTMLElement);
    await waitFor(() =>
        expect(post).toHaveBeenCalledWith(`${base}/players/action`, { action: 'kick', user_id: 'steam_00000000000000001', message: 'AFK' }),
    );

    host.dispose();
});

it('announces, saves the world and checks ids before unbanning', async () => {
    const { host, post } = setup(live);

    fireEvent.change(await screen.findByLabelText('Announcement'), { target: { value: 'Restart in 5 minutes' } });
    fireEvent.click(screen.getByRole('button', { name: 'Announce' }));
    await waitFor(() =>
        expect(post).toHaveBeenCalledWith(`${base}/players/action`, { action: 'announce', message: 'Restart in 5 minutes' }),
    );

    fireEvent.click(screen.getByRole('button', { name: 'Save world' }));
    await waitFor(() => expect(post).toHaveBeenCalledWith(`${base}/players/action`, { action: 'save' }));

    fireEvent.change(screen.getByLabelText('Player id to unban'), { target: { value: 'bad id' } });
    expect(screen.getByText('Use an id like steam_76561198000000000.')).toBeTruthy();
    expect((screen.getByRole('button', { name: 'Unban' }) as HTMLButtonElement).disabled).toBe(true);
    fireEvent.change(screen.getByLabelText('Player id to unban'), { target: { value: 'steam_00000000000000009' } });
    fireEvent.click(screen.getByRole('button', { name: 'Unban' }));
    await waitFor(() =>
        expect(post).toHaveBeenCalledWith(`${base}/players/action`, { action: 'unban', user_id: 'steam_00000000000000009' }),
    );

    host.dispose();
});

it('hides the actions without the players permission', async () => {
    const { host } = setup(live, { permissions: ['ext.palworld-tools.view'] });

    expect(await screen.findByText('Anton')).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Kick' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Announce' })).toBeNull();

    host.dispose();
});

it('explains a disabled REST API and turns it on', async () => {
    const { host, post } = setup({
        ...live,
        state: 'offline',
        available: false,
        reason: 'disabled',
        players: [],
        info: null,
        metrics: null,
    });
    post.mockResolvedValue({ data: { result: 'saved', port: 8212, allocated: true } });

    expect(await screen.findByText(/The REST API is off/)).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Turn on the REST API' }));
    fireEvent.click(await screen.findByRole('button', { name: 'Turn on' }));
    await waitFor(() => expect(post).toHaveBeenCalledWith(`${base}/settings/rest-api`, { restart: false }));

    host.dispose();
});

it('explains an unreachable or unallocated REST API', async () => {
    const unallocated = setup({ ...live, available: false, reason: 'not_allocated', port: 8212, players: [], info: null, metrics: null });
    expect(await screen.findByText(/is not one of this server's ports/)).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Turn on the REST API' })).toBeNull();
    unallocated.host.dispose();
    cleanup();

    const unreachable = setup({ ...live, available: false, reason: 'unreachable', players: [], info: null, metrics: null });
    expect(await screen.findByText(/could not connect to the REST API on port 8212/)).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Try again' })).toBeTruthy();
    unreachable.host.dispose();
});

it('stays hidden on other games', async () => {
    const other = setup(live, { tags: ['ark'], invocation: './ShooterGameServer' });
    await new Promise((resolve) => setTimeout(resolve, 50));
    expect(screen.queryByText('Players')).toBeNull();
    expect(other.get).not.toHaveBeenCalled();
    other.host.dispose();
});
