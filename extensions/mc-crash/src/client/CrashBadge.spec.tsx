import { afterEach, expect, it, vi } from 'vitest';
import { cleanup, render, screen, waitFor } from '@testing-library/react';
import { createExtensionTestHost, createTestServer } from '@pterodactyl/sdk/testing';
import { http } from '@pterodactyl/sdk';
import CrashBadgeSlot from './CrashBadge';

// Screens are not registered in the test host, so links are plain anchors that carry their destination.
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

function setup(count: number, tags: string[], permissions: string[] = ['*']) {
    const get = vi.spyOn(http, 'get').mockResolvedValue({ data: { new: count, latest: '2026-10-12T21:30:14', viewed_at: null } });
    const server = createTestServer({ identifier: 'abc12345', permissions, owner: permissions.includes('*') });
    server.attributes.egg_tags = tags;
    server.attributes.egg_features = null;
    const host = createExtensionTestHost({ extensionId: 'mc-crash', prefix: 'mccr', server });
    render(<CrashBadgeSlot data={server} />, { wrapper: host.Wrapper });

    return { host, get };
}

it('shows a notice while there is a crash newer than the last visit', async () => {
    const { host, get } = setup(1, ['minecraft']);

    expect(await screen.findByText(/The server crashed/)).toBeTruthy();
    expect(screen.getByText(/12 Oct 2026, 21:30/)).toBeTruthy();
    expect(JSON.parse(screen.getByText('See what happened').getAttribute('data-destination') ?? '')).toEqual({
        extension: 'mc-crash',
        screen: 'crashes',
        params: { id: 'abc12345' },
    });
    expect(get).toHaveBeenCalledWith('/api/client/servers/abc12345/extensions/mc-crash/status');

    host.dispose();
});

it('counts several new crashes', async () => {
    const { host } = setup(3, ['minecraft']);

    expect(await screen.findByText(/3 new crashes/)).toBeTruthy();

    host.dispose();
});

it('shows nothing when there is no new crash', async () => {
    const { host, get } = setup(0, ['minecraft']);

    await waitFor(() => expect(get).toHaveBeenCalled());
    expect(screen.queryByRole('status')).toBeNull();

    host.dispose();
});

it('stays hidden on Bedrock, non-Minecraft servers and without permission', async () => {
    const bedrock = setup(2, ['minecraft', 'bedrock']);
    expect(screen.queryByRole('status')).toBeNull();
    expect(bedrock.get).not.toHaveBeenCalled();
    bedrock.host.dispose();
    cleanup();

    const ark = setup(2, ['ark']);
    expect(ark.get).not.toHaveBeenCalled();
    ark.host.dispose();
    cleanup();

    const limited = setup(2, ['minecraft'], ['control.console']);
    expect(limited.get).not.toHaveBeenCalled();
    limited.host.dispose();
});
