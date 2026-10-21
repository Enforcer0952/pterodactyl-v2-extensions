import { http } from '@pterodactyl/sdk';

const base = (server: string) => `/api/client/servers/${server}/extensions/mc-network`;

export const keys = {
    network: (server: string) => ['mc-network', server, 'network'] as const,
    check: (server: string, draft: string) => ['mc-network', server, 'check', draft] as const,
};

export type Level = 'error' | 'warning' | 'info';

export interface Problem {
    code: string;
    level: Level;
    message: string;
}

export interface GlobalProblem extends Problem {
    name: string;
}

export interface Match {
    id: string;
    name: string;
}

export interface Analysis {
    host: string | null;
    port: number | null;
    local: boolean;
    server: Match | null;
    problems: Problem[];
}

export interface BackendRow extends Analysis {
    name: string;
    address: string;
    motd: string;
    motd_plain: string;
    restricted: boolean;
    server: (Match & { state?: string | null }) | null;
}

export interface ForcedHost {
    host: string;
    server: string;
}

export interface PanelChoice {
    id: string;
    name: string;
    port: number;
}

export interface NetworkResponse {
    status: 'ok' | 'missing' | 'velocity' | 'invalid';
    message?: string;
    saved?: boolean;
    proxy_state: string | null;
    hash: string;
    servers: BackendRow[];
    priorities: string[];
    forced_hosts: ForcedHost[];
    problems: GlobalProblem[];
    panel_servers: PanelChoice[];
    has_listener: boolean;
}

/** One backend as the editor holds it. "was" is the name it has in the saved file. */
export interface Entry {
    name: string;
    address: string;
    motd: string;
    restricted: boolean;
    was: string | null;
}

export interface Draft {
    servers: Entry[];
    priorities: string[];
    forced_hosts: ForcedHost[];
}

export interface CheckResponse {
    entries: Analysis[];
    problems: GlobalProblem[];
    panel_servers: PanelChoice[];
}

export const getNetwork = async (server: string) => (await http.get<NetworkResponse>(`${base(server)}/network`)).data;

/** Forced hosts that are still empty are left out: they are a row the person has not filled in yet. */
export const cleanDraft = (draft: Draft): Draft => ({
    ...draft,
    forced_hosts: draft.forced_hosts.filter((forced) => forced.host.trim() !== '' && forced.server !== ''),
});

export const checkDraft = async (server: string, draft: Draft) =>
    (await http.post<CheckResponse>(`${base(server)}/network/check`, cleanDraft(draft))).data;

export const saveNetwork = async (server: string, draft: Draft, hash: string) =>
    (await http.put<NetworkResponse>(`${base(server)}/network`, { ...cleanDraft(draft), hash })).data;

export const restartProxy = async (server: string) => (await http.post<{ restarting: boolean }>(`${base(server)}/network/restart`)).data;

/* Draft */

export function draftFrom(data: Pick<NetworkResponse, 'servers' | 'priorities' | 'forced_hosts'>): Draft {
    return {
        servers: data.servers.map((row) => ({
            name: row.name,
            address: row.address,
            motd: row.motd,
            restricted: row.restricted,
            was: row.name,
        })),
        priorities: [...data.priorities],
        forced_hosts: data.forced_hosts.map((forced) => ({ ...forced })),
    };
}

/** What would be written, without the bookkeeping: equal for two drafts that save the same file. */
const essence = (draft: Draft) =>
    JSON.stringify([
        draft.servers.map((server) => [server.name, server.address, server.motd, server.restricted]),
        draft.priorities,
        draft.forced_hosts.map((forced) => [forced.host.toLowerCase(), forced.server]),
    ]);

export const sameDraft = (a: Draft, b: Draft) => essence(a) === essence(b);

/** How many things differ from the saved file: added, removed and changed entries, plus list changes. */
export function countChanges(saved: Draft, draft: Draft): number {
    const before = new Map(saved.servers.map((server) => [server.name, server]));
    const after = new Map(draft.servers.map((server) => [server.was ?? server.name, server]));
    let count = 0;
    for (const server of draft.servers) {
        const old = server.was === null ? undefined : before.get(server.was);
        if (
            !old ||
            old.name !== server.name ||
            old.address !== server.address ||
            old.motd !== server.motd ||
            old.restricted !== server.restricted
        )
            count++;
    }
    for (const name of before.keys()) if (!after.has(name)) count++;
    if (JSON.stringify(saved.priorities) !== JSON.stringify(draft.priorities)) count++;
    if (JSON.stringify(saved.forced_hosts) !== JSON.stringify(draft.forced_hosts)) count++;
    return count;
}

