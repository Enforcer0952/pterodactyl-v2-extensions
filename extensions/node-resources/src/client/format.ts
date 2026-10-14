/** Sizes and times as people read them. Sizes count in steps of 1024, like the panel's MB limits. */

export const MB = 1_048_576;

const UNITS = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];

export function formatBytes(bytes: number | null | undefined): string {
    if (bytes === null || bytes === undefined || !Number.isFinite(bytes)) return 'Unknown';
    let value = Math.max(0, bytes);
    let unit = 0;
    while (value >= 1024 && unit < UNITS.length - 1) {
        value /= 1024;
        unit++;
    }
    const digits = unit === 0 || value >= 100 ? 0 : 1;
    return `${Number(value.toFixed(digits))} ${UNITS[unit]}`;
}

/** A limit of 0 means no limit. */
export function formatLimit(bytes: number): string {
    return bytes === 0 ? 'Unlimited' : formatBytes(bytes);
}

export function formatMb(mb: number): string {
    return formatBytes(mb * MB);
}

export function formatCpu(percent: number | null | undefined): string {
    return percent === null || percent === undefined ? 'Unknown' : `${Number(percent.toFixed(percent >= 100 ? 0 : 1))}%`;
}

export function formatCpuLimit(percent: number): string {
    return percent === 0 ? 'Unlimited' : formatCpu(percent);
}

export function percentOf(part: number | null | undefined, whole: number | null | undefined): number | null {
    if (part === null || part === undefined || !whole) return null;
    return Math.min(999, Math.max(0, (part / whole) * 100));
}

/** The panel's own thresholds for node usage: up to 75 is fine, over 90 is bad. */
export function levelFor(percent: number | null): 'success' | 'warning' | 'destructive' | 'muted' {
    if (percent === null) return 'muted';
    if (percent > 90) return 'destructive';
    if (percent > 75) return 'warning';
    return 'success';
}

export function formatDays(days: number): string {
    if (days < 1) return 'less than a day';
    const rounded = Math.round(days);
    return rounded === 1 ? '1 day' : `${rounded} days`;
}

export function ago(iso: string | null | undefined, now: number = Date.now()): string {
    if (!iso) return 'never';
    const seconds = Math.max(0, Math.round((now - new Date(iso).getTime()) / 1000));
    if (seconds < 45) return 'just now';
    const minutes = Math.round(seconds / 60);
    if (minutes < 60) return `${minutes} min ago`;
    const hours = Math.round(minutes / 60);
    if (hours < 48) return `${hours} h ago`;
    return `${Math.round(hours / 24)} days ago`;
}

export function formatTime(iso: string | null | undefined): string {
    return iso ? new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '-';
}

export function formatDateTime(iso: string | null | undefined): string {
    return iso ? new Date(iso).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' }) : '-';
}

/** Axis label for a chart point. */
export function axisTime(seconds: number, rangeSeconds: number): string {
    const date = new Date(seconds * 1000);
    return rangeSeconds <= 172_800
        ? date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
        : date.toLocaleDateString([], { day: 'numeric', month: 'short' });
}

/** The panel counts a node's memory and disk in MB. */
export interface FitResource {
    host_total: number | null;
    host_free: number | null;
    panel_limit: number;
    unallocated: number;
    committed: number;
    unlimited: number;
}

export interface FitProblem {
    id: 'host-free' | 'allocation' | 'overcommit';
    text: string;
}

/**
 * Whether a limit fits on the node. `askedMb` is what is typed in the form (0 = unlimited),
 * `currentBytes` what the server already has allocated (0 for a new server), so raising a
 * limit only has to find room for the difference.
 */
export function checkFit(label: 'memory' | 'disk', askedMb: number, currentBytes: number, resource: FitResource): FitProblem[] {
    if (!Number.isFinite(askedMb) || askedMb <= 0) return [];

    const extra = Math.max(0, askedMb * MB - currentBytes);
    if (extra === 0) return [];

    const noun = label === 'memory' ? 'memory' : 'disk space';
    const problems: FitProblem[] = [];

    if (resource.host_free !== null && extra > resource.host_free) {
        problems.push({
            id: 'host-free',
            text: `This needs ${formatBytes(extra)} more ${noun}, but only ${formatBytes(resource.host_free)} is free on the host right now.`,
        });
    }
    if (extra > resource.unallocated) {
        problems.push({
            id: 'allocation',
            text: `This needs ${formatBytes(extra)} more ${noun}, but the node's limit leaves ${formatBytes(resource.unallocated)} to give out.`,
        });
    }
    if (resource.host_total !== null && resource.committed + extra > resource.host_total && resource.committed <= resource.host_total) {
        problems.push({
            id: 'overcommit',
            text: `Servers could then use up to ${formatBytes(resource.committed + extra)} of ${noun}, but the host has ${formatBytes(resource.host_total)}.`,
        });
    }

    return problems;
}
