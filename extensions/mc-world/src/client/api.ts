import { http } from '@pterodactyl/sdk';

export type ServerState = 'running' | 'offline' | 'starting' | 'stopping' | string;

const base = (server: string) => `/api/client/servers/${server}/extensions/mc-world`;

export const keys = {
    worlds: (server: string) => ['mc-world', server, 'worlds'] as const,
    properties: (server: string) => ['mc-world', server, 'properties'] as const,
};

/* Worlds */

export interface World {
    name: string;
    active: boolean;
    modified: string | null;
    /** The folders that belong to this world: itself and, on Paper, its nether and end. */
    folders: string[];
    layout: 'single' | 'split';
}

export interface Backup {
    name: string;
    world: string;
    stamp: string;
    size: number | null;
    modified: string | null;
}

export interface WorldsResponse {
    state: ServerState;
    properties_exists: boolean;
    active: string;
    active_valid: boolean;
    hardcore: boolean;
    worlds: World[];
    backups: Backup[];
    keep: number;
    result?: { backup?: string; deleted?: string[]; world?: string; kept?: string[] };
}

export interface ResetInput {
    world: string;
    /** Empty means a random seed. */
    seed: string;
    backup: boolean;
    start: boolean;
}

export const getWorlds = async (server: string) => (await http.get<WorldsResponse>(`${base(server)}/worlds`)).data;

export const backupWorld = async (server: string, world: string) =>
    (await http.post<WorldsResponse>(`${base(server)}/worlds/backup`, { world })).data;

export const restoreBackup = async (server: string, backup: string) =>
    (await http.post<WorldsResponse>(`${base(server)}/worlds/restore`, { backup })).data;

export const deleteBackup = async (server: string, backup: string) =>
    (await http.delete<WorldsResponse>(`${base(server)}/worlds/backups/${encodeURIComponent(backup)}`)).data;

export const resetWorld = async (server: string, input: ResetInput) =>
    (await http.post<WorldsResponse>(`${base(server)}/worlds/reset`, input)).data;

export const switchWorld = async (server: string, world: string) =>
    (await http.put<WorldsResponse>(`${base(server)}/worlds/active`, { world })).data;

export const deleteWorld = async (server: string, world: string) =>
    (await http.delete<WorldsResponse>(`${base(server)}/worlds/${encodeURIComponent(world)}`)).data;

/** The panel's own power endpoint, so the usual control.stop permission applies. */
export const stopServer = async (server: string) => {
    await http.post(`/api/client/servers/${server}/power`, { signal: 'stop' });
};

/* Properties */

export type PropertyType = 'string' | 'text' | 'bool' | 'int' | 'select';

export interface PropertyDef {
    key: string;
    group: string;
    type: PropertyType;
    label: string;
    help: string;
    default: string;
    min?: number;
    max?: number;
    options?: { value: string; label: string }[];
}

export interface PropertiesResponse {
    state: ServerState;
    exists: boolean;
    hash: string;
    entries: { key: string; value: string }[];
    schema: { groups: { id: string; label: string }[]; settings: PropertyDef[] };
    changed?: string[];
}

/** A change to one key: a new value, or `remove` to delete the line. */
export interface PropertyEdit {
    key: string;
    value?: string;
    remove?: boolean;
}

export const getProperties = async (server: string) => (await http.get<PropertiesResponse>(`${base(server)}/properties`)).data;

/** UTF-8 text as base64, so the panel's request trimming cannot change it. */
export function toBase64(text: string): string {
    const bytes = new TextEncoder().encode(text);
    let binary = '';
    for (let i = 0; i < bytes.length; i += 0x8000) binary += String.fromCharCode(...bytes.subarray(i, i + 0x8000));
    return btoa(binary);
}

export const saveProperties = async (server: string, hash: string, edits: PropertyEdit[]) =>
    (
        await http.put<PropertiesResponse>(`${base(server)}/properties`, {
            hash,
            edits: edits.map((edit) =>
                edit.remove ? { key: edit.key, remove: true } : { key: edit.key, value: toBase64(edit.value ?? '') },
            ),
        })
    ).data;
