import { afterEach, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { createExtensionTestHost, createTestServer } from '@pterodactyl/sdk/testing';
import { http } from '@pterodactyl/sdk';
import PlayersSlot from './PlayersCard';

afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
});

const response = (state = 'running') => ({
    state,
    can_act: true,
    players: [
        { name: 'Big Bob', id: '76561198051014133', steam_id: '76561198051014133', steam: true, list_id: 'V_76561198051014133' },
        { name: 'Freya', id: 'Xbox_2535400000000001', steam_id: null, steam: false, list_id: null },
    ],
    recent: [
        {
            id: '76561198000000001',
            name: 'Ragnar',
            first_seen: '2026-10-01T10:00:00+00:00',
            last_seen: '2026-10-13T10:00:00+00:00',
            steam_id: '76561198000000001',
            list_id: 'V_76561198000000001',
        },
    ],
});

function setup(tags: string[], invocation = './valheim_server.x86_64 -nographics', state = 'running') {
    const get = vi.spyOn(http, 'get').mockResolvedValue({ data: response(state) });
    const post = vi.spyOn(http, 'post').mockResolvedValue({ data: { action: 'admin', id: 'V_76561198051014133', added: true } });
    const server = createTestServer({ identifier: 'abc12345', permissions: ['*'] });
    server.attributes.egg_tags = tags;
    server.attributes.invocation = invocation;
    const host = createExtensionTestHost({ extensionId: 'valheim-tools', prefix: 'vh', server });
    render(<PlayersSlot data={server} />, { wrapper: host.Wrapper });
    return { host, get, post };
}

const base = '/api/client/servers/abc12345/extensions/valheim-tools';

it('lists online and recently seen players', async () => {
    const { host } = setup(['valheim']);

    expect(await screen.findByText('Big Bob')).toBeTruthy();
    expect(screen.getByText('Freya')).toBeTruthy();
    expect(screen.getByText('Ragnar')).toBeTruthy();
    expect(screen.getByText('Players (2)')).toBeTruthy();
    // A player without a Steam ID cannot be listed.
    expect(screen.getByText('No Steam ID')).toBeTruthy();

    host.dispose();
});

it('renders nothing for other games', () => {
    const { host, get } = setup(['minecraft'], 'java -jar server.jar');

    expect(screen.queryByText('Players')).toBeNull();
    expect(get).not.toHaveBeenCalled();

    host.dispose();
});

it('recognises a Valheim server by its startup command', async () => {
    const { host } = setup(['srcds'], './valheim_server.x86_64 -nographics -batchmode');

    expect(await screen.findByText('Big Bob')).toBeTruthy();

    host.dispose();
});

it('makes a player admin with one confirmed click', async () => {
    const { host, post } = setup(['valheim']);

    const row = (await screen.findByText('Big Bob')).closest('li') as HTMLElement;
    fireEvent.click(Array.from(row.querySelectorAll('button')).find((button) => button.textContent === 'Make admin') as HTMLElement);

    expect(await screen.findByText('Make admin Big Bob?')).toBeTruthy();
    expect(post).not.toHaveBeenCalled();

    fireEvent.click(screen.getAllByRole('button', { name: 'Make admin' }).at(-1) as HTMLElement);
    await waitFor(() =>
        expect(post).toHaveBeenCalledWith(`${base}/players/action`, { action: 'admin', id: 'V_76561198051014133', confirm: false }),
    );

    host.dispose();
});

it('says so when the server is stopped', async () => {
    const { host } = setup(['valheim'], './valheim_server.x86_64', 'offline');

    expect(await screen.findByText(/The server is offline/)).toBeTruthy();

    host.dispose();
});
