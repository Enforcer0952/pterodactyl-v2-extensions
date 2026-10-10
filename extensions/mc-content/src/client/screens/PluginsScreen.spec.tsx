import { afterEach, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { createExtensionTestHost, createTestServer } from '@pterodactyl/sdk/testing';
import { http } from '@pterodactyl/sdk';
import PluginsScreen from './PluginsScreen';

afterEach(() => vi.restoreAllMocks());

it('shows Modrinth results for the detected loader and version', async () => {
    const get = vi.spyOn(http, 'get').mockImplementation(async (url: string) => {
        if (url.endsWith('/plugins/context')) {
            return {
                data: {
                    kind: 'plugins',
                    directory: '/plugins',
                    loaders: ['paper', 'purpur', 'folia', 'spigot', 'bukkit', 'sponge'],
                    detected_loader: 'paper',
                    detected_game_version: '1.21.4',
                    game_versions: ['1.21.4', '1.21.1'],
                    egg: 'Paper',
                },
            };
        }

        return {
            data: {
                hits: [
                    {
                        project_id: 'hXiIvTyT',
                        slug: 'essentialsx',
                        title: 'EssentialsX',
                        description: 'The essential plugin suite.',
                        author: 'mdcfe',
                        downloads: 862897,
                        icon_url: null,
                        categories: ['paper'],
                        date_modified: null,
                        installed: null,
                    },
                    {
                        project_id: 'Vebnzrzj',
                        slug: 'luckperms',
                        title: 'LuckPerms',
                        description: 'A permissions plugin.',
                        author: 'Luck',
                        downloads: 5000,
                        icon_url: null,
                        categories: ['paper'],
                        date_modified: null,
                        installed: { filename: 'LuckPerms-Bukkit-5.4.145.jar', version_id: 'cfNN7sys', version_number: 'v5.4.145-bukkit' },
                    },
                ],
                total: 2,
                offset: 0,
                limit: 20,
            },
        };
    });

    const host = createExtensionTestHost({
        extensionId: 'mc-content',
        prefix: 'mcc',
        server: createTestServer({ identifier: 'abc12345', permissions: ['*'] }),
    });

    render(<PluginsScreen />, { wrapper: host.Wrapper });

    expect(await screen.findByText('EssentialsX')).toBeTruthy();
    expect(screen.getByText('862.9k downloads')).toBeTruthy();
    expect(screen.getAllByRole('button', { name: 'Install' })).toHaveLength(1);
    expect(screen.getByText('Installed v5.4.145-bukkit')).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Change version' })).toBeTruthy();

    const searchCall = get.mock.calls.find(([url]) => String(url).endsWith('/plugins/search'));
    expect(searchCall?.[1]).toMatchObject({ params: { loader: 'paper', game_version: '1.21.4', sort: 'relevance', page: 1 } });

    host.dispose();
});
