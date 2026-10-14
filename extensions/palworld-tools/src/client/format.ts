/** Human-readable file size: 1.5 MB. */
export function formatBytes(bytes: number | null | undefined): string {
    if (bytes === null || bytes === undefined || !Number.isFinite(bytes) || bytes < 0) return '';
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

/** A backup's time from its file name stamp (YYYYMMDD-HHMMSS, UTC). */
export function parseStamp(stamp: string): Date | null {
    const match = /^(\d{4})(\d{2})(\d{2})-(\d{2})(\d{2})(\d{2})$/.exec(stamp);
    if (!match) return null;
    const [year, month, day, hour, minute, second] = match.slice(1).map(Number);
    const date = new Date(Date.UTC(year, month - 1, day, hour, minute, second));
    // Reject dates that rolled over, such as month 13.
    const same =
        date.getUTCFullYear() === year && date.getUTCMonth() === month - 1 && date.getUTCDate() === day && date.getUTCHours() === hour;
    return same && date.getUTCMinutes() === minute && date.getUTCSeconds() === second ? date : null;
}

/** A date for lists; empty when unknown. */
export function formatDate(value: string | Date | null | undefined): string {
    if (!value) return '';
    const date = value instanceof Date ? value : new Date(value);
    return Number.isNaN(date.getTime()) ? '' : date.toLocaleString();
}

/** Seconds as "2d 3h", "1h 5m", "4m" or "30s". */
export function formatDuration(seconds: number | null | undefined): string {
    if (seconds === null || seconds === undefined || !Number.isFinite(seconds) || seconds < 0) return '';
    const s = Math.floor(seconds);
    const days = Math.floor(s / 86400);
    const hours = Math.floor((s % 86400) / 3600);
    const minutes = Math.floor((s % 3600) / 60);
    if (days > 0) return `${days}d ${hours}h`;
    if (hours > 0) return `${hours}h ${minutes}m`;
    if (minutes > 0) return `${minutes}m`;
    return `${s}s`;
}

/** A world id is long; show the start of it. */
export function shortId(id: string): string {
    return id.length > 12 ? `${id.slice(0, 8)}…` : id;
}

/** A game snapshot name (2026.10.13-21.05.09) as a date, or the name itself. */
export function snapshotDate(at: string): string {
    return formatDate(at) || at;
}
