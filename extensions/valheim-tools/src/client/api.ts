import { http, type SdkServer } from '@pterodactyl/sdk';

export type ServerState = 'running' | 'offline' | 'starting' | 'stopping' | string;

const base = (server: string) => `/api/client/servers/${server}/extensions/valheim-tools`;

export const keys = {
    players: (server: string) => ['valheim-tools', server, 'players'] as const,
    lists: (server: string) => ['valheim-tools', server, 'lists'] as const,
    worlds: (server: string) => ['valheim-tools', server, 'worlds'] as const,
    mods: (server: string) => ['valheim-tools', server, 'mods'] as const,
    updates: (server: string) => ['valheim-tools', server, 'mod-updates'] as const,
    search: (server: string, query: string, ordering: string, page: number) =>
        ['valheim-tools', server, 'search', query, ordering, page] as const,
    settings: (server: string) => ['valheim-tools', server, 'settings'] as const,
};

/**
 * Whether a server is a Valheim server: the "valheim" egg tag, or a startup command
 * that runs valheim_server. Mirrors the check the panel side makes. (The panel side also
 * accepts the Steam app id 896660; the browser does not know it.)
 */
export function isValheim(tags: readonly string[] | null | undefined, invocation: string | null | undefined): boolean {
    return (tags ?? []).some((tag) => tag.toLowerCase() === 'valheim') || /valheim_server/i.test(invocation ?? '');
}

export function isValheimServer(server: SdkServer | undefined): boolean {
    return server !== undefined && isValheim(server.attributes.egg_tags, server.attributes.invocation);
}

/** Whether the startup command loads BepInEx, so the Mods page is worth showing. */
export function usesBepInEx(invocation: string | null | undefined): boolean {
    return /bepinex|doorstop/i.test(invocation ?? '');
}

/* Players */

export interface OnlinePlayer {
    name: string;
    id: string | null;
    steam_id: string | null;
    steam: boolean;
    list_id: string | null;
}

export interface RecentPlayer {
    id: string | null;
    name: string;
    first_seen: string;
    last_seen: string;
    steam_id: string | null;
    list_id: string | null;
}

export interface PlayersResponse {
    state: ServerState;
    players: OnlinePlayer[];
    recent: RecentPlayer[];
    can_act: boolean;
}

export type PlayerAction = 'ban' | 'admin' | 'permit';

export const getPlayers = async (server: string) => (await http.get<PlayersResponse>(`${base(server)}/players`)).data;

export const runPlayerAction = async (server: string, action: PlayerAction, id: string, confirm = false) =>
    (await http.post<{ action: string; id: string; added: boolean }>(`${base(server)}/players/action`, { action, id, confirm })).data;

/* Access lists */

export type ListKind = 'admin' | 'banned' | 'permitted';

export interface ListEntry {
    id: string;
    steam_id: string | null;
    name: string | null;
    last_seen: string | null;
}

export interface AccessList {
    file: string;
    exists: boolean;
    entries: ListEntry[];
}

export interface ListsResponse {
    state: ServerState;
    directory: string;
    style: string;
    editable: boolean;
    lists: Record<ListKind, AccessList>;
}

export const getLists = async (server: string) => (await http.get<ListsResponse>(`${base(server)}/lists`)).data;

export const addToList = async (server: string, list: ListKind, id: string, confirm = false) =>
    (await http.post<ListsResponse & { added: boolean }>(`${base(server)}/lists/add`, { list, id, confirm })).data;

export const removeFromList = async (server: string, list: ListKind, id: string) =>
    (await http.post<ListsResponse & { removed: number }>(`${base(server)}/lists/remove`, { list, id })).data;

/* Worlds */

export interface World {
    name: string;
    format: 'folder' | 'files';
    entries: string[];
    complete: boolean;
    size: number;
    modified: string | null;
    active: boolean;
    automatic_backups: number;
}

export interface WorldBackup {
    id: string;
    world: string;
    kind: '' | 'before-restore' | 'before-delete';
    size: number;
    modified: string | null;
}

export interface AutomaticBackup {
    id: string;
    world: string;
    stamp: string;
    auto: boolean;
    format: string;
    size: number;
    modified: string | null;
    time: string | null;
}

export interface WorldsResponse {
    state: ServerState;
    worlds: World[];
    backups: WorldBackup[];
    automatic_backups: AutomaticBackup[];
    originals: { world: string; entries: string[]; size: number; modified: string | null }[];
    active: string | null;
    active_exists: boolean;
    worlds_directory: string;
    backups_directory: string;
    keep: number;
    variable: { exists: boolean; editable: boolean };
}

export type WorldsResult = WorldsResponse & { result?: { name?: string; backup?: string | null; safety?: string | null; world?: string } };

export const getWorlds = async (server: string) => (await http.get<WorldsResponse>(`${base(server)}/worlds`)).data;

export const switchWorld = async (server: string, name: string, create = false) =>
    (await http.post<WorldsResult>(`${base(server)}/worlds/switch`, { name, create })).data;

