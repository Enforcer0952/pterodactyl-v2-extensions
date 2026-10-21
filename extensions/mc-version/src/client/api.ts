import { http } from '@pterodactyl/sdk';

export type SoftwareId = 'paper' | 'purpur' | 'fabric' | 'vanilla';

export interface JavaImage {
    label: string;
    image: string;
    java: number;
}

export type JavaStatus = 'ok' | 'works' | 'too_old' | 'unknown';

export interface Warning {
    code: string;
    severity: 'info' | 'warning' | 'danger';
    message: string;
}

export interface Pending {
    software: string | null;
    label: string;
    version: string | null;
    build: string | null;
    at: number;
}

export interface Overview {
    state: string;
    jar: { name: string; exists: boolean; size: number | null; modified: string | null };
    detected: {
        software: string | null;
        label: string;
        version: string | null;
        build: string | null;
        source: 'log' | 'history' | 'folder' | null;
    };
    /** A switch made on this page that the server has not started with yet. */
    pending: Pending | null;
    variables: { name: string; value: string; editable: boolean }[];
    egg: { name: string; software: string | null };
    mismatches: Warning[];
    java: {
        image: string;
        java: number | null;
        images: JavaImage[];
        recommended: JavaImage | null;
        for_version: string | null;
        status: JavaStatus;
        /** An administrator typed the image in by hand. */
        locked: boolean;
    };
    copies: { name: string; created: string | null; size: number; software: string | null; version: string | null }[];
    software: { id: SoftwareId; label: string }[];
    can: { apply: boolean; java: boolean; rollback: boolean; stop: boolean; delete: boolean };
}

export interface VersionEntry {
    id: string;
    stable: boolean;
}

export interface Plan {
    target: {
        software: SoftwareId;
        label: string;
        version: string;
        build: string | null;
        channel: string;
        stable: boolean;
        size: number | null;
        checksum: string | null;
    };
    jar: string;
    keeps_copy: boolean;
    warnings: Warning[];
    needs_confirmation: boolean;
    /** A downgrade or a move between plugins and mods: the user must say the world is backed up. */
    needs_backup: boolean;
    verification: { content: boolean; note: string };
    variables: { name: string; from: string; to: string }[];
    java: { recommended: JavaImage | null; current: string; change: boolean; locked: boolean };
}

export interface Change {
    notes: string[];
    overview: Overview;
}

const base = (server: string) => `/api/client/servers/${server}/extensions/mc-version`;

export const keys = {
    overview: (server: string) => ['mc-version', server, 'overview'] as const,
    versions: (server: string, software: string, experimental: boolean) =>
        ['mc-version', server, 'versions', software, experimental] as const,
    plan: (server: string, software: string, version: string, experimental: boolean) =>
        ['mc-version', server, 'plan', software, version, experimental] as const,
};

export const getOverview = async (server: string) => (await http.get<Overview>(base(server))).data;

export const getVersions = async (server: string, software: string, experimental: boolean) =>
    (await http.get<{ versions: VersionEntry[] }>(`${base(server)}/versions`, { params: { software, experimental: experimental ? 1 : 0 } }))
        .data.versions;

export const getPlan = async (server: string, software: string, version: string, experimental: boolean) =>
    (await http.get<Plan>(`${base(server)}/plan`, { params: { software, version, experimental: experimental ? 1 : 0 } })).data;

export const applySwitch = async (
    server: string,
    input: { software: string; version: string; experimental: boolean; confirmed: boolean; setJava: boolean; backupConfirmed: boolean },
) =>
    (
        await http.post<Change>(`${base(server)}/apply`, {
            software: input.software,
            version: input.version,
            experimental: input.experimental ? 1 : 0,
            confirmed: input.confirmed ? 1 : 0,
            set_java: input.setJava ? 1 : 0,
            backup_confirmed: input.backupConfirmed ? 1 : 0,
        })
    ).data;

export const rollback = async (server: string, restoreJava: boolean) =>
    (await http.post<Change>(`${base(server)}/rollback`, { restore_java: restoreJava ? 1 : 0 })).data;

export const setJava = async (server: string, image: string) =>
    (await http.put<{ overview: Overview }>(`${base(server)}/java`, { image })).data.overview;
