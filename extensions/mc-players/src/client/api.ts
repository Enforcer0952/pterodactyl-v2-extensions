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

export interface PlayerStats {
    play_seconds: number;
    deaths: number;
    mob_kills: number;
    player_kills: number;
    distance_m: number;
    blocks_mined: number;
    jumps: number;
}

export interface ServerRef {
    id: string;
    name: string;
    uuid: string;
}

/** One player's standing on one server. `state: 'unreachable'` comes with `error` and nothing else. */
export interface PlayerOnServer {
    server: ServerRef;
    state: ServerState | 'unreachable';
    error?: string;
    name?: string;
    uuid?: string | null;
    online_mode?: boolean | null;
    /** null when the server is not running, or the online list is unknown. */
    online?: boolean | null;
    whitelist_enabled?: boolean | null;
    whitelisted?: boolean;
    op?: (ListedPlayer & { level: number }) | null;
    ban?: Ban | null;
    last_seen?: string | null;
    joined?: boolean;
    problems?: Record<string, string>;
    stats?: PlayerStats | null;
}

export type NetworkAction = 'kick' | 'ban' | 'pardon' | 'whitelist-add' | 'whitelist-remove' | 'op' | 'deop';

export interface NetworkPlayer {
    name: string;
    servers: (PlayerOnServer & { can?: Partial<Record<Action, boolean>> })[];
}

export interface NetworkResult {
    server: ServerRef;
    ok: boolean;
    message: string;
}

const network = '/api/client/extensions/mc-players/network';

export const playerKeys = {
    onServer: (server: string, name: string) => ['mc-players', server, 'player', name.toLowerCase()] as const,
    network: (name: string) => ['mc-players', 'network', name.toLowerCase()] as const,
};

export async function getPlayer(server: string, name: string): Promise<PlayerOnServer> {
    const { data } = await http.get<PlayerOnServer>(`${base(server)}/player`, { params: { name } });
    return data;
}

export async function getNetworkPlayer(name: string): Promise<NetworkPlayer> {
    const { data } = await http.get<NetworkPlayer>(`${network}/player`, { params: { name } });
    return data;
}

export async function runNetworkAction(
    action: NetworkAction,
    target: string,
    servers: string[],
    reason?: string,
): Promise<NetworkResult[]> {
    const { data } = await http.post<{ results: NetworkResult[] }>(`${network}/action`, {
        action,
        target,
        servers,
        reason: reason || null,
    });
    return data.results;
}
