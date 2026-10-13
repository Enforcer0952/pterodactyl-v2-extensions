import type { JavaImage, JavaStatus, VersionEntry } from './api';

export function formatBytes(bytes: number | null | undefined): string {
    if (bytes === null || bytes === undefined) return '';
    if (bytes < 1024) return `${bytes} B`;
    const units = ['KB', 'MB', 'GB'];
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

export function sourceLabel(source: 'log' | 'history' | 'folder' | null): string {
    switch (source) {
        case 'log':
            return 'from the log of the last start';
        case 'history':
            return 'from version_history.json';
        case 'folder':
            return 'from the versions folder';
        default:
            return '';
    }
}

export function javaName(image: Pick<JavaImage, 'java'> | null | undefined): string {
    return image ? `Java ${image.java}` : 'Unknown';
}

export const JAVA_TEXT: Record<
    JavaStatus,
    { tone: 'success' | 'info' | 'danger'; text: (current: number | null, recommended: number | null, version: string | null) => string }
> = {
    ok: {
        tone: 'success',
        text: (current, _recommended, version) => `Java ${current} is the right version for ${version}.`,
    },
    works: {
        tone: 'info',
        text: (current, recommended, version) => `Java ${current} can run ${version}, but Java ${recommended} is the one it is made for.`,
    },
    too_old: {
        tone: 'danger',
        text: (current, recommended, version) =>
            `Java ${current} is too old for ${version}. The server will not start until you switch to Java ${recommended ?? 'a newer version'}.`,
    },
    unknown: {
        tone: 'info',
        text: () => 'The Java version of this server could not be compared with its Minecraft version.',
    },
};

/** Options for the version picker: the version being run is marked. */
export function versionOptions(versions: VersionEntry[], current: string | null): { value: string; label: string; textLabel: string }[] {
    return versions.map((entry) => {
        const notes = [entry.id === current ? 'current' : null, entry.stable ? null : 'snapshot'].filter(Boolean).join(', ');
        const label = notes ? `${entry.id} (${notes})` : entry.id;
        return { value: entry.id, label, textLabel: label };
    });
}

/** The version to preselect after the list changes: the one already chosen if it is still offered. */
export function keepSelection(selected: string, versions: VersionEntry[]): string {
    return versions.some((entry) => entry.id === selected) ? selected : '';
}
