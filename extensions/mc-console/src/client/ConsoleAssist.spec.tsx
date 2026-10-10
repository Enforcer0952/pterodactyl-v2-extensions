import { afterEach, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { createExtensionTestHost, createTestServer } from '@pterodactyl/sdk/testing';
import { http } from '@pterodactyl/sdk';
import ConsoleAssistSlot from './ConsoleAssist';

afterEach(() => {
    cleanup();
    document.body.innerHTML = '';
    vi.restoreAllMocks();
});

function setup(tags = ['minecraft']) {
    // Stand-in for the Panel's console input.
    const wrapper = document.createElement('div');
    wrapper.className = 'relative';
    const input = document.createElement('input');
    input.setAttribute('aria-label', 'Console command input.');
    wrapper.appendChild(input);
    document.body.appendChild(wrapper);

    vi.spyOn(http, 'get').mockResolvedValue({
        data: {
            plugins: [
                {
                    name: 'lp',
                    aliases: ['luckperms'],
                    description: 'Manage permissions.',
                    usage: '/<command> user <user> info',
                    plugin: 'LuckPerms',
                },
            ],
            pending: 0,
            players: ['Steve', 'Alex'],
        },
    });

    const server = createTestServer({ identifier: 'abc12345', permissions: ['*'] });
    server.attributes.egg_tags = tags;
    const host = createExtensionTestHost({ extensionId: 'mc-console', prefix: 'mccon', server });
    render(<ConsoleAssistSlot data={server} />, { wrapper: host.Wrapper });

    const type = (value: string) => {
        input.focus();
        input.value = value;
        fireEvent.input(input);
    };

    return { host, input, type };
}

it('suggests commands and completes with Tab', async () => {
    const { host, input, type } = setup();

    type('gamem');
    expect(await screen.findByRole('option', { name: /gamemode/ })).toBeTruthy();

    fireEvent.keyDown(input, { key: 'Tab' });
    expect(input.value).toBe('gamemode ');

    fireEvent.input(input);
    expect(await screen.findByRole('option', { name: /creative/ })).toBeTruthy();
    expect(screen.getByText(/survival\|creative\|adventure\|spectator/)).toBeTruthy();

    host.dispose();
});

it('offers player names and plugin commands', async () => {
    const { host, input, type } = setup();

    type('ban S');
    expect(await screen.findByRole('option', { name: /^Steve/ })).toBeTruthy();

    type('lp user A');
    expect(await screen.findByRole('option', { name: /^Alex/ })).toBeTruthy();
    expect(screen.getByText('(LuckPerms)')).toBeTruthy();

    fireEvent.keyDown(input, { key: 'Escape' });
    await waitFor(() => expect(screen.queryByRole('option', { name: /^Alex/ })).toBeNull());

    host.dispose();
});

it('moves through suggestions with the arrow keys', async () => {
    const { host, input, type } = setup();

    type('whitelist ');
    await screen.findByRole('option', { name: 'add' });
    fireEvent.keyDown(input, { key: 'ArrowDown' });
    await waitFor(() => expect(screen.getByRole('option', { name: 'remove' }).getAttribute('aria-selected')).toBe('true'));
    fireEvent.keyDown(input, { key: 'Tab' });
    expect(input.value).toBe('whitelist remove ');

    host.dispose();
});

it('does nothing on proxies', () => {
    const { host, type } = setup(['minecraft', 'proxy']);
    type('gam');
    expect(screen.queryByRole('listbox')).toBeNull();
    host.dispose();
});
