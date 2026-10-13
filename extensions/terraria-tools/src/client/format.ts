/** Human-readable file size: 1.5 MB. */
export function formatBytes(bytes: number): string {
    if (!Number.isFinite(bytes) || bytes < 0) return '';
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

/** Console join and leave lines: "Anton has joined." and "Anton has left." */
export function consolePresence(line: string): 'joined' | 'left' | null {
    // eslint-disable-next-line no-control-regex
    const text = line.replace(/\u001b\[[0-9;?]*[A-Za-z]/g, '').trim();
    if (/\S.* has joined\.?$/i.test(text)) return 'joined';
    if (/\S.* has left\.?$/i.test(text)) return 'left';
    return null;
}

/** Turns a skipped-variables result into a sentence. */
export function skippedMessage(variables: { skipped: string[] } | undefined): string {
    const names = variables?.skipped ?? [];
    if (names.length === 0) return '';
    return `The startup variables ${names.join(', ')} were not changed. If your egg fills serverconfig.txt from them when the server starts, change them on the Startup page too.`;
}
