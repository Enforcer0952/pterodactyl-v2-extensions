import type { PropertyDef, PropertyEdit, ServerState } from './api';

/** Whether this server is a Minecraft Java server: not Bedrock, not a proxy. */
export function isJavaServer(tags: string[] | null | undefined, features: string[] | null | undefined): boolean {
    const t = (tags ?? []).map((tag) => tag.toLowerCase());
    const f = (features ?? []).map((feature) => feature.toLowerCase());
    return (t.includes('minecraft') || f.includes('eula')) && !t.includes('proxy') && !t.includes('bedrock');
}

export const isStopped = (state: ServerState) => state === 'offline';

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

export function formatWhen(iso: string | null | undefined): string {
    if (!iso) return '';
    const date = new Date(iso);
    return Number.isNaN(date.getTime()) ? '' : date.toLocaleString();
}

/** "20261013-120000" as "2026-10-13 12:00:00". */
export function formatStamp(stamp: string): string {
    const match = /^(\d{4})(\d{2})(\d{2})-(\d{2})(\d{2})(\d{2})$/.exec(stamp);
    return match ? `${match[1]}-${match[2]}-${match[3]} ${match[4]}:${match[5]}:${match[6]}` : stamp;
}

/** A problem with a value typed into the form, or null when it is fine. Mirrors the panel's own checks. */
export function validateProperty(def: PropertyDef, value: string): string | null {
    if (value.length > 2000) return 'Too long.';
    // Line breaks are fine (the motd uses them); other control characters are not.
    if (/[\u0000-\u0009\u000b-\u001f\u007f-\u009f\u2028\u2029]/.test(value)) return 'Contains characters that cannot be stored.';
    switch (def.type) {
        case 'bool':
            return value === 'true' || value === 'false' ? null : 'Must be on or off.';
        case 'int': {
            if (!/^-?\d{1,10}$/.test(value)) return 'Enter a whole number.';
            const number = Number(value);
            if (def.min !== undefined && number < def.min) return `At least ${def.min}.`;
            if (def.max !== undefined && number > def.max) return `At most ${def.max}.`;
            return null;
        }
        case 'select':
            if (def.key === 'level-type') return /^[A-Za-z0-9_:./-]{1,100}$/.test(value) ? null : 'Not a valid world type.';
            return def.options?.some((option) => option.value === value) ? null : 'Pick one of the listed values.';
        default:
            break;
    }
    if (def.key === 'level-name') {
        const ok = /^[\p{L}\p{N}_][\p{L}\p{N}_ .-]{0,99}$/u.test(value) && !value.includes('..') && !/[ .]$/.test(value);
        return ok ? null : 'Use letters, digits, spaces, dots, dashes and underscores.';
    }
    if (def.key === 'level-seed' && (value.includes('\n') || value.length > 100)) return 'One line, at most 100 characters.';
    return null;
}

/** Whether a key can be added in the All properties table. */
export const validKey = (key: string) => /^[A-Za-z0-9._-]{1,100}$/.test(key);

/**
 * The edits that turn the saved entries into the draft. Keys that equal their saved value
 * are left out, so only changed lines are rewritten.
 */
export function buildEdits(saved: Record<string, string>, draft: Record<string, string | null>): PropertyEdit[] {
    const edits: PropertyEdit[] = [];
    for (const [key, value] of Object.entries(draft)) {
        if (value === null) {
            if (key in saved) edits.push({ key, remove: true });
        } else if (saved[key] !== value) {
            edits.push({ key, value });
        }
    }
    return edits;
}

/** The folders a reset deletes, as a sentence for the confirm dialog. */
export function foldersSentence(folders: string[]): string {
    return folders.length === 1 ? folders[0] : `${folders.slice(0, -1).join(', ')} and ${folders[folders.length - 1]}`;
}
