import { afterEach, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { createExtensionTestHost, createTestServer } from '@pterodactyl/sdk/testing';
import { http } from '@pterodactyl/sdk';
import SettingsScreen from './SettingsScreen';
import schema from '../../../resources/settings-schema.json';

afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
});

const base = '/api/client/servers/abc12345/extensions/palworld-tools/settings';

function response(state: string, overrides: Record<string, unknown> = {}) {
    const values: Record<string, string> = { ServerName: 'Pals', ExpRate: '1.000000', ServerPlayerMaxNum: '32', RESTAPIEnabled: 'False' };
    return {
        state,
        file: {
            path: 'Pal/Saved/Config/LinuxServer/PalWorldSettings.ini',
            exists: true,
            from_template: false,
            raw: 'OptionSettings=(ExpRate=1.000000)',
            hash: 'a'.repeat(40),
        },
        fields: schema.settings.map((setting) => ({
            key: setting.key,
            value: setting.secret ? null : (values[setting.key] ?? null),
            set: setting.secret ? setting.key === 'AdminPassword' : null,
            lock:
                setting.key === 'ServerName'
                    ? { kind: 'variable', env: 'SERVER_NAME', editable: true }
                    : setting.key === 'PublicPort'
                      ? { kind: 'allocation', env: 'SERVER_PORT' }
                      : null,
        })),
        issues: [],
        apply: null,
        parser: true,
        schema: { groups: schema.groups, settings: schema.settings, presets: schema.presets },
        can: { secrets: true, control: true, start: true, write: true },
        ...overrides,
    };
}

function setup(state = 'offline', overrides: Record<string, unknown> = {}) {
    const get = vi.spyOn(http, 'get').mockResolvedValue({ data: response(state, overrides) });
    const put = vi.spyOn(http, 'put').mockResolvedValue({ data: { ...response(state, overrides), result: 'saved' } });
    const server = createTestServer({ identifier: 'abc12345', permissions: ['*'] });
    server.attributes.egg_tags = ['steamcmd'];
    const host = createExtensionTestHost({ extensionId: 'palworld-tools', prefix: 'pal', server });
    render(<SettingsScreen />, { wrapper: host.Wrapper });
    return { host, get, put };
}

it('shows the groups, values and the startup variable notes', async () => {
    const { host } = setup();

    expect(await screen.findByText('Rates')).toBeTruthy();
    expect(screen.getByText('Server')).toBeTruthy();
    expect((screen.getByLabelText('Experience') as HTMLInputElement).value).toBe('1.000000');
    expect(screen.getByText(/Also changes the SERVER_NAME startup variable/)).toBeTruthy();
    expect(screen.getByText(/Comes from the server port/)).toBeTruthy();
    expect((screen.getByLabelText('Public port') as HTMLInputElement).disabled).toBe(true);
    expect((screen.getByLabelText('Admin password') as HTMLInputElement).placeholder).toBe('Set. Type to change it.');

    host.dispose();
});

it('saves a changed value with the file hash', async () => {
    const { host, put } = setup();

    fireEvent.change(await screen.findByLabelText('Experience'), { target: { value: '5' } });
    expect(await screen.findByText('1 unsaved change')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));

    await waitFor(() =>
        expect(put).toHaveBeenCalledWith(base, {
            hash: 'a'.repeat(40),
            edits: [{ key: 'ExpRate', value: '5' }],
            restart: false,
            start: false,
        }),
    );

    host.dispose();
});

it('blocks invalid values', async () => {
    const { host, put } = setup();

    fireEvent.change(await screen.findByLabelText('Player slots'), { target: { value: '99' } });
    expect(screen.getByText('At most 32.')).toBeTruthy();
    expect((screen.getByRole('button', { name: 'Save' }) as HTMLButtonElement).disabled).toBe(true);
    expect(put).not.toHaveBeenCalled();

    host.dispose();
});

it('offers save and restart while the server runs', async () => {
    const { host, put } = setup('running');

    fireEvent.change(await screen.findByLabelText('Experience'), { target: { value: '2' } });
    fireEvent.click(await screen.findByRole('button', { name: 'Save and restart' }));
    await waitFor(() => expect(put).toHaveBeenCalledWith(base, expect.objectContaining({ restart: true, start: false })));

    host.dispose();
});

it('applies a preset after showing what changes', async () => {
    const { host, put } = setup();

    await screen.findByText('Rates');
    const trigger = screen.getByPlaceholderText('Apply a preset…');
    fireEvent.mouseDown(trigger);
    fireEvent.click(trigger);
    fireEvent.click(await screen.findByRole('option', { name: '5x rates' }));
    expect(await screen.findByText('Apply the 5x rates preset?')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: /^Apply \d+ changes?$/ }));

    expect(await screen.findByText(/unsaved changes/)).toBeTruthy();
    expect(((await screen.findByLabelText(/^Experience/)) as HTMLInputElement).value).toBe('5.000000');
    expect(put).not.toHaveBeenCalled();

    host.dispose();
});

it('hides passwords from people who may not see them and makes the form read-only without file access', async () => {
    const withoutSecrets = response('offline', { can: { secrets: false, control: false, start: false, write: false } });
    withoutSecrets.fields = withoutSecrets.fields.filter((field) => !['AdminPassword', 'ServerPassword'].includes(field.key));
    withoutSecrets.schema.settings = withoutSecrets.schema.settings.filter((setting) => !setting.secret);
    vi.spyOn(http, 'get').mockResolvedValue({ data: withoutSecrets });
    const server = createTestServer({ identifier: 'abc12345', permissions: ['*'] });
    const host = createExtensionTestHost({ extensionId: 'palworld-tools', prefix: 'pal', server });
    render(<SettingsScreen />, { wrapper: host.Wrapper });

    expect(await screen.findByText('Rates')).toBeTruthy();
    expect(screen.queryByLabelText('Admin password')).toBeNull();
    expect((screen.getByLabelText('Experience') as HTMLInputElement).disabled).toBe(true);
    expect(screen.getByText(/You can look at the settings but not change them/)).toBeTruthy();

    host.dispose();
});

it('shows a change that waits for the server to stop', async () => {
    const { host } = setup('running', { apply: { status: 'waiting', since: '2026-10-14T10:00:00+00:00', error: null } });

    expect(await screen.findByText('Waiting to apply settings')).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Cancel' })).toBeTruthy();

    host.dispose();
});
