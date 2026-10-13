import { afterEach, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { createExtensionTestHost, createTestServer } from '@pterodactyl/sdk/testing';
import { http } from '@pterodactyl/sdk';
import SettingsScreen from './SettingsScreen';

afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
});

const field = (key: string, label: string, kind: string, value: string | null, extra = {}) => ({
    key,
    label,
    kind,
    help: '',
    value,
    set: value !== null && value !== '',
    present: value !== null,
    min: null,
    max: null,
    choices: null,
    ...extra,
});

const settings = (masked: boolean, editable = true) => ({
    state: 'offline',
    exists: true,
    hash: 'a'.repeat(40),
    raw: masked ? 'maxplayers=8\npassword=********\n' : 'maxplayers=8\npassword=secret\n',
    masked,
    editable,
    fields: [
        field('maxplayers', 'Max players', 'int', '8', { min: 1, max: 255 }),
        field('motd', 'Message of the day', 'text', 'Welcome', { max: 200 }),
        field('password', 'Server password', 'secret', masked ? null : 'secret', { max: 100, set: true }),
    ],
});

function setup(data: ReturnType<typeof settings>) {
    vi.spyOn(http, 'get').mockResolvedValue({ data });
    const put = vi.spyOn(http, 'put').mockResolvedValue({ data: { ...data, written: true, variables: { updated: [], skipped: [] } } });
    const server = createTestServer({ identifier: 'abc12345', permissions: ['*'] });
    const host = createExtensionTestHost({ extensionId: 'terraria-tools', prefix: 'terra', server });
    render(<SettingsScreen />, { wrapper: host.Wrapper });
    return { host, put };
}

it('saves only the changed fields with the file hash', async () => {
    const { host, put } = setup(settings(false));

    fireEvent.change(await screen.findByLabelText('Max players'), { target: { value: '16' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));

    await waitFor(() =>
        expect(put).toHaveBeenCalledWith('/api/client/servers/abc12345/extensions/terraria-tools/settings', {
            raw: undefined,
            edits: { maxplayers: '16' },
            hash: 'a'.repeat(40),
        }),
    );

    host.dispose();
});

it('blocks saving an invalid value', async () => {
    const { host, put } = setup(settings(false));

    fireEvent.change(await screen.findByLabelText('Max players'), { target: { value: '999' } });
    expect(screen.getByText('Max players must be a number from 1 to 255.')).toBeTruthy();
    expect((screen.getByRole('button', { name: 'Save' }) as HTMLButtonElement).disabled).toBe(true);
    expect(put).not.toHaveBeenCalled();

    host.dispose();
});

it('hides the password from people who may not read it', async () => {
    const { host } = setup(settings(true, false));

    expect(await screen.findByText(/A password is set/)).toBeTruthy();
    expect(document.body.innerHTML).not.toContain('secret');
    expect((screen.getByLabelText('Server password') as HTMLInputElement).disabled).toBe(true);

    host.dispose();
});

it('shows the password masked until asked', async () => {
    const { host } = setup(settings(false));

    const input = (await screen.findByLabelText('Server password')) as HTMLInputElement;
    expect(input.type).toBe('password');
    fireEvent.click(screen.getByRole('button', { name: 'Show' }));
    expect(input.type).toBe('text');

    host.dispose();
});