export const backupWorld = async (server: string, name: string, running = false) =>
    (await http.post<WorldsResult>(`${base(server)}/worlds/backup`, { name, running })).data;

export const restoreBackup = async (server: string, id: string, automatic = false) =>
    (await http.post<WorldsResult>(`${base(server)}/worlds/restore`, { id, automatic })).data;

export const deleteBackup = async (server: string, id: string) =>
    (await http.post<WorldsResult>(`${base(server)}/worlds/backups/delete`, { id })).data;

export const deleteWorld = async (server: string, name: string, backup: boolean) =>
    (await http.post<WorldsResult>(`${base(server)}/worlds/delete`, { name, backup })).data;

/** A name Valheim takes for -world "NAME" and that is safe in the startup command. Mirrors the panel side. */
export function validWorldName(name: string): boolean {
    return name.length <= 20 && !name.includes('..') && /^[\p{L}\p{N}](?:[\p{L}\p{N} _.-]*[\p{L}\p{N}_-])?$/u.test(name);
}

/* Mods */

export interface InstalledMod {
    id: string;
    folder: string;
    kind: 'plugins' | 'patchers' | 'monomod';
    file: boolean;
    name: string;
    version: string | null;
    owner: string | null;
    package: string | null;
    description: string | null;
    website: string | null;
    size: number;
    modified: string | null;
}

export interface ModsResponse {
    available: boolean;
    state: ServerState;
    mods: InstalledMod[];
}

export interface ModUpdates {
    updates: Record<string, { latest: string; update: boolean }>;
}

export interface SearchResult {
    namespace: string;
    name: string;
    description: string;
    downloads: number;
    rating: number;
    size: number;
    icon: string | null;
    updated: string | null;
    categories: string[];
    server_side: boolean;
    client_side: boolean;
    deprecated: boolean;
}

export interface SearchResponse {
    results: SearchResult[];
    total: number;
    has_more: boolean;
}

export type Ordering = 'most-downloaded' | 'top-rated' | 'newest' | 'last-updated';

export type InstallResult = ModsResponse & {
    result?: { installed: { package: string; version: string; updated: boolean }[]; notes: string[] };
};

export const getMods = async (server: string) => (await http.get<ModsResponse>(`${base(server)}/mods`)).data;

export const getModUpdates = async (server: string) => (await http.get<ModUpdates>(`${base(server)}/mods/updates`)).data;

export const searchMods = async (server: string, q: string, ordering: Ordering, page: number) =>
    (await http.get<SearchResponse>(`${base(server)}/mods/search`, { params: { q, ordering, page } })).data;

export const installMod = async (server: string, pkg: string, version?: string) =>
    (await http.post<InstallResult>(`${base(server)}/mods/install`, { package: pkg, version: version ?? null })).data;

export const removeMod = async (server: string, kind: string, name: string) =>
    (await http.post<ModsResponse>(`${base(server)}/mods/remove`, { kind, name })).data;

/* Settings */

export interface SettingField {
    present: boolean;
    editable: boolean;
    value: string | null;
}

export interface SettingsResponse {
    name: SettingField;
    password: SettingField;
    public: SettingField;
    crossplay: SettingField;
    preset: SettingField;
    presets: string[];
    world: string | null;
    warning: string | null;
    limits: { name: number; password_min: number; password_max: number };
    can_update: boolean;
}

export interface SettingsInput {
    name?: string;
    password?: string;
    public?: boolean;
    crossplay?: boolean;
    preset?: string;
}

export const getSettings = async (server: string) => (await http.get<SettingsResponse>(`${base(server)}/settings`)).data;

export const saveSettings = async (server: string, input: SettingsInput) =>
    (await http.put<SettingsResponse>(`${base(server)}/settings`, input)).data;

/** Characters the egg's startup command cannot take inside quotes. Mirrors the panel side. */
export const UNSAFE_TEXT = /["$`\\{}\p{Cc}\p{Zl}\p{Zp}]/u;

/** An error for the server name, or null when fine. Mirrors the panel side. */
export function validateName(value: string, max: number): string | null {
    if (value.length < 1 || [...value].length > max) return `Use 1 to ${max} characters for the server name.`;
    if (value.trim() !== value) return 'The server name cannot start or end with a space.';
    if (UNSAFE_TEXT.test(value)) return 'The server name cannot contain quotes, backslashes, dollar signs, backticks or curly braces.';
    return null;
}

/** An error for the password, or null when fine. Mirrors the panel side. */
export function validatePassword(value: string, world: string | null, min: number, max: number): string | null {
    const length = [...value].length;
    if (length < min || length > max) return `Use ${min} to ${max} characters for the password.`;
    if (/\s/u.test(value)) return 'The password cannot contain spaces.';
    if (UNSAFE_TEXT.test(value)) return 'The password cannot contain quotes, backslashes, dollar signs, backticks or curly braces.';
    if (world && world.toLowerCase().includes(value.toLowerCase())) return 'Valheim refuses a password that is part of the world name.';
    return null;
}
