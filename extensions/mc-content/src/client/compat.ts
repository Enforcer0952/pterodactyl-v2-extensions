import type { ContentKind, Platform } from './api';

/** Loaders whose builds also run on the given server loader (mirrors the backend). */
export function compatibleLoaders(loader: string): string[] {
    switch (loader) {
        case 'purpur':
            return ['purpur', 'paper', 'spigot', 'bukkit'];
        case 'paper':
            return ['paper', 'spigot', 'bukkit'];
        case 'spigot':
            return ['spigot', 'bukkit'];
        case 'quilt':
            return ['quilt', 'fabric'];
        case 'waterfall':
            return ['waterfall', 'bungeecord'];
        default:
            return [loader];
    }
}

/**
 * Why installing is not possible on this page, or null when it is. The server's
 * files decide: a Fabric server cannot take plugins, and a Paper server cannot
 * take Forge mods.
 */
export function installBlockedReason(platform: Platform | null | undefined, kind: ContentKind, loader: string | null): string | null {
    if (!platform) return null;
    if (platform.kind !== kind) {
        return `This server loads ${platform.kind}, not ${kind}.`;
    }
    if (loader && !compatibleLoaders(platform.loader).includes(loader)) {
        return `This server runs ${platform.loader}. Pick a compatible loader to install.`;
    }
    return null;
}
