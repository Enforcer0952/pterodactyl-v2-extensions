import { http } from '@pterodactyl/sdk';

export type ServerState = 'running' | 'offline' | 'starting' | 'stopping' | string;

const base = (server: string) => `/api/client/servers/${server}/extensions/ark-tools`;

export const keys = {
    mods: (server: string) => ['ark-tools', server, 'mods'] as const,
    online: (server: string) => ['ark-tools', server, 'online'] as const,
    lists: (server: string) => ['ark-tools', server, 'lists'] as const,
    restart: (server: string) => ['ark-tools', server, 'restart'] as const,
    config: (server: string) => ['ark-tools', server, 'config'] as const,
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
    running: { kind: 'now' | 'daily' | 'apply'; restart_at: string | null } | null;
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

/* Config */

export type ConfigFileName = 'GameUserSettings.ini' | 'Game.ini';

export interface ConfigSetting {
    key: string;
    file: ConfigFileName;
    section: string;
    type: 'float' | 'int' | 'bool' | 'string';
    default: string;
    min?: number;
    max?: number;
    group: string;
    label: string;
    help: string;
}

export interface ConfigPreset {
    id: string;
    label: string;
    description: string;
    values: Record<string, string>;
}

export interface ConfigEdit {
    file: ConfigFileName;
    section: string;
    key: string;
    value: string | null;
}

export interface ConfigIssue {
    file: ConfigFileName;
    section: string;
    key: string;
    line: number;
    kind: 'misplaced' | 'typo' | 'duplicate';
    level: 'warning' | 'info';
    message: string;
    fixes: { label: string; edits: ConfigEdit[] }[];
}

export interface ConfigResponse {
    state: ServerState;
    files: Record<ConfigFileName, { path: string; exists: boolean; raw: string; hash: string }>;
    fields: { key: string; value: string | null; overridden: boolean }[];
    issues: ConfigIssue[];
    apply: { status: 'waiting' | 'writing' | 'failed'; since: string; error: string | null } | null;
    restart: RestartStatus['running'];
    minutes: number[];
    schema: { groups: { id: string; label: string }[]; settings: ConfigSetting[]; presets: ConfigPreset[] };
    result?: 'unchanged' | 'saved' | 'waiting';
}

export interface ConfigSave {
    raw?: Partial<Record<ConfigFileName, string>>;
    edits?: ConfigEdit[];
    hashes: Record<string, string>;
    minutes?: number;
    start?: boolean;
}

export const getConfig = async (server: string) => (await http.get<ConfigResponse>(`${base(server)}/config`)).data;

export const saveConfig = async (server: string, input: ConfigSave) => (await http.put<ConfigResponse>(`${base(server)}/config`, input)).data;

export const cancelConfigApply = async (server: string) => (await http.delete<ConfigResponse>(`${base(server)}/config/apply`)).data;

/** A problem with a value typed into the form, or null when it is fine. */
export function validateSetting(setting: ConfigSetting, value: string): string | null {
    const trimmed = value.trim();
    if (setting.type === 'bool') {
        return /^(true|false)$/i.test(trimmed) ? null : 'Must be True or False.';
    }
    if (setting.type === 'string') {
        return trimmed.length > 500 ? 'Too long.' : null;
    }
    if (trimmed === '' || !Number.isFinite(Number(trimmed))) {
        return setting.type === 'int' ? 'Enter a whole number.' : 'Enter a number.';
    }
    if (setting.type === 'int' && !/^-?\d+$/.test(trimmed)) {
        return 'Enter a whole number.';
    }
    if (setting.min !== undefined && Number(trimmed) < setting.min) {
        return `At least ${setting.min}.`;
    }
    if (setting.max !== undefined && Number(trimmed) > setting.max) {
        return `At most ${setting.max}.`;
    }
    return null;
}

/** Edits keyed by file, section and key, so a later edit to the same setting replaces an earlier one. */
export const editKey = (edit: Pick<ConfigEdit, 'file' | 'section' | 'key'>) =>
    `${edit.file}|${edit.section.toLowerCase()}|${edit.key.toLowerCase()}`;

/** Whether two setting values mean the same thing: 2 and 2.000000, true and True. */
export function sameValue(a: string | null | undefined, b: string | null | undefined): boolean {
    if (a === null || a === undefined || b === null || b === undefined) return (a ?? null) === (b ?? null);
    const x = a.trim();
    const y = b.trim();
    if (x !== '' && y !== '' && Number.isFinite(Number(x)) && Number.isFinite(Number(y))) {
        return Math.abs(Number(x) - Number(y)) < 1e-9;
    }
    return x.toLowerCase() === y.toLowerCase();
}
