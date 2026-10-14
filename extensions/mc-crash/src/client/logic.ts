import type { Culprit, IndexProgress, JarRef, Severity } from './api';

/** Whether this is a Java server or proxy. Bedrock has no crash reports like these. */
export function isJavaOrProxy(tags: string[] | null | undefined, features: string[] | null | undefined): boolean {
    const t = (tags ?? []).map((tag) => tag.toLowerCase());
    const f = (features ?? []).map((feature) => feature.toLowerCase());
    const java = t.includes('minecraft') || f.includes('eula');
    const proxy = ['proxy', 'bungeecord', 'velocity', 'waterfall'].some((tag) => t.includes(tag));
    return (java || proxy) && !t.includes('bedrock');
}

export function formatBytes(bytes: number | null | undefined): string {
    if (bytes === null || bytes === undefined) return '';
    if (bytes < 1024) return `${bytes} B`;
    const units = ['KB', 'MB', 'GB', 'TB'];
    let value = bytes / 1024;
    let unit = 0;
    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit++;
    }
    return `${value >= 100 ? Math.round(value) : value.toFixed(1)} ${units[unit]}`;
}

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

/**
 * A server-local time such as "2026-10-12T21:30:14" as "12 Oct 2026, 21:30". The server writes no time zone, so none is
 * applied. A bare "21:30:14" (a log without a known date) stays a time.
 */
export function formatWhen(value: string | null | undefined): string {
    if (!value) return '';
    const full = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/.exec(value);
    if (full) {
        const month = MONTHS[Number(full[2]) - 1];
        return month ? `${Number(full[3])} ${month} ${full[1]}, ${full[4]}:${full[5]}` : value;
    }
    const time = /^(\d{2}):(\d{2}):\d{2}$/.exec(value);
    return time ? `${time[1]}:${time[2]}` : value;
}

export const SEVERITY_LABEL: Record<Severity, string> = { crash: 'Crash', error: 'Error', warning: 'Warning', info: 'Info' };

/** Panel theme tokens only: colours such as green-500 do not exist in the panel theme. */
export const SEVERITY_CLASS: Record<Severity, string> = {
    crash: 'mccr:bg-destructive/15 mccr:text-destructive',
    error: 'mccr:bg-destructive/15 mccr:text-destructive',
    warning: 'mccr:bg-warning/15 mccr:text-warning',
    info: 'mccr:bg-muted mccr:text-muted-foreground',
};

/** "Confirmed" or "Likely", and a plain sentence for it. A guess is never worded as a fact. */
export function tierLabel(culprit: Culprit): string {
    return culprit.tier === 'confirmed' ? 'Confirmed' : 'Likely';
}

export function jarLabel(jar: JarRef): string {
    return jar.version ? `${jar.name} ${jar.version}` : jar.name;
}

/** "A", "A or B", "A, B or C". */
export function jarNames(culprit: Culprit): string {
    const names = culprit.jars.map((jar) => jar.name);
    if (names.length <= 1) return names[0] ?? '';
    return `${names.slice(0, -1).join(', ')} or ${names[names.length - 1]}`;
}

/** The mc-content page that manages this kind of jar. */
export function contentScreen(jar: JarRef): 'plugins' | 'mods' | null {
    if (!jar.installed || jar.kind === null) return null;
    return jar.kind === 'mod' ? 'mods' : 'plugins';
}

/** Whether the lists should be fetched again because the server is still reading jars or files. */
export function isBusy(data: { pending: number; index: IndexProgress } | undefined): boolean {
    return data !== undefined && (data.pending > 0 || data.index.pending > 0);
}

/** "3 of 12 plugins and mods read". */
export function indexProgress(index: IndexProgress): string {
    return `${index.total - index.pending} of ${index.total} plugins and mods read`;
}

/** "4012 times", "once". */
export function timesText(count: number): string {
    return count === 1 ? 'once' : `${count.toLocaleString('en')} times`;
}

/** The text above the Share confirmation. */
export function shareWarning(name: string): string {
    return `This uploads ${name} to mclo.gs. Anyone who has the link can read it, and it stays public until mclo.gs removes it. Reports and logs can contain player names, IP addresses and other private details. Only continue if that is fine.`;
}
