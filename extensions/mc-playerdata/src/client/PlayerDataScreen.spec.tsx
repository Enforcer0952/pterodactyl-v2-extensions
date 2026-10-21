import { afterEach, expect, it, vi } from 'vitest';
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { createExtensionTestHost, createTestServer } from '@pterodactyl/sdk/testing';
import { SocketEvent, http } from '@pterodactyl/sdk';
import PlayerDataScreen from './screens/PlayerDataScreen';
import type { Item, PlayerView } from './api';

vi.mock('@pterodactyl/sdk', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@pterodactyl/sdk')>()),
    PanelLink: ({ destination, children, className }: { destination: unknown; children?: React.ReactNode; className?: string }) => (
        <a className={className} data-destination={JSON.stringify(destination)}>
            {children}
        </a>
    ),
}));

afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
});

const item = (slot: number | string, id: string, name: string, patch: Partial<Item> = {}): Item => ({
    slot,
    id,
    name,
    modded: !id.startsWith('minecraft:'),
    count: 1,
    custom_name: null,
    item_name: null,
    damage: null,
    max_damage: null,
    unbreakable: false,
    enchantments: [],
    stored_enchantments: [],
    lore: [],
    contents: [],
    format: 'components',
    ...patch,
});

const view = (patch: Partial<PlayerView> = {}): PlayerView => ({
    state: 'offline',
    player: { uuid: '069a79f4-44e9-4726-a5be-fca90e38aaf5', name: 'Steve' },
    hash: 'a'.repeat(40),
    saved: '2026-10-20T12:00:00+00:00',
    size: 1500,
    world: 'world',
    backups: [],
    can_manage: true,
    can_edit: true,
    read_only: null,
    data: {
        data_version: 4325,
        inventory: [
            item(0, 'minecraft:diamond_sword', 'Diamond Sword', {
                custom_name: 'Excalibur',
                damage: 100,
                max_damage: 1561,
                enchantments: [
                    { id: 'minecraft:sharpness', name: 'Sharpness', level: 5 },
                    { id: 'minecraft:looting', name: 'Looting', level: 3 },
                ],
                lore: ['Pulled from a stone'],
            }),
            item(1, 'minecraft:cooked_beef', 'Cooked Beef', { count: 64 }),
            item(9, 'create:brass_ingot', 'create:brass_ingot', { count: 12 }),
        ],
        equipment: {
            head: item('head', 'minecraft:iron_helmet', 'Iron Helmet'),
            chest: null,
            legs: null,
            feet: null,
            offhand: item('offhand', 'minecraft:shield', 'Shield'),
        },
        ender: [item(3, 'minecraft:diamond', 'Diamond', { count: 64 })],
        other: [],
        selected_slot: 0,
        position: { x: 100.5, y: 64, z: -200.25, yaw: 90, pitch: 0 },
        dimension: { id: 'minecraft:overworld', name: 'Overworld' },
        spawn: { x: 10, y: 70, z: -20, dimension: { id: 'minecraft:overworld', name: 'Overworld' }, forced: false },
        xp: { level: 30, progress: 0.5, total: 1395 },
        health: 18.5,
        max_health: 20,
        food: { level: 15, saturation: 2.5 },
        game_mode: { id: 0, name: 'Survival' },
        effects: [{ id: 'minecraft:speed', name: 'Speed', level: 2, ticks: 1200, infinite: false, seconds: 60 }],
    },
    ...patch,
});

function setup(initial: PlayerView = view(), permissions: string[] = ['*']) {
    let current = initial;
    const get = vi.spyOn(http, 'get').mockImplementation(async (url: string) => {
        if (url.endsWith('/players')) {
            return {
                data: {
                    players: [
                        { name: 'Steve', uuid: initial.player.uuid, saved: null },
                        { name: 'Alex', uuid: 'b', saved: null },
                    ],
                },
            };
        }
        return { data: current };
    });
    const post = vi.spyOn(http, 'post').mockImplementation(async (_url: string, body?: unknown) => {
        const request = body as { action: string; slot?: number };
        if (request.action === 'remove-item') {
            current = {
                ...current,
                hash: 'b'.repeat(40),
                data: { ...current.data, inventory: current.data.inventory.filter((entry) => entry.slot !== request.slot) },
            };
        }
        return { data: { ...current, changed: true, backup: 'x.dat.mcpd-bak-20261021-120000' } };
    });

    const server = createTestServer({ identifier: 'abc12345', permissions, owner: permissions.includes('*') });
    const host = createExtensionTestHost({ extensionId: 'mc-playerdata', prefix: 'mcpd', server });
    render(<PlayerDataScreen data={{ pathname: '/server/abc12345/player-data/Steve', search: {}, params: { player: 'Steve' } }} />, {
        wrapper: host.Wrapper,
    });

    return { host, get, post };
}

