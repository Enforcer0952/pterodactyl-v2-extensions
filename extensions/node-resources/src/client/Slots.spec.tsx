import { afterEach, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { createExtensionTestHost, createTestServer } from '@pterodactyl/sdk/testing';
import { http } from '@pterodactyl/sdk';
import AlertBanner from './AlertBanner';
import AllocationSlot, { readField } from './AllocationHelper';
import HistorySlot from './HistoryCard';
import { history, report } from './fixtures';

// Our own screens are not registered in the test host, so links are plain anchors.
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
    document.querySelectorAll('[data-fixture]').forEach((el) => el.remove());
});

it('shows alerts in the banner and hides it when all is well', async () => {
    const get = vi.spyOn(http, 'get').mockResolvedValue({
        data: {
            collector: report().collector,
            warnings: [{ id: 'disk-high', level: 'danger', text: 'The disk is 95% full.', node: { id: 3, name: 'Node One' } }],
        },
    });
    const host = createExtensionTestHost({ extensionId: 'node-resources', prefix: 'nr' });
    const view = render(<AlertBanner />, { wrapper: host.Wrapper });
    expect(await screen.findByText('The disk is 95% full.')).toBeTruthy();
    view.unmount();

    host.dispose();

    get.mockResolvedValue({ data: { collector: report().collector, warnings: [] } });
    const other = createExtensionTestHost({ extensionId: 'node-resources', prefix: 'nr' });
    const quiet = render(<AlertBanner />, { wrapper: other.Wrapper });
    await waitFor(() => expect(get).toHaveBeenCalledTimes(2));
    expect(quiet.container.textContent).toBe('');
    other.dispose();
});

it('warns when typed limits do not fit', async () => {
    document.body.insertAdjacentHTML('beforeend', '<input data-fixture id="memory" value="20000"><input data-fixture id="disk" value="">');
    vi.spyOn(http, 'get').mockResolvedValue({
        data: { server: { id: 7, name: 'Survival', memory: 0, disk: 0, cpu: 0 }, report: report() },
    });
    const host = createExtensionTestHost({ extensionId: 'node-resources', prefix: 'nr' });
    render(<AllocationSlot data={{ params: { id: '7' } }} />, { wrapper: host.Wrapper });

    expect(await screen.findByRole('alert')).toBeTruthy();
    expect(screen.getByRole('alert').textContent).toContain('free on the host');
    host.dispose();
});

it('lets a node be picked on the create form', async () => {
    vi.spyOn(http, 'get').mockImplementation(async (url: string) => ({
        data: url.endsWith('/nodes') ? { data: [{ id: 3, name: 'Node One' }] } : report(),
    }));
    const host = createExtensionTestHost({ extensionId: 'node-resources', prefix: 'nr' });
    render(<AllocationSlot data={{ params: {} }} />, { wrapper: host.Wrapper });

    await screen.findByRole('option', { name: 'Node One' });
    fireEvent.change(screen.getByRole('combobox'), { target: { value: '3' } });
    expect((await screen.findAllByText(/left to give out/)).length).toBe(2);
    host.dispose();
});

it('reads numbers from the form', () => {
    document.body.insertAdjacentHTML(
        'beforeend',
        '<input data-fixture id="a" value=" 512 "><input data-fixture id="b" value=""><input data-fixture id="c" value="x">',
    );
    expect(readField('a')).toBe(512);
    expect(readField('b')).toBeNull();
    expect(readField('c')).toBeNull();
    expect(readField('missing')).toBeNull();
});

it('hides the history card without the permission', () => {
    vi.spyOn(http, 'get').mockResolvedValue({ data: {} });
    const server = createTestServer({ identifier: 'abc12345', owner: false, permissions: ['control.console'] });
    const host = createExtensionTestHost({ extensionId: 'node-resources', prefix: 'nr', server });
    const view = render(<HistorySlot data={server} />, { wrapper: host.Wrapper });
    expect(view.container.textContent).toBe('');
    expect(http.get).not.toHaveBeenCalled();
    host.dispose();
});

it('shows the history card with the permission and loads it when opened', async () => {
    const get = vi.spyOn(http, 'get').mockResolvedValue({
        data: {
            ...history,
            points: history.points.map((p) => ({ t: p.t, cpu: 20, mem: p.mem, disk: p.disk })),
            peaks: { cpu: 80, mem: 5e9, disk: 4e11 },
            limits: { memory: 0, disk: 0, cpu: 0 },
            collector: { last_run_at: '2026-10-14T10:00:00+00:00' },
        },
    });
    const server = createTestServer({ identifier: 'abc12345', owner: false, permissions: ['ext.node-resources.history'] });
    const host = createExtensionTestHost({ extensionId: 'node-resources', prefix: 'nr', server });
    render(<HistorySlot data={server} />, { wrapper: host.Wrapper });

    expect(get).not.toHaveBeenCalled();
    fireEvent.click(await screen.findByRole('button', { name: 'Show history' }));
    expect(await screen.findByText('Peak 80%')).toBeTruthy();
    expect(get).toHaveBeenCalledWith('/api/client/servers/abc12345/extensions/node-resources/history', { params: { range: '24h' } });
    host.dispose();
});
