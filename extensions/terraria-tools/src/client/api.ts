import { http, type SdkServer } from '@pterodactyl/sdk';

export type ServerState = 'running' | 'offline' | 'starting' | 'stopping' | string;

const base = (server: string) => `/api/client/servers/${server}/extensions/terraria-tools`;

export const keys = {
    online: (server: string) => ['terraria-tools', server, 'online'] as const,
    bans: (server: string) => ['terraria-tools', server, 'bans'] as const,
    worlds: (server: string) => ['terraria-tools', server, 'worlds'] as const,
    settings: (server: string) => ['terraria-tools', server, 'settings'] as const,
};

/**
 * Whether a server is a Terraria server: the "terraria" egg tag, or a startup
 * command that runs TerrariaServer. Mirrors the check the panel side makes.
 */
export function isTerraria(tags: readonly string[] | null | undefined, invocation: string | null | undefined): boolean {
    return (tags ?? []).some((tag) => tag.toLowerCase() === 'terraria') || /TerrariaServer/i.test(invocation ?? '');
}

export function isTerrariaServer(server: SdkServer | undefined): boolean {
    return server !== undefined && isTerraria(server.attributes.egg_tags, server.attributes.invocation);
}

/* Players */

export interface OnlinePlayer {
    name: string;
}

export interface OnlineResponse {
    state: ServerState;
    players: OnlinePlayer[] | null;
}

export const getOnline = async (server: string, refresh = false) =>
    (await http.get<OnlineResponse>(`${base(server)}/players/online`, { params: refresh ? { refresh: 1 } : undefined })).data;

export const runPlayerAction = async (server: string, action: 'kick' | 'ban', name: string) =>
    (await http.post<{ sent: string; name: string }>(`${base(server)}/players/action`, { action, name })).data;

export interface BanEntry {
    name: string | null;
    id: string;
}

export interface BansResponse {
    state: ServerState;
    exists: boolean;
    path: string;
    entries: BanEntry[];
    editable: boolean;
}

export const getBans = async (server: string) => (await http.get<BansResponse>(`${base(server)}/bans`)).data;

export const removeBan = async (server: string, id: string) => (await http.post<BansResponse>(`${base(server)}/bans/remove`, { id })).data;

/* Admin */

export type AdminAction = 'save' | 'settle' | 'dawn' | 'noon' | 'dusk' | 'midnight' | 'say';

export const MAX_MESSAGE = 200;

export const runAdminCommand = async (server: string, action: AdminAction, message?: string) =>
    (await http.post<{ sent: string }>(`${base(server)}/admin/command`, { action, message })).data;

/* Worlds */

export interface World {
    name: string;
    size: number;
    modified: string | null;
    active: boolean;
    has_bak: boolean;
}

export interface WorldBackup {
    file: string;
    world: string;
    stamp: string;
    size: number;
    modified: string | null;
}

export interface WorldsResponse {
    state: ServerState;
    worlds: World[];
    backups: WorldBackup[];
    active: string | null;
    worlds_directory: string;
    backups_directory: string;
    keep: number;
    config_exists: boolean;
    variables: { name: boolean; name_editable: boolean };
}

export interface VariablesResult {
    updated: string[];
    skipped: string[];
}

export type WorldsResult = WorldsResponse & {
    result?: { name?: string; backup?: string | null; safety?: string | null; world?: string; variables?: VariablesResult };
};

export interface NewWorld {
    name: string;
    size: number;
    difficulty: number;
    seed: string;
}

export const getWorlds = async (server: string) => (await http.get<WorldsResponse>(`${base(server)}/worlds`)).data;

export const switchWorld = async (server: string, name: string) =>
    (await http.post<WorldsResult>(`${base(server)}/worlds/switch`, { name })).data;

export const backupWorld = async (server: string, name: string) =>
    (await http.post<WorldsResult>(`${base(server)}/worlds/backup`, { name })).data;

export const restoreBackup = async (server: string, file: string) =>
    (await http.post<WorldsResult>(`${base(server)}/worlds/restore`, { file })).data;

export const deleteBackup = async (server: string, file: string) =>
    (await http.post<WorldsResult>(`${base(server)}/worlds/backups/delete`, { file })).data;

export const deleteWorld = async (server: string, name: string, backup: boolean) =>
    (await http.post<WorldsResult>(`${base(server)}/worlds/delete`, { name, backup })).data;

export const createWorld = async (server: string, world: NewWorld) =>
    (await http.post<WorldsResult>(`${base(server)}/worlds/new`, world)).data;

export const WORLD_SIZES = [
    { value: 1, label: 'Small' },
    { value: 2, label: 'Medium' },
    { value: 3, label: 'Large' },
];

export const WORLD_DIFFICULTIES = [
    { value: 0, label: 'Classic' },
    { value: 1, label: 'Expert' },
    { value: 2, label: 'Master' },
    { value: 3, label: 'Journey' },
];

/** Same rule as the panel side: letters, digits, spaces, dash, underscore, dot, apostrophe. */
export function validWorldName(name: string): boolean {
    const text = name.trim();
    return /^[\p{L}\p{N}][\p{L}\p{N} _.'-]{0,39}$/u.test(text) && !text.includes('..') && !text.endsWith('.');
}

/* Settings */

export interface SettingField {
    key: string;
    label: string;
    kind: 'int' | 'text' | 'choice' | 'secret';
    help: string;
    value: string | null;
    set: boolean;
    present: boolean;
    min: number | null;
    max: number | null;
    choices: { value: string; label: string }[] | null;
}

export interface SettingsResponse {
    state: ServerState;
    exists: boolean;
    hash: string;
    raw: string;
    masked: boolean;
    editable: boolean;
    fields: SettingField[];
}

export type SettingsSaved = SettingsResponse & { written: boolean; variables: VariablesResult };

export const getSettings = async (server: string) => (await http.get<SettingsResponse>(`${base(server)}/settings`)).data;

/** The panel trims request strings, so raw text travels base64-encoded. */
export function encodeText(text: string): string {
    const bytes = new TextEncoder().encode(text);
    let binary = '';
    for (const byte of bytes) binary += String.fromCharCode(byte);
    return btoa(binary);
}

export const saveSettings = async (server: string, input: { raw?: string; edits?: Record<string, string>; hash: string }) =>
    (
        await http.put<SettingsSaved>(`${base(server)}/settings`, {
            raw: input.raw === undefined ? undefined : encodeText(input.raw),
            edits: input.edits,
            hash: input.hash,
        })
    ).data;

/** Why a setting's value is not acceptable, or null. Mirrors the panel side. */
export function validateField(field: SettingField, value: string): string | null {
    if (/[\u0000-\u001f\u007f-\u009f\u2028\u2029]/.test(value)) return `${field.label} must be a single line of text.`;
    if (field.kind === 'int') {
        const number = Number(value);
        if (!/^\d{1,4}$/.test(value) || number < (field.min ?? 0) || number > (field.max ?? 9999)) {
            return `${field.label} must be a number from ${field.min ?? 0} to ${field.max ?? 9999}.`;
        }
    } else if (field.kind === 'choice') {
        if (!field.choices?.some((choice) => choice.value === value)) return `Pick one of the choices.`;
    } else if (field.max !== null && [...value].length > field.max) {
        return `${field.label} can be at most ${field.max} characters.`;
    }
    return null;
}