export function moveItem<T>(list: T[], index: number, delta: -1 | 1): T[] {
    const target = index + delta;
    if (target < 0 || target >= list.length) return list;
    const next = [...list];
    [next[index], next[target]] = [next[target], next[index]];
    return next;
}

/** Renames a backend in the priority list and the forced hosts too. */
export function renameServer(draft: Draft, from: string, to: string): Draft {
    if (from === to) return draft;
    return {
        ...draft,
        priorities: draft.priorities.map((name) => (name === from ? to : name)),
        forced_hosts: draft.forced_hosts.map((forced) => (forced.server === from ? { ...forced, server: to } : forced)),
    };
}

/** A BungeeCord name from a panel server name: "The Kingdom" becomes "TheKingdom", unique among the taken names. */
export function suggestName(panelName: string, taken: string[]): string {
    const cleaned = panelName.replace(/[^A-Za-z0-9_-]/g, '');
    // A name of digits only is not accepted.
    const base = (/^\d*$/.test(cleaned) ? `server${cleaned}` : cleaned).slice(0, 32);
    const used = new Set(taken.map((name) => name.toLowerCase()));
    if (!used.has(base.toLowerCase())) return base;
    for (let n = 2; n < 1000; n++) {
        const suffix = String(n);
        const candidate = base.slice(0, 32 - suffix.length) + suffix;
        if (!used.has(candidate.toLowerCase())) return candidate;
    }
    return base;
}

/* Validation: the same rules the panel applies, so mistakes show before saving. */

export const NAME = /^(?=.*\D)[A-Za-z0-9_-]{1,32}$/;
const HOST = /^[A-Za-z0-9_](?:[A-Za-z0-9._-]{0,251}[A-Za-z0-9_])?$/;

export function parseAddress(address: string): { host: string; port: number } | null {
    const text = address.trim();
    const bracketed = /^\[([0-9A-Fa-f:.]+)\]:(\d{1,5})$/.exec(text);
    const plain = bracketed ? null : /^([^:\s]+):(\d{1,5})$/.exec(text);
    const match = bracketed ?? plain;
    if (!match) return null;
    if (bracketed ? !match[1].includes(':') : !HOST.test(match[1])) return null;
    const port = Number(match[2]);
    return port >= 1 && port <= 65535 ? { host: bracketed ? `[${match[1].toLowerCase()}]` : match[1], port } : null;
}

/** Forced hosts with a host that is not a name or is listed twice. */
export function badForcedHosts(hosts: ForcedHost[]): number[] {
    const seen = new Set<string>();
    const bad: number[] = [];
    hosts.forEach((forced, index) => {
        const host = forced.host.trim().toLowerCase();
        if (host === '') return;
        if (!isHostName(host) || seen.has(host)) bad.push(index);
        seen.add(host);
    });
    return bad;
}

export const isHostName = (host: string) => host.length <= 253 && HOST.test(host);

export interface EntryErrors {
    name?: string;
    address?: string;
    motd?: string;
}

/** Problems with one entry. "others" are the names of the other entries. */
export function validateEntry(entry: Pick<Entry, 'name' | 'address' | 'motd'>, others: string[]): EntryErrors {
    const errors: EntryErrors = {};
    if (!NAME.test(entry.name))
        errors.name = 'Use letters, numbers, dashes and underscores, up to 32 characters, with at least one that is not a digit.';
    else if (others.includes(entry.name)) errors.name = 'Another server already has this name.';
    if (parseAddress(entry.address) === null) errors.address = 'Use host:port, for example localhost:25566. The port is 1 to 65535.';
    if (/[\p{Cc}\p{Zl}\p{Zp}]/u.test(entry.motd)) errors.motd = 'No line breaks or control characters.';
    else if (entry.motd.length > 256) errors.motd = 'At most 256 characters.';
    return errors;
}

/** A description without colour codes: § and & followed by a code, hex colours included. */
export function plainMotd(motd: string): string {
    return motd
        .replace(/[§&]#[0-9a-fA-F]{6}/g, '')
        .replace(/[§&][0-9a-fk-orxA-FK-ORX]/g, '')
        .replace(/§/g, '')
        .replace(/[\s\p{Cc}\p{Zl}\p{Zp}]+/gu, ' ')
        .trim();
}

export const BADGES: Record<string, string> = {
    invalid_address: 'Invalid address',
    self: 'Points at the proxy',
    no_server: 'No panel server',
    not_minecraft: 'Not Minecraft',
    proxy: 'Is a proxy',
    duplicate_port: 'Duplicate port',
    external: 'Other host',
};

export function problemCount(problems: Problem[]): number {
    return problems.filter((problem) => problem.level !== 'info').length;
}
