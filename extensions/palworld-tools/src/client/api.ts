import { http, type SdkServer } from '@pterodactyl/sdk';

export type ServerState = 'running' | 'offline' | 'starting' | 'stopping' | string;

const base = (server: string) => `/api/client/servers/${server}/extensions/palworld-tools`;

export const keys = {
    players: (server: string) => ['palworld-tools', server, 'players'] as const,
    settings: (server: string) => ['palworld-tools', server, 'settings'] as const,
    saves: (server: string) => ['palworld-tools', server, 'saves'] as const,
    bans: (server: string) => ['palworld-tools', server, 'bans'] as const,
};

/**
 * Whether this is a Palworld server: the "palworld" egg tag, or a startup command
 * that runs PalServer. The common egg only has the "steamcmd" tag.
 */
export function isPalworldServer(server: Pick<SdkServer, 'attributes'> | null | undefined): boolean {
    if (!server) return false;
    const tags = (server.attributes.egg_tags ?? []).map((tag) => tag.toLowerCase());
    return tags.includes('palworld') || /PalServer/i.test(server.attributes.invocation ?? '');
}

/* Players */

export interface OnlinePlayer {
    name: string;
    account_name: string | null;
    user_id: string;
    player_id: string | null;
    level: number | null;
    ping: number | null;
    x: number | null;
    y: number | null;
    buildings: number | null;
}

export type RestReason = 'offline' | 'disabled' | 'not_allocated' | 'no_password' | 'unreachable' | 'auth' | 'failed';

export interface PlayersResponse {
    state: ServerState;
    available: boolean;
    reason: RestReason | null;
    message: string | null;
    port: number | null;
    info: { version: string | null; name: string | null; description: string | null } | null;
    metrics: {
        fps: number | null;
        uptime: number | null;
        days: number | null;
        players: number | null;
        max_players: number | null;
        base_camps: number | null;
    } | null;
    players: OnlinePlayer[];
}

export type PlayerAction = 'kick' | 'ban' | 'unban' | 'announce' | 'save';

export const getPlayers = async (server: string) => (await http.get<PlayersResponse>(`${base(server)}/players`)).data;

export const runPlayerAction = async (server: string, input: { action: PlayerAction; user_id?: string; message?: string }) =>
    (await http.post<PlayersResponse>(`${base(server)}/players/action`, input)).data;

export const enableRestApi = async (server: string, restart: boolean) =>
    (await http.post<{ result: SaveResult; port: number; allocated: boolean }>(`${base(server)}/settings/rest-api`, { restart })).data;

/** Player ids as the REST API gives them: steam_7656..., xbox_..., mac_... */
export const USER_ID = /^[A-Za-z0-9_.:-]{1,64}$/;

/* Settings */

export type SettingType = 'string' | 'int' | 'float' | 'bool' | 'enum' | 'list';

export interface Setting {
    key: string;
    group: string;
    type: SettingType;
    default: string;
    label: string;
    help: string;
    min?: number;
    max?: number;
    options?: string[];
    secret?: boolean;
    env?: string;
}

export interface Preset {
    id: string;
    label: string;
    description: string;
    values: Record<string, string>;
}

export interface Lock {
    kind: 'variable' | 'allocation' | 'startup';
    env: string | null;
    editable?: boolean;
}

export interface Field {
    key: string;
    value: string | null;
    set: boolean | null;
    lock: Lock | null;
}

export interface Edit {
    key: string;
    value: string | null;
}

export interface Issue {
    key: string;
    kind: 'duplicate' | 'invalid' | 'broken';
    level: string;
    message: string;
    fixes: { label: string; edits: Edit[] }[];
}

export type SaveResult = 'unchanged' | 'saved' | 'waiting';

export interface SettingsResponse {
    state: ServerState;
    file: { path: string; exists: boolean; from_template: boolean; raw: string; hash: string };
    fields: Field[];
    issues: Issue[];
    apply: { status: 'waiting' | 'writing' | 'failed'; since: string; error: string | null } | null;
    parser: boolean;
    schema: { groups: { id: string; label: string }[]; settings: Setting[]; presets: Preset[] };
    can: { secrets: boolean; control: boolean; start: boolean; write: boolean };
    result?: SaveResult;
}

export interface SettingsSave {
    raw?: string;
    edits?: Edit[];
    hash: string;
    restart?: boolean;
    start?: boolean;
}

/** UTF-8 text as base64, so the panel's request trimming cannot change it. */
export function toBase64(text: string): string {
    const bytes = new TextEncoder().encode(text);
    let binary = '';
    for (let i = 0; i < bytes.length; i += 0x8000) binary += String.fromCharCode(...bytes.subarray(i, i + 0x8000));
    return btoa(binary);
}

