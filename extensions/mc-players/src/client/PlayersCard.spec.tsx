import { afterEach, expect, it, vi } from 'vitest';
import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { createExtensionTestHost, createTestServer } from '@pterodactyl/sdk/testing';
import { SocketEvent, http } from '@pterodactyl/sdk';
import PlayersSlot from './PlayersCard';

afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
});

const lists = {
    state: 'running',
    whitelist_enabled: true,
    online_mode: true,
    whitelist: [{ name: 'Alex', uuid: 'a' }],
    ops: [{ name: 'Steve', uuid: 's', level: 4 }],
    bans: [{ name: 'Griefer', uuid: 'g', reason: 'Griefing', source: 'Steve', created: '2026-10-08 12:00:00 +0200', expires: 'forever' }],
    ip_bans: [],
    known: [
        { name: 'Steve', uuid: 's', last_seen: '2026-10-08T10:00:00+00:00' },
        { name: 'Griefer', uuid: 'g', last_seen: '2026-10-01T10:00:00+00:00' },
        { name: 'Alex', uuid: 'a', last_seen: '2026-09-20T10:00:00+00:00' },
    ],
};

function setup(tags: string[], features: string[] | null = null) {
    vi.spyOn(http, 'get').mockImplementation(async (url: string) =>
        url.endsWith('/online') ? { data: { state: 'running', online: { count: 1, max: 20, players: ['Steve'] } } } : { data: lists },
    );
    const post = vi
        .spyOn(http, 'post')
        .mockResolvedValue({ data: { ...lists, whitelist: [...lists.whitelist, { name: 'Notch', uuid: 'n' }] } });

    const server = createTestServer({ identifier: 'abc12345', permissions: ['*'] });
    server.attributes.egg_tags = tags;
    server.attributes.egg_features = features;
    const host = createExtensionTestHost({ extensionId: 'mc-players', prefix: 'mcp', server });
    render(<PlayersSlot data={server} />, { wrapper: host.Wrapper });

    return { host, post };
}

it('lists online players and follows joins from the console', async () => {
    const { host } = setup(['minecraft']);

    expect(await screen.findByText('Steve')).toBeTruthy();
    expect(screen.getByText('Online (1/20)')).toBeTruthy();
    expect(screen.getByText('Operator')).toBeTruthy();

    await act(async () => host.emitWebsocket(SocketEvent.CONSOLE_OUTPUT, '[12:00:00 INFO]: Alex joined the game'));
    expect(await screen.findByText('Alex')).toBeTruthy();

    await act(async () => host.emitWebsocket(SocketEvent.CONSOLE_OUTPUT, '[12:00:01 INFO]: Steve left the game'));
    await waitFor(() => expect(screen.queryByText('Steve')).toBeNull());
    expect(screen.getByText('Online (1/20)')).toBeTruthy();

    host.dispose();
});

it('adds a player to the whitelist', async () => {
    const { host, post } = setup(['minecraft']);

    fireEvent.click(await screen.findByRole('tab', { name: /Whitelist/ }));
    expect(await screen.findByText('Alex')).toBeTruthy();

    fireEvent.change(screen.getByLabelText('Username'), { target: { value: 'Notch' } });
    fireEvent.click(screen.getByRole('button', { name: 'Add' }));

    expect(await screen.findByText('Notch')).toBeTruthy();
    expect(post).toHaveBeenCalledWith('/api/client/servers/abc12345/extensions/mc-players/action', {
        action: 'whitelist-add',
        target: 'Notch',
        reason: null,
    });

    host.dispose();
});

it('stays hidden on Bedrock and non-Minecraft servers', () => {
    const bedrock = setup(['minecraft', 'bedrock']);
    expect(screen.queryByText('Players')).toBeNull();
    bedrock.host.dispose();
    cleanup();

    const proxy = setup(['minecraft', 'proxy'], ['eula']);
    expect(screen.queryByText('Players')).toBeNull();
    proxy.host.dispose();
    cleanup();

    const rust = setup(['rust'], []);
    expect(screen.queryByText('Players')).toBeNull();
    rust.host.dispose();
});

it('shows on untagged Java eggs that have the eula feature', async () => {
    const { host } = setup([], ['eula']);
    expect(await screen.findByText('Players')).toBeTruthy();
    host.dispose();
});

it('lists offline players with their status and actions', async () => {
    const { host, post } = setup(['minecraft']);

    fireEvent.click(await screen.findByRole('tab', { name: /Offline/ }));
    // Steve is online, so only Griefer and Alex are listed.
    expect(await screen.findByText('Griefer')).toBeTruthy();
    expect(screen.queryByText('Steve')).toBeNull();
    expect(screen.getByText('Banned')).toBeTruthy();
    expect(screen.getByText('Whitelisted')).toBeTruthy();
    expect(screen.getByRole('tab', { name: 'Offline (2)' })).toBeTruthy();

    fireEvent.click(screen.getByRole('button', { name: 'Unban' }));
    await waitFor(() =>
        expect(post).toHaveBeenCalledWith('/api/client/servers/abc12345/extensions/mc-players/action', {
            action: 'pardon',
            target: 'Griefer',
            reason: null,
        }),
    );

    host.dispose();
});
