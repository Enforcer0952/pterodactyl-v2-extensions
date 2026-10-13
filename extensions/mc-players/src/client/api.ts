import { http } from '@pterodactyl/sdk';

export type ServerState = 'running' | 'offline' | 'starting' | 'stopping' | string;

export interface OnlinePlayers {
    count: number;
    max: number;
    players: string[];
}

export interface OnlineResponse {
    state: ServerState;
    online: OnlinePlayers | null;
}

export interface ListedPlayer {
    name: string;
    uuid: string;
}

export interface Ban extends ListedPlayer {
    reason: string | null;
    source: string | null;
    created: string | null;
    expires: string | null;
}

export interface IpBan {
    ip: string;
    reason: string | null;
    source: string | null;
    created: string | null;
    expires: string | null;
}

export interface Lists {
    state: ServerState;
    whitelist_enabled: boolean | null;
    online_mode: boolean | null;
    whitelist: ListedPlayer[];
    ops: (ListedPlayer & { level: number })[];
    bans: Ban[];
    ip_bans: IpBan[];
    known: (ListedPlayer & { last_seen: string | null })[];
    /** Files that exist but could not be read, with the reason. Their lists show as empty. */
    problems: Record<string, string>;
    /** The server's own answer to a command, when it printed one. */
    reply?: string | null;
}

export type Action =
    | 'kick'
    | 'ban'
    | 'pardon'
    | 'ban-ip'
    | 'pardon-ip'
    | 'whitelist-add'
    | 'whitelist-remove'
    | 'whitelist-on'
    | 'whitelist-off'
    | 'op'
    | 'deop';

const base = (server: string) => `/api/client/servers/${server}/extensions/mc-players`;

export const keys = {
    online: (server: string) => ['mc-players', server, 'online'] as const,
    lists: (server: string) => ['mc-players', server, 'lists'] as const,
};

export async function getOnline(server: string): Promise<OnlineResponse> {
    const { data } = await http.get<OnlineResponse>(`${base(server)}/online`);
    return data;
}

export async function getLists(server: string): Promise<Lists> {
    const { data } = await http.get<Lists>(`${base(server)}/lists`);
    return data;
}

export async function runAction(server: string, action: Action, target?: string, reason?: string): Promise<Lists> {
    const { data } = await http.post<Lists>(`${base(server)}/action`, { action, target: target ?? null, reason: reason || null });
    return data;
}
