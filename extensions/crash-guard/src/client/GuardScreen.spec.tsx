import { afterEach, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { createExtensionTestHost, createTestServer } from '@pterodactyl/sdk/testing';
import { http } from '@pterodactyl/sdk';
import GuardBannerSlot from './GuardBanner';
import GuardScreen from './screens/GuardScreen';
import type { Guard } from './api';

// Other extensions' screens are not registered in the test host, so links are plain anchors that carry their destination.
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

const watching: Guard = {
    state: 'watching',
    enabled: true,
    max_crashes: 3,
    window_minutes: 10,
    defaults: { enabled: false, max_crashes: 3, window_minutes: 10 },
    recent_crashes: 1,
    tripped: null,
    mc_crash: true,
    events: [
        {
            id: 2,
            kind: 'crash',
            status: 'confirmed',
            via: 'uptime-drop',
            uptime_before: 600_000,
            note: null,
            occurred_at: '2026-10-13T10:00:00+00:00',
        },
        {
            id: 1,
            kind: 'cleared',
            status: 'confirmed',
            via: null,
            uptime_before: null,
            note: 'Cleared by a user.',
            occurred_at: '2026-10-13T09:00:00+00:00',
        },
    ],
};

const tripped: Guard = {
    ...watching,
    state: 'tripped',
    recent_crashes: 3,
    tripped: { at: '2026-10-13T10:05:00+00:00', crashes: 3, minutes: 10 },
};

function setup(guard: Guard, options: { permissions?: string[]; tags?: string[] } = {}) {
    vi.spyOn(http, 'get').mockResolvedValue({ data: guard });
    const post = vi.spyOn(http, 'post').mockResolvedValue({ data: watching });
    const put = vi.spyOn(http, 'put').mockResolvedValue({ data: { ...watching, max_crashes: 5 } });

    const server = createTestServer({ identifier: 'abc12345', owner: !options.permissions, permissions: options.permissions ?? ['*'] });
    server.attributes.egg_tags = options.tags ?? ['minecraft'];
    const host = createExtensionTestHost({ extensionId: 'crash-guard', prefix: 'cg', server });

    return { host, server, post, put };
}

it('shows the banner while the server is held off, and clears it and starts the server', async () => {
    const { host, server, post } = setup(tripped);
    render(<GuardBannerSlot data={server} />, { wrapper: host.Wrapper });

    expect(await screen.findByText('Crash Guard stopped this server after 3 crashes in 10 minutes')).toBeTruthy();

    fireEvent.click(screen.getByRole('button', { name: 'Clear and start' }));
    await waitFor(() => expect(post).toHaveBeenCalledWith('/api/client/servers/abc12345/extensions/crash-guard/clear', { start: true }));
    await waitFor(() => expect(screen.queryByText(/Crash Guard stopped this server/)).toBeNull());

    host.dispose();
});

it('clears without starting', async () => {
    const { host, server, post } = setup(tripped);
    render(<GuardBannerSlot data={server} />, { wrapper: host.Wrapper });

    fireEvent.click(await screen.findByRole('button', { name: 'Clear' }));
    await waitFor(() => expect(post).toHaveBeenCalledWith('/api/client/servers/abc12345/extensions/crash-guard/clear', { start: false }));

    host.dispose();
});

it('offers only Clear without the start permission', async () => {
    const { host, server } = setup(tripped, { permissions: ['ext.crash-guard.view', 'ext.crash-guard.manage'] });
    render(<GuardBannerSlot data={server} />, { wrapper: host.Wrapper });

    expect(await screen.findByRole('button', { name: 'Clear' })).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Clear and start' })).toBeNull();

    host.dispose();
});

it('has no buttons for someone who may only look', async () => {
    const { host, server } = setup(tripped, { permissions: ['ext.crash-guard.view'] });
    render(<GuardBannerSlot data={server} />, { wrapper: host.Wrapper });

    expect(await screen.findByText(/Crash Guard stopped this server/)).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Clear' })).toBeNull();

    host.dispose();
});

it('stays hidden when nothing is wrong or the user may not view it', async () => {
    const quiet = setup(watching);
    const { container } = render(<GuardBannerSlot data={quiet.server} />, { wrapper: quiet.host.Wrapper });
    await waitFor(() => expect(quiet.host.queryClient.isFetching()).toBe(0));
    expect(container.textContent).toBe('');
    quiet.host.dispose();
    cleanup();

    const hidden = setup(tripped, { permissions: ['control.start'] });
    const result = render(<GuardBannerSlot data={hidden.server} />, { wrapper: hidden.host.Wrapper });
    expect(result.container.textContent).toBe('');
    hidden.host.dispose();
});

it('saves the limits and links Minecraft crashes to the crash page', async () => {
    const { host, put } = setup(watching);
    render(<GuardScreen />, { wrapper: host.Wrapper });

    expect(await screen.findByText('Restarted by itself after 10 min')).toBeTruthy();
    expect(JSON.parse(screen.getByText('Open Minecraft Crash').getAttribute('data-destination') ?? '{}')).toEqual({
        extension: 'mc-crash',
        screen: 'crashes',
        params: { id: 'abc12345' },
    });
    expect(screen.getByText('Cleared by a user.')).toBeTruthy();

    fireEvent.change(screen.getByLabelText('Crashes'), { target: { value: '5' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));

    await waitFor(() =>
        expect(put).toHaveBeenCalledWith('/api/client/servers/abc12345/extensions/crash-guard/', {
            enabled: true,
            max_crashes: 5,
            window_minutes: 10,
        }),
    );

    host.dispose();
});

it('keeps the numbers inside the limits when saving', async () => {
    const { host, put } = setup(watching);
    render(<GuardScreen />, { wrapper: host.Wrapper });

    fireEvent.change(await screen.findByLabelText('Crashes'), { target: { value: '500' } });
    fireEvent.change(screen.getByLabelText('Within (minutes)'), { target: { value: 'x' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));

    await waitFor(() => expect(put).toHaveBeenCalled());
    expect(put.mock.calls[0][1]).toEqual({ enabled: true, max_crashes: 20, window_minutes: 10 });

    host.dispose();
});

it('shows no crash page link on other games or without the extension', async () => {
    const other = setup(watching, { tags: ['ark'] });
    render(<GuardScreen />, { wrapper: other.host.Wrapper });
    expect(await screen.findByText('Restarted by itself after 10 min')).toBeTruthy();
    expect(screen.queryByText('Open Minecraft Crash')).toBeNull();
    other.host.dispose();
    cleanup();

    const missing = setup({ ...watching, mc_crash: false });
    render(<GuardScreen />, { wrapper: missing.host.Wrapper });
    expect(await screen.findByText('Restarted by itself after 10 min')).toBeTruthy();
    expect(screen.queryByText('Open Minecraft Crash')).toBeNull();
    missing.host.dispose();
});

it('is read-only without the manage permission', async () => {
    const { host } = setup(watching, { permissions: ['ext.crash-guard.view'] });
    render(<GuardScreen />, { wrapper: host.Wrapper });

    const crashes = (await screen.findByLabelText('Crashes')) as HTMLInputElement;
    expect(crashes.disabled).toBe(true);
    expect(screen.queryByRole('button', { name: 'Save' })).toBeNull();

    host.dispose();
});