export const getSettings = async (server: string) => (await http.get<SettingsResponse>(`${base(server)}/settings`)).data;

export const saveSettings = async (server: string, input: SettingsSave) =>
    (
        await http.put<SettingsResponse>(`${base(server)}/settings`, {
            hash: input.hash,
            // The panel turns an empty string into null, so clearing a text is sent as a flag.
            edits: (input.edits ?? []).map((edit) => (edit.value === '' ? { key: edit.key, value: null, clear: true } : edit)),
            restart: input.restart ?? false,
            start: input.start ?? false,
            ...(input.raw !== undefined ? { raw: toBase64(input.raw) } : {}),
        })
    ).data;

export const processSettings = async (server: string) => (await http.post<SettingsResponse>(`${base(server)}/settings/apply`)).data;

export const cancelSettingsApply = async (server: string) => (await http.delete<SettingsResponse>(`${base(server)}/settings/apply`)).data;

/** A problem with a value typed into the form, or null when it is fine. */
export function validateSetting(setting: Setting, value: string): string | null {
    const trimmed = value.trim();
    switch (setting.type) {
        case 'bool':
            return /^(true|false)$/i.test(trimmed) ? null : 'Must be True or False.';
        case 'enum':
            return (setting.options ?? []).some((option) => option.toLowerCase() === trimmed.toLowerCase())
                ? null
                : 'Pick one of the options.';
        case 'list':
            return trimmed
                .split(',')
                .map((item) => item.trim())
                .filter(Boolean)
                .every((item) => (setting.options ?? []).some((option) => option.toLowerCase() === item.toLowerCase()))
                ? null
                : 'Use only the listed values.';
        case 'string':
            if (/[\u0000-\u001f\u007f]/.test(value)) return 'No line breaks.';
            return setting.max !== undefined && trimmed.length > setting.max
                ? `At most ${setting.max} characters.`
                : trimmed.length > 500
                  ? 'Too long.'
                  : null;
        default: {
            const pattern = setting.type === 'int' ? /^-?\d{1,12}$/ : /^-?\d{1,12}(\.\d{1,12})?$/;
            if (!pattern.test(trimmed)) return setting.type === 'int' ? 'Enter a whole number.' : 'Enter a number.';
            const number = Number(trimmed);
            if (setting.min !== undefined && number < setting.min) return `At least ${setting.min}.`;
            if (setting.max !== undefined && number > setting.max) return `At most ${setting.max}.`;
            return null;
        }
    }
}

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

/** The default as a person reads it: "1.000000" shows as 1, "(A,B)" as A,B. */
export function displayDefault(setting: Setting): string {
    if (setting.type === 'float') return String(Number(setting.default));
    return setting.default.replace(/^\(|\)$/g, '');
}

/** The value a field shows: an unsaved edit, else the file (or startup variable) value. */
export function currentValue(setting: Setting, field: Field | undefined, edit: Edit | undefined): string | null {
    return edit ? edit.value : (field?.value ?? null);
}

/* Saves */

export interface GameBackup {
    name: string;
    at: string;
}

export interface World {
    id: string;
    active: boolean;
    has_level: boolean;
    size: number | null;
    modified: string | null;
    players: number;
    game_backups: GameBackup[];
}

export interface WorldBackup {
    file: string;
    world: string;
    stamp: string;
    size: number;
    modified: string | null;
}

export interface SavesResponse {
    state: ServerState;
    worlds: World[];
    backups: WorldBackup[];
    keep: number;
    directory: string;
    saves_directory: string;
    result?: { file?: string; saved?: boolean | null; world?: string; kept?: string | null };
}

export const getSaves = async (server: string) => (await http.get<SavesResponse>(`${base(server)}/saves`)).data;

export const backupWorld = async (server: string, world: string) =>
    (await http.post<SavesResponse>(`${base(server)}/saves/backup`, { world })).data;

export const restoreBackup = async (server: string, file: string) =>
    (await http.post<SavesResponse>(`${base(server)}/saves/restore`, { file })).data;

export const restoreSnapshot = async (server: string, world: string, snapshot: string) =>
    (await http.post<SavesResponse>(`${base(server)}/saves/restore-snapshot`, { world, snapshot })).data;

export const deleteBackup = async (server: string, file: string) =>
    (await http.post<SavesResponse>(`${base(server)}/saves/backups/delete`, { file })).data;

export interface BansResponse {
    state: ServerState;
    exists: boolean;
    path: string;
    ids: string[];
}

export const getBans = async (server: string) => (await http.get<BansResponse>(`${base(server)}/bans`)).data;

export const removeBan = async (server: string, userId: string) =>
    (await http.post<BansResponse>(`${base(server)}/bans/remove`, { user_id: userId })).data;
