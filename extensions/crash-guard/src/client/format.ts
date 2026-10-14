import type { EventKind, GuardEvent } from './api';

/** "2 h 5 min", "45 s": how long a run lasted, from the uptime in milliseconds. */
export function formatUptime(ms: number | null): string {
    if (ms === null || ms <= 0) return 'unknown';
    const seconds = Math.floor(ms / 1000);
    if (seconds < 60) return `${seconds} s`;
    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) return `${minutes} min`;
    const hours = Math.floor(minutes / 60);
    if (hours < 24) return minutes % 60 === 0 ? `${hours} h` : `${hours} h ${minutes % 60} min`;
    const days = Math.floor(hours / 24);
    return hours % 24 === 0 ? `${days} d` : `${days} d ${hours % 24} h`;
}

const VIA: Record<string, string> = {
    'went-offline': 'Went offline',
    'uptime-drop': 'Restarted by itself',
    restarted: 'Fell back to starting',
};

export const describeVia = (via: string | null): string => (via !== null && VIA[via]) || 'Crash';

export function describeEvent(event: GuardEvent): { title: string; detail: string | null } {
    const titles: Record<EventKind, string> = {
        crash: event.status === 'pending' ? 'Possible crash' : 'Crash',
        trip: 'Server stopped by Crash Guard',
        blocked: 'Kept off',
        release: 'Started again',
        cleared: 'Cleared',
    };

    if (event.kind === 'crash') {
        return {
            title: titles.crash,
            detail:
                event.status === 'pending'
                    ? `${describeVia(event.via)}. Checking that nobody restarted it.`
                    : `${describeVia(event.via)} after ${formatUptime(event.uptime_before)}`,
        };
    }

    return { title: titles[event.kind], detail: event.note };
}

/** The sentence on the banner and the page. */
export const tripSummary = (crashes: number, minutes: number): string =>
    `Crash Guard stopped this server after ${crashes} crashes in ${minutes} minute${minutes === 1 ? '' : 's'}`;

/** Whether the egg is a Minecraft one, so crashes can link to the Minecraft Crash page. */
export function isMinecraft(tags: readonly string[] | null | undefined, features: readonly string[] | null | undefined): boolean {
    const lower = (list: readonly string[] | null | undefined) => (list ?? []).map((item) => item.toLowerCase());
    return lower(tags).includes('minecraft') || lower(features).includes('eula');
}

export function clampInt(value: string, min: number, max: number, fallback: number): number {
    if (!/^\d{1,9}$/.test(value.trim())) return fallback;
    return Math.max(min, Math.min(max, Number(value)));
}
