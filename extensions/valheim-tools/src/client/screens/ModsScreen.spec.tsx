import { afterEach, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { createExtensionTestHost, createTestServer } from '@pterodactyl/sdk/testing';
import { http } from '@pterodactyl/sdk';
import ModsScreen from './ModsScreen';

afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
});

const mods = (available = true, state = 'offline') => ({
    available,
    state,
    mods: available
        ? [
              {
                  id: 'plugins/Batera-Doorbell',
                  folder: 'Batera-Doorbell',
                  kind: 'plugins',
                  file: false,
                  name: 'Doorbell',
                  version: '1.0.1',
                  owner: 'Batera',
                  package: 'Batera-Doorbell',
                  description: null,
                  website: null,
                  size: 0,
                  modified: null,
              },
          ]
        : [],
});

const search = {
    results: [
        {
            namespace: 'RandyKnapp',
            name: 'EpicLoot',
            description: 'Adds loot.',
            downloads: 2306409,
            rating: 350,
            size: 1,
            icon: null,
            updated: null,
            categories: [],
            server_side: true,
            client_side: true,
            deprecated: false,
        },
    ],
    total: 1,
    has_more: false,
};

function setup(available = true, state = 'offline') {
    const get = vi.spyOn(http, 'get').mockImplementation(async (url: string) => {
        if (url.endsWith('/mods/updates')) return { data: { updates: { 'Batera-Doorbell': { latest: '1.1.0', update: true } } } };
        if (url.endsWith('/mods/search')) return { data: search };
        return { data: mods(available, state) };
    });
    const post = vi.spyOn(http, 'post').mockResolvedValue({
        data: {
            ...mods(true),
            result: { installed: [{ package: 'RandyKnapp-EpicLoot', version: '0.14.13', updated: false }], notes: [] },
        },
    });
    const server = createTestServer({ identifier: 'abc12345', permissions: ['*'] });
    server.attributes.egg_tags = ['valheim'];
    const host = createExtensionTestHost({ extensionId: 'valheim-tools', prefix: 'vh', server });
    render(<ModsScreen />, { wrapper: host.Wrapper });
    return { host, get, post };
}

const base = '/api/client/servers/abc12345/extensions/valheim-tools/mods';

it('lists installed mods and offers the update', async () => {
    const { host, post } = setup();

    expect(await screen.findByText('Doorbell')).toBeTruthy();
    fireEvent.click(await screen.findByRole('button', { name: 'Update' }));
    await waitFor(() => expect(post).toHaveBeenCalledWith(`${base}/install`, { package: 'Batera-Doorbell', version: '1.1.0' }));

    host.dispose();
});

it('explains when there is no BepInEx folder', async () => {
    const { host } = setup(false);

    expect(await screen.findByText(/no BepInEx folder/)).toBeTruthy();

    host.dispose();
});

it('searches Thunderstore and installs a result', async () => {
    const { host, post } = setup();

    fireEvent.click(await screen.findByRole('tab', { name: 'Find on Thunderstore' }));
    expect(await screen.findByText('EpicLoot by RandyKnapp')).toBeTruthy();

    const row = screen.getByText('EpicLoot by RandyKnapp').closest('li') as HTMLElement;
    fireEvent.click(Array.from(row.querySelectorAll('button')).find((button) => button.textContent === 'Install') as HTMLElement);
    await waitFor(() => expect(post).toHaveBeenCalledWith(`${base}/install`, { package: 'RandyKnapp-EpicLoot', version: null }));

    host.dispose();
});

it('blocks installs while the server runs', async () => {
    const { host } = setup(true, 'running');

    expect(await screen.findByText(/Stop it to install/)).toBeTruthy();
    fireEvent.click(screen.getByRole('tab', { name: 'Find on Thunderstore' }));
    const button = await screen.findByRole('button', { name: 'Install' });
    expect((button as HTMLButtonElement).disabled).toBe(true);

    host.dispose();
});
