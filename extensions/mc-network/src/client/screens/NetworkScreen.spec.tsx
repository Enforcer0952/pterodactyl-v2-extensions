import { afterEach, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { createExtensionTestHost, createTestServer } from '@pterodactyl/sdk/testing';
import { http } from '@pterodactyl/sdk';
import NetworkScreen from './NetworkScreen';
import type { BackendRow, NetworkResponse, Problem } from '../api';

afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
});

const row = (name: string, address: string, extra: Partial<BackendRow> = {}): BackendRow => ({
    name,
    address,
    motd: '',
    motd_plain: '',
    restricted: false,
    host: 'localhost',
    port: Number(address.split(':')[1]),
    local: true,
    server: null,
    problems: [],
    ...extra,
});

const problem = (code: string, level: Problem['level'], message: string): Problem => ({ code, level, message });

const response = (overrides: Partial<NetworkResponse> = {}): NetworkResponse => ({
    status: 'ok',
    proxy_state: 'running',
    hash: 'a'.repeat(40),
    has_listener: true,
    servers: [
        row('lobby', 'localhost:12550', {
            motd: '&1Lobby &r&7- spawn',
            server: { id: 'aaaa0001', name: 'Lobby server', state: 'running' },
        }),
        row('Skyblock', 'localhost:25565', {
            problems: [problem('self', 'error', 'Points at the proxy itself (port 25565). Players would be sent back to the proxy.')],
        }),
        row('Terra', 'localhost:12556', {
            server: { id: 'aaaa0007', name: 'Terraria', state: 'offline' },
            problems: [problem('not_minecraft', 'error', 'Terraria is not a Minecraft Java server.')],
        }),
    ],
    priorities: ['lobby', 'Ghost'],
    forced_hosts: [{ host: 'play.example.com', server: 'lobby' }],
    problems: [{ ...problem('unknown_priority', 'error', 'The priority list uses "Ghost", but no server has that name.'), name: 'Ghost' }],
    panel_servers: [
        { id: 'aaaa0001', name: 'Lobby server', port: 12550 },
        { id: 'aaaa0002', name: 'The Kingdom', port: 12551 },
    ],
    ...overrides,
});

function setup(data: NetworkResponse, permissions: string[] = ['*']) {
    const get = vi.spyOn(http, 'get').mockResolvedValue({ data });
    const put = vi.spyOn(http, 'put').mockResolvedValue({ data: { ...data, saved: true } });
    const post = vi.spyOn(http, 'post').mockResolvedValue({ data: { restarting: true } });
    const server = createTestServer({ identifier: 'abc12345', permissions, owner: permissions.includes('*') });
    server.attributes.egg_tags = ['minecraft', 'proxy'];
    const host = createExtensionTestHost({ extensionId: 'mc-network', prefix: 'mcnet', server });
    render(<NetworkScreen />, { wrapper: host.Wrapper });

    return { host, get, put, post };
}

it('lists the backends with plain descriptions, matched servers and problem badges', async () => {
    const { host, get } = setup(response());

    expect(await screen.findByText('Lobby - spawn')).toBeTruthy();
    expect(get).toHaveBeenCalledWith('/api/client/servers/abc12345/extensions/mc-network/network');
    expect(screen.queryByText('&1Lobby &r&7- spawn')).toBeNull();

    expect(screen.getByText('Lobby server')).toBeTruthy();
    expect(screen.getByLabelText('running')).toBeTruthy();
    expect(screen.getByLabelText('offline')).toBeTruthy();

    expect(screen.getByText('Points at the proxy')).toBeTruthy();
    expect(screen.getByText('Not Minecraft')).toBeTruthy();
    expect(screen.getByText(/The priority list uses "Ghost"/)).toBeTruthy();
    expect(screen.getByText('3 problems found.', { exact: false })).toBeTruthy();

    host.dispose();
});

