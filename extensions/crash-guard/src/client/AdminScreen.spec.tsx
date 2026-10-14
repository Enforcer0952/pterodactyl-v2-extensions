import { afterEach, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { createExtensionTestHost } from '@pterodactyl/sdk/testing';
import { http } from '@pterodactyl/sdk';
import AdminScreen from './screens/AdminScreen';
import type { AdminList } from './api';

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

const list: AdminList = {
    data: [
        {
            id: 'abc12345',
            name: 'Survival',
            node: 'node-1',
            status: 'tripped',
            max_crashes: 3,
            window_minutes: 10,
            last_state: 'offline',
            last_seen_at: '2026-10-13T10:06:00+00:00',
            tripped_at: '2026-10-13T10:05:00+00:00',
            trip_crashes: 3,
            trip_minutes: 10,
            crashes_24h: 3,
            last_crash_at: '2026-10-13T10:04:00+00:00',
            recent: [{ kind: 'trip', note: '3 crashes in 10 minutes.', occurred_at: '2026-10-13T10:05:00+00:00' }],
        },
        {
            id: 'def67890',
            name: 'Creative',
            node: 'node-2',
            status: 'watching',
            max_crashes: 3,
            window_minutes: 10,
            last_state: 'running',
            last_seen_at: '2026-10-13T10:06:00+00:00',
            tripped_at: null,
            trip_crashes: null,
            trip_minutes: null,
            crashes_24h: 0,
            last_crash_at: null,
            recent: [],
        },
    ],
    meta: { page: 1, per_page: 50, total: 2, guarded: 2, tripped: 1, default_enabled: false },
};

it('lists guarded servers with their state', async () => {
    const get = vi.spyOn(http, 'get').mockResolvedValue({ data: list });
    const host = createExtensionTestHost({ extensionId: 'crash-guard', prefix: 'cg', path: '/panel/crash-guard-overview' });
    render(<AdminScreen />, { wrapper: host.Wrapper });

    expect(await screen.findByText('Survival')).toBeTruthy();
    expect(screen.getByText('Creative')).toBeTruthy();
    expect(screen.getByText('Stopped')).toBeTruthy();
    expect(screen.getByText('Watching')).toBeTruthy();
    expect(screen.getByText('2 guarded servers, 1 stopped by Crash Guard.', { exact: false })).toBeTruthy();
    expect(get).toHaveBeenCalledWith('/api/admin/extensions/crash-guard/servers', { params: { page: 1 } });

    host.dispose();
});

it('searches by name', async () => {
    const get = vi.spyOn(http, 'get').mockResolvedValue({ data: list });
    const host = createExtensionTestHost({ extensionId: 'crash-guard', prefix: 'cg', path: '/panel/crash-guard-overview' });
    render(<AdminScreen />, { wrapper: host.Wrapper });

    fireEvent.change(await screen.findByLabelText('Search servers'), { target: { value: ' surv ' } });
    await waitFor(() =>
        expect(get).toHaveBeenLastCalledWith('/api/admin/extensions/crash-guard/servers', { params: { page: 1, search: 'surv' } }),
    );

    host.dispose();
});
