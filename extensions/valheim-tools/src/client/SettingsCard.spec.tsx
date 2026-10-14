import { afterEach, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { createExtensionTestHost, createTestServer } from '@pterodactyl/sdk/testing';
import { http } from '@pterodactyl/sdk';
import SettingsSlot from './SettingsCard';

afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
});

const settings = {
    name: { present: true, editable: true, value: 'My Server' },
    password: { present: true, editable: true, value: 'secret' },
    public: { present: true, editable: true, value: '1' },
    crossplay: { present: true, editable: true, value: '0' },
    preset: { present: false, editable: false, value: null },
    presets: ['normal', 'hard'],
    world: 'Dedicated',
    warning: null,
    limits: { name: 60, password_min: 5, password_max: 20 },
    can_update: true,
};

function setup() {
    vi.spyOn(http, 'get').mockResolvedValue({ data: settings });
    const put = vi.spyOn(http, 'put').mockResolvedValue({ data: settings });
    const server = createTestServer({ identifier: 'abc12345', permissions: ['*'] });
    server.attributes.egg_tags = ['valheim'];
    const host = createExtensionTestHost({ extensionId: 'valheim-tools', prefix: 'vh', server });
    render(<SettingsSlot data={server} />, { wrapper: host.Wrapper });
    return { host, put };
}

it('masks the password and saves only what changed', async () => {
    const { host, put } = setup();

    const password = (await screen.findByLabelText('Password')) as HTMLInputElement;
    expect(password.type).toBe('password');

    fireEvent.change(await screen.findByLabelText('Server name'), { target: { value: 'Valhalla' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save settings' }));

    await waitFor(() =>
        expect(put).toHaveBeenCalledWith('/api/client/servers/abc12345/extensions/valheim-tools/settings', { name: 'Valhalla' }),
    );

    host.dispose();
});

it('refuses a password that is too short or inside the world name', async () => {
    const { host, put } = setup();

    fireEvent.change(await screen.findByLabelText('Password'), { target: { value: 'abc' } });
    expect(await screen.findByText(/Use 5 to 20 characters/)).toBeTruthy();
    expect((screen.getByRole('button', { name: 'Save settings' }) as HTMLButtonElement).disabled).toBe(true);

    fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'Dedic' } });
    expect(await screen.findByText(/part of the world name/)).toBeTruthy();
    expect(put).not.toHaveBeenCalled();

    host.dispose();
});