it('shows the inventory with the hotbar apart, armor, the ender chest and the details', async () => {
    const { host, get } = setup();

    expect(await screen.findByRole('button', { name: 'Hotbar slot 1: Excalibur' })).toBeTruthy();
    expect(get).toHaveBeenCalledWith('/api/client/servers/abc12345/extensions/mc-playerdata/player', { params: { player: 'Steve' } });
    expect(screen.getByRole('button', { name: 'Hotbar slot 2: Cooked Beef, 64' })).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Inventory slot 1: create:brass_ingot, 12' })).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Head: Iron Helmet' })).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Off hand: Shield' })).toBeTruthy();
    expect(screen.getByLabelText('Chest: empty')).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Ender chest slot 4: Diamond, 64' })).toBeTruthy();
    expect(screen.getByText('Hotbar (slot 1 selected in game)')).toBeTruthy();

    expect(screen.getByText('100.5, 64, -200.25')).toBeTruthy();
    expect(screen.getAllByText(/in Overworld/)).toHaveLength(2);
    expect(screen.getByText(/Level 30/)).toBeTruthy();
    expect(screen.getByText('18.5 of 20')).toBeTruthy();
    expect(screen.getByText('Survival')).toBeTruthy();
    expect(screen.getByText(/Speed II/)).toBeTruthy();
    expect(screen.getByText(/1:00 left/)).toBeTruthy();

    host.dispose();
});

it('shows enchantments, lore and durability for the selected item', async () => {
    const { host } = setup();

    expect(await screen.findByText('Select an item to see its details.')).toBeTruthy();
    fireEvent.click(await screen.findByRole('button', { name: 'Hotbar slot 1: Excalibur' }));

    const list = await screen.findByRole('list', { name: 'Enchantments' });
    expect(within(list).getByText('Sharpness V')).toBeTruthy();
    expect(within(list).getByText('Looting III')).toBeTruthy();
    expect(screen.getByText('Pulled from a stone')).toBeTruthy();
    expect(screen.getByText('1461 of 1561 uses left')).toBeTruthy();
    expect(screen.getByText('minecraft:diamond_sword')).toBeTruthy();

    host.dispose();
});

