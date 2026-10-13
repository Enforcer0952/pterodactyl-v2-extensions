import { afterEach, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { createExtensionTestHost, createTestServer } from '@pterodactyl/sdk/testing';
import { http } from '@pterodactyl/sdk';
import PropertiesCard, { optionsFor } from './PropertiesCard';

afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
});

const settings = [
    { key: 'motd', group: 'general', type: 'text', label: 'Message of the day', help: '', default: 'A Minecraft Server' },
    { key: 'max-players', group: 'general', type: 'int', label: 'Max players', help: '', default: '20', min: 0, max: 100 },
    { key: 'hardcore', group: 'general', type: 'bool', label: 'Hardcore', help: '', default: 'false' },
    {
        key: 'difficulty',
        group: 'general',
        type: 'select',
        label: 'Difficulty',
        help: '',
        default: 'easy',
        options: [
            { value: 'easy', label: 'Easy' },
            { value: 'hard', label: 'Hard' },
        ],
    },
];

const response = (state = 'offline', hash = 'a'.repeat(40)) => ({
    state,
    exists: true,
    hash,
    entries: [
        { key: 'motd', value: '§aHello' },
        { key: 'max-players', value: '20' },
        { key: 'rcon.port', value: '25575' },
    ],
    schema: { groups: [{ id: 'general', label: 'General' }], settings },
});

function setup(state = 'offline') {
    vi.spyOn(http, 'get').mockResolvedValue({ data: response(state) });
    const put = vi.spyOn(http, 'put').mockResolvedValue({ data: { ...response(state, 'b'.repeat(40)), changed: ['max-players'] } });
    const server = createTestServer({ identifier: 'abc12345', permissions: ['*'] });
    const host = createExtensionTestHost({ extensionId: 'mc-world', prefix: 'mcw', server });
    render(<PropertiesCard identifier={'abc12345'} />, { wrapper: host.Wrapper });

    return { host, put };
}

it('shows common keys in the form and the rest in the table', async () => {
    const { host } = setup();

    expect(await screen.findByDisplayValue('§aHello')).toBeTruthy();
    expect(screen.getByDisplayValue('20')).toBeTruthy();
    expect(screen.getByLabelText('rcon.port')).toBeTruthy();
    expect(screen.queryByLabelText('motd')).toBeNull();
    expect(screen.queryByText('Save')).toBeNull();

    host.dispose();
});

it('sends only the changed key, base64-encoded, with the file hash', async () => {
    const { host, put } = setup();

    fireEvent.change(await screen.findByDisplayValue('20'), { target: { value: '40' } });
    expect(screen.getByText('1 unsaved change')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));

    await waitFor(() =>
        expect(put).toHaveBeenCalledWith('/api/client/servers/abc12345/extensions/mc-world/properties', {
            hash: 'a'.repeat(40),
            edits: [{ key: 'max-players', value: btoa('40') }],
        }),
    );
    await waitFor(() => expect(screen.queryByText(/unsaved change/)).toBeNull());

    host.dispose();
});

it('blocks saving a value outside its range', async () => {
    const { host, put } = setup();

    fireEvent.change(await screen.findByDisplayValue('20'), { target: { value: '500' } });
    expect(screen.getByText('At most 100.')).toBeTruthy();
    expect((screen.getByRole('button', { name: 'Save' }) as HTMLButtonElement).disabled).toBe(true);
    expect(put).not.toHaveBeenCalled();

    host.dispose();
});

it('removes and adds keys in the table', async () => {
    const { host, put } = setup();

    await screen.findByLabelText('rcon.port');
    fireEvent.click(screen.getByRole('button', { name: 'Remove' }));
    fireEvent.change(screen.getByLabelText('New key'), { target: { value: 'max-tick-time' } });
    fireEvent.change(screen.getByLabelText('New value'), { target: { value: '60000' } });
    fireEvent.click(screen.getByRole('button', { name: 'Add' }));
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));

    await waitFor(() => expect(put).toHaveBeenCalled());
    expect(put.mock.calls[0][1]).toEqual({
        hash: 'a'.repeat(40),
        edits: [
            { key: 'rcon.port', remove: true },
            { key: 'max-tick-time', value: btoa('60000') },
        ],
    });

    host.dispose();
});

it('warns that changes apply after a restart while the server runs', async () => {
    const { host } = setup('running');

    expect(await screen.findByText(/apply after a restart/)).toBeTruthy();

    host.dispose();
});

it('keeps a level-type the list does not know as an option', () => {
    const def = { ...settings[3], key: 'level-type' } as Parameters<typeof optionsFor>[0];
    expect(optionsFor(def, 'default').at(-1)).toEqual({ value: 'default', label: 'default (current)' });
    expect(optionsFor(def, 'hard')).toHaveLength(2);
});
