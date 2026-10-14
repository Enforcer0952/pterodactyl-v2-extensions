import { http } from '@pterodactyl/sdk';

export type GuardState = 'off' | 'watching' | 'tripped';
export type EventKind = 'crash' | 'trip' | 'blocked' | 'release' | 'cleared';

export interface GuardEvent {
    id: number;
    kind: EventKind;
    /** A crash waits as "pending" until the activity log can say whether someone caused it. */
    status: 'pending' | 'confirmed';
    via: string | null;
    uptime_before: number | null;
    note: string | null;
    occurred_at: string;
}

export interface Guard {
    state: GuardState;
    enabled: boolean;
    max_crashes: number;
    window_minutes: number;
    defaults: { enabled: boolean; max_crashes: number; window_minutes: number };
    recent_crashes: number;
    tripped: { at: string; crashes: number; minutes: number } | null;
    events: GuardEvent[];
    mc_crash: boolean;
}

export const LIMITS = { minCrashes: 2, maxCrashes: 20, minMinutes: 1, maxMinutes: 120 } as const;

const base = (server: string) => `/api/client/servers/${server}/extensions/crash-guard`;

export const keys = {
    guard: (server: string) => ['crash-guard', server, 'guard'] as const,
    admin: (page: number, status: string, search: string) => ['crash-guard', 'admin', page, status, search] as const,
};

export const getGuard = async (server: string) => (await http.get<Guard>(`${base(server)}/`)).data;

export const saveGuard = async (server: string, input: { enabled: boolean; max_crashes: number; window_minutes: number }) =>
    (await http.put<Guard>(`${base(server)}/`, input)).data;

export const clearGuard = async (server: string, start: boolean) => (await http.post<Guard>(`${base(server)}/clear`, { start })).data;

/* Admin */

export interface AdminServer {
    id: string;
    name: string;
    node: string;
    status: 'watching' | 'tripped';
    max_crashes: number;
    window_minutes: number;
    last_state: string | null;
    last_seen_at: string | null;
    tripped_at: string | null;
    trip_crashes: number | null;
    trip_minutes: number | null;
    crashes_24h: number;
    last_crash_at: string | null;
    recent: { kind: EventKind; note: string | null; occurred_at: string }[];
}

export interface AdminList {
    data: AdminServer[];
    meta: { page: number; per_page: number; total: number; guarded: number; tripped: number; default_enabled: boolean };
}

export const getAdminServers = async (page: number, status: string, search: string) =>
    (
        await http.get<AdminList>('/api/admin/extensions/crash-guard/servers', {
            params: { page, ...(status === 'tripped' ? { status } : {}), ...(search.trim() === '' ? {} : { search: search.trim() }) },
        })
    ).data;