it('removes a stack after confirming and sends the file hash', async () => {
    const { host, post } = setup();

    fireEvent.click(await screen.findByRole('button', { name: 'Hotbar slot 2: Cooked Beef, 64' }));
    fireEvent.click(await screen.findByRole('button', { name: 'Remove stack' }));

    // Nothing is sent until it is confirmed.
    expect(post).not.toHaveBeenCalled();
    expect(await screen.findByText('Remove this item?')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Remove' }));

    await waitFor(() => expect(post).toHaveBeenCalled());
    expect(post).toHaveBeenCalledWith('/api/client/servers/abc12345/extensions/mc-playerdata/player/edit', {
        player: 'Steve',
        hash: 'a'.repeat(40),
        action: 'remove-item',
        area: 'inventory',
        slot: 1,
    });

    // The screen shows the new file and the selection is gone.
    await waitFor(() => expect(screen.queryByRole('button', { name: /Cooked Beef/ })).toBeNull());
    expect(screen.getByText('Select an item to see its details.')).toBeTruthy();

    host.dispose();
});

it('can remove a single item from a stack', async () => {
    const { host, post } = setup();

    fireEvent.click(await screen.findByRole('button', { name: 'Hotbar slot 2: Cooked Beef, 64' }));
    fireEvent.click(await screen.findByRole('button', { name: 'Remove 1' }));
    fireEvent.click(await screen.findByRole('button', { name: 'Remove' }));

    await waitFor(() => expect(post).toHaveBeenCalled());
    expect(post.mock.calls[0][1]).toMatchObject({ action: 'remove-item', area: 'inventory', slot: 1, amount: 1 });

    host.dispose();
});

it('is read-only while the server runs, with the reason', async () => {
    const { host } = setup(
        view({ state: 'running', can_edit: false, read_only: 'The server is running, so this is read-only. Stop the server to edit.' }),
    );

    expect(await screen.findByText(/so this is read-only/)).toBeTruthy();
    fireEvent.click(await screen.findByRole('button', { name: 'Hotbar slot 2: Cooked Beef, 64' }));
    await screen.findByText('Cooked Beef', { selector: 'p' });
    expect(screen.queryByRole('button', { name: 'Remove stack' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Clear inventory' })).toBeNull();

    host.dispose();
});

it('offers the edit tools when the server is stopped and the user may manage', async () => {
    const { host } = setup();

    expect(await screen.findByRole('button', { name: 'Clear inventory' })).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Clear ender chest' })).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Clear effects' })).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Move to world spawn' })).toBeTruthy();

    host.dispose();
});

it('asks before clearing the inventory', async () => {
    const { host, post } = setup();

    fireEvent.click(await screen.findByRole('button', { name: 'Clear inventory' }));
    const dialog = await screen.findByRole('dialog');
    expect(within(dialog).getByText('Clear the inventory?')).toBeTruthy();
    expect(post).not.toHaveBeenCalled();

    fireEvent.click(within(dialog).getByRole('button', { name: 'Clear inventory' }));
    await waitFor(() => expect(post).toHaveBeenCalled());
    expect(post).toHaveBeenCalledWith('/api/client/servers/abc12345/extensions/mc-playerdata/player/edit', {
        player: 'Steve',
        hash: 'a'.repeat(40),
        action: 'clear-inventory',
    });

    host.dispose();
});

it('refuses a bad coordinate without a request', async () => {
    const { host, post } = setup();

    const y = (await screen.findAllByRole('textbox'))[1];
    fireEvent.change(y, { target: { value: '9999' } });
    fireEvent.click(screen.getByRole('button', { name: 'Move to these coordinates' }));

    expect(await screen.findByText('Y must be between -2048 and 4064.')).toBeTruthy();
    expect(post).not.toHaveBeenCalled();

    host.dispose();
});

it('turns editable when the server stops', async () => {
    const { host } = setup(view({ state: 'running', can_edit: false, read_only: 'The server is running, so this is read-only.' }));

    await screen.findByText(/so this is read-only/);
    expect(screen.queryByRole('button', { name: 'Clear inventory' })).toBeNull();

    await act(async () => host.emitWebsocket(SocketEvent.STATUS, 'offline'));
    expect(await screen.findByRole('button', { name: 'Clear inventory' })).toBeTruthy();

    host.dispose();
});

it('shows the reason when the player has no saved data', async () => {
    vi.spyOn(http, 'get').mockRejectedValue({
        isAxiosError: true,
        response: {
            status: 404,
            data: { errors: [{ code: 'NotFoundHttpException', status: '404', detail: 'There is no saved data for this player yet.' }] },
        },
    });
    const server = createTestServer({ identifier: 'abc12345', permissions: ['*'], owner: true });
    const host = createExtensionTestHost({ extensionId: 'mc-playerdata', prefix: 'mcpd', server });
    render(<PlayerDataScreen data={{ pathname: '/x', search: {}, params: { player: 'Steve' } }} />, { wrapper: host.Wrapper });

    expect(await screen.findByText(/no saved data for this player/)).toBeTruthy();
    host.dispose();
});

it('rejects an address that is not a name or a UUID', async () => {
    const get = vi.spyOn(http, 'get');
    const server = createTestServer({ identifier: 'abc12345', permissions: ['*'], owner: true });
    const host = createExtensionTestHost({ extensionId: 'mc-playerdata', prefix: 'mcpd', server });
    render(<PlayerDataScreen data={{ pathname: '/x', search: {}, params: { player: '../etc' } }} />, { wrapper: host.Wrapper });

    expect(await screen.findByText('That is not a valid player name or UUID.')).toBeTruthy();
    expect(get).not.toHaveBeenCalled();
    host.dispose();
});
