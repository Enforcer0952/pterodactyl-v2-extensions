import { http } from '@pterodactyl/sdk';

const base = (server: string) => `/api/client/servers/${server}/extensions/mc-crash`;

export const keys = {
    status: (server: string) => ['mc-crash', server, 'status'] as const,
    reports: (server: string) => ['mc-crash', server, 'reports'] as const,
    logs: (server: string) => ['mc-crash', server, 'logs'] as const,
    raw: (server: string, path: string) => ['mc-crash', server, 'raw', path] as const,
};

export type Severity = 'crash' | 'error' | 'warning' | 'info';
export type Tier = 'confirmed' | 'likely';

export interface JarRef {
    file: string;
    name: string;
    id: string | null;
    version: string | null;
    kind: 'plugin' | 'mod' | null;
    /** False when the trace names a jar that is no longer in the plugins or mods folder. */
    installed: boolean;
}

export interface Culprit {
    tier: Tier;
    jars: JarRef[];
    basis: string;
}

export interface Verdict {
    rule: string | null;
    severity: Severity;
    title: string;
    verdict: string;
    fix: string | null;
    culprit: Culprit | null;
    exception: string | null;
    message: string | null;
    also: { rule: string; title: string; verdict: string }[];
    meta: { kind: string; description: string | null; minecraft: string | null; java: string | null };
    time: string | null;
}

export type ReportStatus = 'ok' | 'pending' | 'too_large' | 'unreadable';

export interface Report {
    path: string;
    name: string;
    kind: 'crash' | 'jvm' | 'log';
    size: number;
    modified: string | null;
    time: string | null;
    status: ReportStatus;
    verdict: Verdict | null;
}

export interface IndexProgress {
    total: number;
    pending: number;
}

export interface ReportsResponse {
    reports: Report[];
    pending: number;
    index: IndexProgress;
    /** Whether the Plugins and Mods pages of mc-content exist on this panel. */
    links: boolean;
    sharing: boolean;
}

export interface LogGroup {
    id: string;
    rule: string | null;
    severity: Severity;
    level: string;
    title: string;
    fix: string | null;
    culprit: Culprit | null;
    exception: string | null;
    count: number;
    first: string | null;
    last: string | null;
    stat: string | null;
    noise: boolean;
    sample: string;
}

export type SourceStatus = 'scanned' | 'pending' | 'too_large' | 'failed' | 'empty' | 'console';

export interface LogSource {
    path: string;
    name: string;
    live: boolean;
    size: number;
    modified: string | null;
    status: SourceStatus;
    note: string | null;
    entries: number;
}

export interface LogsResponse {
    sources: LogSource[];
    groups: LogGroup[];
    pending: number;
    index: IndexProgress;
    links: boolean;
    sharing: boolean;
}

export interface StatusResponse {
    new: number;
    latest: string | null;
    viewed_at: string | null;
}

export const getStatus = async (server: string) => (await http.get<StatusResponse>(`${base(server)}/status`)).data;

export const markViewed = async (server: string) => (await http.post<{ new: number }>(`${base(server)}/viewed`)).data;

export const getReports = async (server: string) => (await http.get<ReportsResponse>(`${base(server)}/reports`)).data;

export const getLogs = async (server: string) => (await http.get<LogsResponse>(`${base(server)}/logs`)).data;

export const getRaw = async (server: string, path: string) =>
    (await http.get<{ text: string; truncated: boolean }>(`${base(server)}/raw`, { params: { path } })).data;

export interface SharePreview {
    /** The text that would be uploaded, cut to the first part when it is long. */
    text: string;
    shown_all: boolean;
    bytes: number;
    lines: number;
    /** Identifies the exact text; the upload is refused if the file changed after the preview. */
    hash: string;
    hidden: { ips: number; commands: number; jvm_args: number };
}

export const getSharePreview = async (server: string, path: string) =>
    (await http.get<SharePreview>(`${base(server)}/share/preview`, { params: { path } })).data;

export const shareFile = async (server: string, path: string, hash: string) =>
    (await http.post<{ url: string }>(`${base(server)}/share`, { path, hash })).data;
