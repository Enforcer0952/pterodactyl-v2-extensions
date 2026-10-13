import { afterEach, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { createExtensionTestHost, createTestServer } from '@pterodactyl/sdk/testing';
import { http } from '@pterodactyl/sdk';
import AdminSlot from './AdminCard';

afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
});

function setup(permissions: string[]) {
    const post = vi.spyOn(http, 'post').mockResolvedValue({ data: { sent: 'ok' } });
    const server = createTestServer({ identifier: 'abc12345', owner: false, permissions });
    server.attributes.egg_tags = ['terraria'];
    const host = createExtensionTestHost({ extensionId: 'terraria-tools', prefix: 'terra', server });
    render(<AdminSlot data={server} />, { wrapper: host.Wrapper });
    return { host, post };
}

const url = '/api/client/servers/abc12345/extensions/terraria-tools/admin/command';

it('runs the one-click commands', async () => {
    const { host, post } = setup(['ext.terraria-tools.admin', 'control.console']);

    fireEvent.click(await screen.findByRole('button', { name: 'Save world' }));
    await waitFor(() => expect(post).toHaveBeenCalledWith(url, { action: 'save', message: undefined }));

    fireEvent.click(screen.getByRole('button', { name: 'Midnight' }));
    await waitFor(() => expect(post).toHaveBeenCalledWith(url, { action: 'midnight', message: undefined }));

    host.dispose();
});

it('sends a cleaned single-line message', async () => {
    const { host, post } = setup(['ext.terraria-tools.admin', 'control.console']);

    fireEvent.change(await screen.findByLabelText('Message to all players'), { target: { value: 'hello\texit' } });
    fireEvent.click(screen.getByRole('button', { name: 'Say' }));

    await waitFor(() => expect(post).toHaveBeenCalledWith(url, { action: 'say', message: 'hello exit' }));

    host.dispose();
});

it('refuses a message that is too long', async () => {
    const { host, post } = setup(['ext.terraria-tools.admin', 'control.console']);

    fireEvent.change(await screen.findByLabelText('Message to all players'), { target: { value: 'x'.repeat(250) } });
    expect(screen.getByText('A message can be at most 200 characters.')).toBeTruthy();
    expect((screen.getByRole('button', { name: 'Say' }) as HTMLButtonElement).disabled).toBe(true);
    expect(post).not.toHaveBeenCalled();

    host.dispose();
});

it('is hidden without the admin or console permission', async () => {
    const { host } = setup(['ext.terraria-tools.view']);
    await new Promise((resolve) => setTimeout(resolve, 50));
    expect(screen.queryByText('Server admin')).toBeNull();
    host.dispose();
});
