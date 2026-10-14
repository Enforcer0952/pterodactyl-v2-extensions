import { afterEach, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { createExtensionTestHost } from '@pterodactyl/sdk/testing';
import { http } from '@pterodactyl/sdk';
import NodeView from './NodeView';
import { history, report } from './fixtures';

afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
});

function setup(node = report()) {
    vi.spyOn(http, 'get').mockImplementation(async (url: string) => ({ data: url.endsWith('/history') ? history : node }));
    const post = vi.spyOn(http, 'post').mockResolvedValue({ data: { applied: { memory: 4096 } } });
    const host = createExtensionTestHost({ extensionId: 'node-resources', prefix: 'nr' });
    render(<NodeView nodeId={3} />, { wrapper: host.Wrapper });
    return { host, post };
}

it('shows allocated, really used and free for memory and disk', async () => {
    const { host } = setup();

    expect(await screen.findByText('The disk is 90% full. 50 GB left.')).toBeTruthy();
    expect(screen.getAllByText('Allocated').length).toBe(3);
    expect(screen.getAllByText('Really used').length).toBe(3);
    expect(screen.getAllByText('Free on host').length).toBe(2);
    expect(screen.getAllByText('50 GB').length).toBeGreaterThan(0);
    expect(screen.getByText(/Collector last ran at/)).toBeTruthy();
    expect(screen.getByText('Probe running')).toBeTruthy();

    host.dispose();
});

it('offers to set up the probe when there is none', async () => {
    const none = report({
        probe: { state: 'none', server: null, last_at: null },
        disk: { ...report().disk, host_total: null, host_used: null, host_free: null },
    });
    const { host, post } = setup(none);
    post.mockResolvedValue({ data: none });

    expect(await screen.findByText('Set up the probe to see how much disk is really free.')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Set up probe' }));
    await waitFor(() => expect(post).toHaveBeenCalledWith('/api/admin/extensions/node-resources/nodes/3/probe'));

    host.dispose();
});

it('applies a suggested limit after confirming', async () => {
    const { host, post } = setup();

    expect(await screen.findByText('Survival')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Apply' }));
    expect(await screen.findByText(/Survival gets/)).toBeTruthy();
    expect(post).not.toHaveBeenCalled();

    fireEvent.click(screen.getAllByRole('button', { name: 'Apply' }).at(-1) as HTMLElement);
    await waitFor(() => expect(post).toHaveBeenCalledWith('/api/admin/extensions/node-resources/servers/7/limits', { fields: ['memory'] }));

    host.dispose();
});

it('says when the collector has not run', async () => {
    const { host } = setup(
        report({ collector: { last_run_at: null, age: null, stale: true, nodes_ok: 0, nodes_failed: 0, servers: 0, seconds: 0 } }),
    );

    expect(await screen.findByText(/collector has not run yet/)).toBeTruthy();

    host.dispose();
});
