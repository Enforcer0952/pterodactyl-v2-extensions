import { http } from '@pterodactyl/sdk';

export type ServerState = 'running' | 'offline' | 'starting' | 'stopping' | string;

const base = (server: string) => `/api/client/servers/${server}/extensions/ark-tools`;

export const keys = {
    mods: (server: string) => ['ark-tools', server, 'mods'] as const,
    online: (server: string) => ['ark-tools', server, 'online'] as const,
    lists: (server: string) => ['ark-tools', server, 'lists'] as const,
    restart: (server: string) => ['ark-tools', server, 'restart'] as const,
};

/* Mods */

export type ModStatus = 'up_to_date' | 'update_available' | 'not_downloaded' | 'downloading';

export interface WorkshopItem {
    id: string;
    found: boolean;
    title: string | null;
    preview_url: string | null;
    updated_at: number | null;
    size: number | null;
    app_id: number | null;
    url: string;
}

export interface Mod extends WorkshopItem {
    listed: boolean;
    downloaded: boolean;
    installed_at: number | null;
    status: ModStatus;
    wrong_game: boolean;
}

export interface ModsResponse {
    state: ServerState;
    raw: string | null;
    editable: boolean;
    can_delete_files: boolean;
    mods: Mod[];
    unlisted: Mod[];
    max: number;
}

export const getMods = async (server: string) => (await http.get<ModsResponse>(`${base(server)}/mods`)).data;

export const saveMods = async (server: string, ids: string[]) => (await http.put<ModsResponse>(`${base(server)}/mods`, { ids })).data;

export const lookupMods = async (server: string, ids: string[]) =>
    (await http.post<{ items: WorkshopItem[] }>(`${base(server)}/mods/lookup`, { ids })).data.items;

export const deleteModFiles = async (server: string, id: string) =>
    (await http.delete<ModsResponse>(`${base(server)}/mods/${id}/files`)).data;

/* Players */

export interface OnlinePlayer {
    name: string;
    steam_id: string;
}

export interface OnlineResponse {
    state: ServerState;
    online: OnlinePlayer[] | null;
}

export interface KnownPlayer {
    steam_id: string;
    name: string;
    last_seen: string;
}

export interface Lists {
    state: ServerState;
    whitelist_enabled: boolean | null;
    whitelist_editable: boolean;
    whitelist: string[];
    bans: string[];
    admins: string[];
    known: KnownPlayer[];
}

export type PlayerAction = 'kick' | 'ban' | 'unban' | 'whitelist-add' | 'whitelist-remove' | 'admin-add' | 'admin-remove';

export const getOnline = async (server: string) => (await http.get<OnlineResponse>(`${base(server)}/players/online`)).data;

export const getLists = async (server: string) => (await http.get<Lists>(`${base(server)}/players/lists`)).data;

export const runPlayerAction = async (server: string, action: PlayerAction, steamId: string) =>
    (await http.post<Lists>(`${base(server)}/players/action`, { action, steam_id: steamId })).data;

export const setWhitelistMode = async (server: string, enabled: boolean) =>
    (await http.put<Lists>(`${base(server)}/players/whitelist-mode`, { enabled })).data;

/* Admin */

export type AdminAction = 'save' | 'wild-dinos' | 'broadcast' | 'chat';

export const runAdminCommand = async (server: string, action: AdminAction, message?: string) =>
    (await http.post<{ sent: string }>(`${base(server)}/admin/command`, { action, message })).data;

export interface RestartStatus {
    running: { kind: 'now' | 'daily'; restart_at: string | null } | null;
    daily: { enabled: boolean; time: string; minutes: number; next_run_at: string | null } | null;
    timezone: string;
    minutes: number[];
}

export const getRestart = async (server: string) => (await http.get<RestartStatus>(`${base(server)}/restart`)).data;

export const startRestart = async (server: string, minutes: number) =>
    (await http.post<RestartStatus>(`${base(server)}/restart`, { minutes })).data;

export const cancelRestart = async (server: string) => (await http.delete<RestartStatus>(`${base(server)}/restart`)).data;

export const saveDailyRestart = async (server: string, input: { enabled: boolean; time: string; minutes: number }) =>
    (await http.put<RestartStatus>(`${base(server)}/restart/daily`, input)).data;

/** Workshop ids from text: bare ids, comma lists or Workshop links. */
export function parseModInput(text: string): string[] {
    const ids: string[] = [];
    for (const match of text.matchAll(/(?:[?&]id=)?(\d{5,12})/g)) {
        if (!ids.includes(match[1])) ids.push(match[1]);
    }
    return ids;
}

export const STEAM_ID = /^7656119\d{10}$/;
