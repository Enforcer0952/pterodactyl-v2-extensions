/** Human-readable file size: 1.5 MB. */
export function formatBytes(bytes: number): string {
    if (!Number.isFinite(bytes) || bytes <= 0) return '';
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

/** A date for lists; empty when unknown. */
export function formatDate(value: string | Date | null | undefined): string {
    if (!value) return '';
    const date = value instanceof Date ? value : new Date(value);
    return Number.isNaN(date.getTime()) ? '' : date.toLocaleString();
}

/** 1234567 becomes 1.2M. */
export function formatCount(value: number): string {
    if (!Number.isFinite(value) || value < 0) return '';
    if (value < 1000) return String(value);
    if (value < 1_000_000) return `${(value / 1000).toFixed(value < 10_000 ? 1 : 0).replace(/\.0$/, '')}K`;
    return `${(value / 1_000_000).toFixed(value < 10_000_000 ? 1 : 0).replace(/\.0$/, '')}M`;
}

/** Compare two x.y.z versions: negative, zero or positive. */
export function compareVersions(a: string, b: string): number {
    const x = a.split('.').map(Number);
    const y = b.split('.').map(Number);
    for (let i = 0; i < 3; i++) {
        const diff = (x[i] ?? 0) - (y[i] ?? 0);
        if (diff !== 0) return diff;
    }
    return 0;
}

/** The text of a world backup's folder kind. */
export function backupKind(kind: string): string {
    return kind === 'before-restore' ? 'Before a restore' : kind === 'before-delete' ? 'Before a delete' : 'Backup';
}

/** The backup's time from its folder name stamp (YYYYMMDD-HHMMSS, UTC). */
export function parseFolderStamp(id: string): Date | null {
    const match = /(\d{4})(\d{2})(\d{2})-(\d{2})(\d{2})(\d{2})$/.exec(id);
    if (!match) return null;
    const [year, month, day, hour, minute, second] = match.slice(1).map(Number);
    const date = new Date(Date.UTC(year, month - 1, day, hour, minute, second));
    const same =
        date.getUTCFullYear() === year && date.getUTCMonth() === month - 1 && date.getUTCDate() === day && date.getUTCHours() === hour;
    return same && date.getUTCMinutes() === minute && date.getUTCSeconds() === second ? date : null;
}

/** Whether an error message is the server asking to confirm adding the first permitted player. */
export function needsPermitConfirm(message: string): boolean {
    return message.includes('permitted list is empty');
}