it('shows a clear message for Velocity and for a missing config.yml', async () => {
    setup(response({ status: 'velocity', servers: [] }));
    expect(await screen.findByText('Velocity is not supported yet')).toBeTruthy();
    cleanup();

    setup(response({ status: 'missing', servers: [] }));
    expect(await screen.findByText('No config.yml yet')).toBeTruthy();
});

it('removes a missing name from the priorities and saves with the file hash', async () => {
    const { host, put } = setup(response());

    fireEvent.click(await screen.findByRole('button', { name: 'Remove from list' }));
    expect(await screen.findByText('1 unsaved change')).toBeTruthy();

    fireEvent.click(screen.getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(put).toHaveBeenCalled());

    const [url, body] = put.mock.calls[0] as [string, Record<string, unknown>];
    expect(url).toBe('/api/client/servers/abc12345/extensions/mc-network/network');
    expect(body.hash).toBe('a'.repeat(40));
    expect(body.priorities).toEqual(['lobby']);
    expect((body.servers as { name: string; was: string }[]).map((server) => [server.name, server.was])).toEqual([
        ['lobby', 'lobby'],
        ['Skyblock', 'Skyblock'],
        ['Terra', 'Terra'],
    ]);

    expect(await screen.findByText('Restart the proxy to apply the changes')).toBeTruthy();
    host.dispose();
});

it('adds a panel server with localhost and its port, and a name that can be changed', async () => {
    const { host, put } = setup(response());

    // The Select is a combobox: open it and pick the second server.
    await screen.findByPlaceholderText('Add a panel server…');
    fireEvent.click(screen.getAllByLabelText('Open popup')[0]);
    fireEvent.click(await screen.findByRole('option', { name: /The Kingdom/ }));

    const dialog = await screen.findByRole('dialog');
    expect((within(dialog).getByLabelText('Name') as HTMLInputElement).value).toBe('TheKingdom');
    expect((within(dialog).getByLabelText('Address') as HTMLInputElement).value).toBe('localhost:12551');

    fireEvent.change(within(dialog).getByLabelText('Name'), { target: { value: 'Kingdom' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Add' }));

    await waitFor(() => expect(screen.getByText('1 unsaved change')).toBeTruthy());
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(put).toHaveBeenCalled());

    const body = put.mock.calls[0][1] as { servers: { name: string; address: string; was: string | null }[] };
    expect(body.servers.at(-1)).toMatchObject({ name: 'Kingdom', address: 'localhost:12551', was: null });
    host.dispose();
});

it('does not accept an invalid entry', async () => {
    const { host, put } = setup(response());

    fireEvent.click(await screen.findByRole('button', { name: 'Add other address' }));
    const dialog = await screen.findByRole('dialog');
    fireEvent.change(within(dialog).getByLabelText('Name'), { target: { value: 'bad name' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Add' }));

    expect(await within(dialog).findByText(/Use letters, numbers/)).toBeTruthy();
    expect(screen.queryByText(/unsaved change/)).toBeNull();
    expect(put).not.toHaveBeenCalled();
    host.dispose();
});

it('restarts the proxy after a confirmation', async () => {
    const { host, post } = setup(response());

    fireEvent.click(await screen.findByRole('button', { name: 'Remove from list' }));
    fireEvent.click(await screen.findByRole('button', { name: 'Save' }));
    fireEvent.click(await screen.findByRole('button', { name: 'Restart proxy' }));
    fireEvent.click(await screen.findByRole('button', { name: 'Restart' }));

    await waitFor(() => expect(post).toHaveBeenCalledWith('/api/client/servers/abc12345/extensions/mc-network/network/restart'));
    host.dispose();
});

it('is read-only without the file.update permission', async () => {
    const { host } = setup(response(), ['file.read']);

    expect(await screen.findByText('Lobby - spawn')).toBeTruthy();
    expect(screen.queryByRole('button', { name: /^Edit / })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Add other address' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Remove from list' })).toBeNull();
    host.dispose();
});
