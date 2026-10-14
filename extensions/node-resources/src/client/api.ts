import { http } from '@pterodactyl/sdk';

const admin = '/api/admin/extensions/node-resources';
const server = (id: string) => `/api/client/servers/${id}/extensions/node-resources`;

export const keys = {
    overview: ['node-resources', 'overview'] as const,
    alerts: ['node-resources', 'alerts'] as const,
    nodes: ['node-resources', 'nodes'] as const,
    node: (id: number) => ['node-resources', 'node', id] as const,
    capacity: (id: number) => ['node-resources', 'capacity', id] as const,
    serverCapacity: (id: number) => ['node-resources', 'server-capacity', id] as const,
    nodeHistory: (id: number, range: string) => ['node-resources', 'node-history', id, range] as const,
    serverHistory: (id: string, range: string) => ['node-resources', 'server-history', id, range] as const,
};

export type Level = 'warning' | 'danger';

export interface Warning {
    id: string;
    level: Level;
    text: string;
}

/** Memory or disk of a node. Sizes are bytes; null means not known. */
export interface Resource {
    host_total: number | null;
    host_used: number | null;
    host_free: number | null;
    node_size: number;
    overallocate: number;
    panel_limit: number;
    allocated: number;
    unlimited: number;
    servers_used: number;
    committed: number;
    unallocated: number;
    overcommitted: boolean | null;
}

export interface CpuResource {
    threads: number | null;
    capacity: number | null;
    load1: number | null;
    allocated: number;
    unlimited: number;
    servers_used: number;
    committed: number;
}

export type ProbeState =
    'none' | 'installing' | 'failed' | 'suspended' | 'waiting' | 'ok' | 'stale' | 'offline' | 'starting' | 'unreachable';

export interface ProbeStatus {
    state: ProbeState;
    server: { id: string; name: string } | null;
    last_at: string | null;
}

export interface Forecast {
    days: number | null;
    growth_per_day: number;
}

export interface Suggestion {
    memory: number | null;
    disk: number | null;
}

export interface ServerUsage {
    limit: number;
    now: number | null;
    peak: number | null;
}

export interface ServerRow {
    id: string;
    admin_id: number;
    name: string;
    state: string | null;
    status: string | null;
    is_probe: boolean;
    memory: ServerUsage;
    disk: ServerUsage;
    cpu: ServerUsage;
    suggestion: Suggestion | null;
}

export interface CollectorStatus {
    last_run_at: string | null;
    age: number | null;
    stale: boolean;
    nodes_ok: number;
    nodes_failed: number;
    servers: number;
    seconds: number;
}

export interface NodeInfo {
    id: number;
    name: string;
    fqdn: string;
    memory: number;
    memory_overallocate: number;
    disk: number;
    disk_overallocate: number;
    maintenance: boolean;
}

export interface NodeReport {
    node: NodeInfo;
    collected_at: string | null;
    reachable: boolean | null;
    probe: ProbeStatus;
    ram: Resource;
    disk: Resource;
    cpu: CpuResource;
    forecast: Forecast | null;
    warnings: Warning[];
    data_since: string | null;
    servers?: ServerRow[];
    collector?: CollectorStatus;
    settings?: { disk_percent: number; forecast_days: number; retention: string };
}

export interface Overview {
    collector: CollectorStatus;
    settings: { disk_percent: number; forecast_days: number; retention: string };
    nodes: NodeReport[];
}

export interface Alerts {
    collector: CollectorStatus;
    warnings: (Warning & { node: { id: number; name: string } })[];
}

export type Range = '24h' | '7d' | '30d' | '90d' | '1y';

export const RANGE_SECONDS: Record<Range, number> = { '24h': 86_400, '7d': 604_800, '30d': 2_592_000, '90d': 7_776_000, '1y': 31_536_000 };

export const RANGE_LABEL: Record<Range, string> = { '24h': '24 h', '7d': '7 days', '30d': '30 days', '90d': '90 days', '1y': '1 year' };

export interface NodeHistory {
    range: Range;
    step: number;
    ranges: Range[];
    points: {
        t: number;
        mem: number | null;
        disk: number | null;
        load: number | null;
        sv_mem: number | null;
        sv_disk: number | null;
        sv_cpu: number | null;
    }[];
    mem_total: number | null;
    disk_total: number | null;
    cpus: number | null;
}

export interface ServerHistory {
    range: Range;
    step: number;
    ranges: Range[];
    points: { t: number; cpu: number | null; mem: number | null; disk: number | null }[];
    peaks: { cpu: number | null; mem: number | null; disk: number | null };
    limits: { memory: number; disk: number; cpu: number };
    collector: { last_run_at: string | null };
}

export const getOverview = async () => (await http.get<Overview>(`${admin}/overview`)).data;

export const getAlerts = async () => (await http.get<Alerts>(`${admin}/alerts`)).data;

export const getNodes = async () => (await http.get<{ data: { id: number; name: string }[] }>(`${admin}/nodes`)).data.data;

export const getNode = async (id: number) => (await http.get<NodeReport>(`${admin}/nodes/${id}`)).data;

export const getCapacity = async (id: number) => (await http.get<NodeReport>(`${admin}/nodes/${id}/capacity`)).data;

export const getServerCapacity = async (id: number) =>
    (
        await http.get<{ server: { id: number; name: string; memory: number; disk: number; cpu: number }; report: NodeReport }>(
            `${admin}/servers/${id}/capacity`,
        )
    ).data;

export const getNodeHistory = async (id: number, range: Range) =>
    (await http.get<NodeHistory>(`${admin}/nodes/${id}/history`, { params: { range } })).data;

export const setupProbe = async (id: number) => (await http.post<NodeReport>(`${admin}/nodes/${id}/probe`)).data;

export const removeProbe = async (id: number) => (await http.delete<NodeReport>(`${admin}/nodes/${id}/probe`)).data;

export const refreshNode = async (id: number) => (await http.post<NodeReport>(`${admin}/nodes/${id}/refresh`)).data;

export const applyLimits = async (id: number, fields: ('memory' | 'disk')[]) =>
    (await http.post<{ applied: Record<string, number> }>(`${admin}/servers/${id}/limits`, { fields })).data.applied;

export const getServerHistory = async (id: string, range: Range) =>
    (await http.get<ServerHistory>(`${server(id)}/history`, { params: { range } })).data;
