export function formatCount(value: number): string {
    if (value >= 1_000_000) return `${(value / 1_000_000).toFixed(1).replace(/\.0$/, '')}M`;
    if (value >= 1_000) return `${(value / 1_000).toFixed(1).replace(/\.0$/, '')}k`;
    return String(value);
}

export function formatBytes(value: number): string {
    if (value >= 1024 * 1024) return `${(value / (1024 * 1024)).toFixed(1)} MiB`;
    if (value >= 1024) return `${Math.round(value / 1024)} KiB`;
    return `${value} B`;
}

export function formatDate(value: string | null): string {
    if (!value) return '';
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? '' : date.toLocaleDateString();
}

const LOADER_LABELS: Record<string, string> = {
    paper: 'Paper',
    purpur: 'Purpur',
    folia: 'Folia',
    spigot: 'Spigot',
    bukkit: 'Bukkit',
    sponge: 'Sponge',
    velocity: 'Velocity',
    waterfall: 'Waterfall',
    bungeecord: 'BungeeCord',
    fabric: 'Fabric',
    forge: 'Forge',
    neoforge: 'NeoForge',
    quilt: 'Quilt',
};

export function loaderLabel(loader: string): string {
    return LOADER_LABELS[loader] ?? loader;
}

/** Collapse a long list of game versions into something short like "1.20.1 – 1.21.4". */
export function versionRange(versions: string[]): string {
    if (versions.length === 0) return '';
    if (versions.length <= 3) return versions.join(', ');
    return `${versions[0]} – ${versions[versions.length - 1]}`;
}

/** Proxy plugins do not depend on a Minecraft version. */
export function isProxyLoader(loader: string | null): boolean {
    return loader === 'velocity' || loader === 'waterfall' || loader === 'bungeecord';
}
