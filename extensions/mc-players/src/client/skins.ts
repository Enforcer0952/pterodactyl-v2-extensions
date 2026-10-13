import { offlineSkins, skinSource, type ProfileLink, type SkinSource } from './config';

/** Mojang accounts have version 4 UUIDs; offline-mode servers make version 3 ones from the name. */
export function isPremiumUuid(uuid: string | null | undefined): uuid is string {
    return typeof uuid === 'string' && /^[0-9a-f]{8}-?[0-9a-f]{4}-?4[0-9a-f]{3}-?[89ab][0-9a-f]{3}-?[0-9a-f]{12}$/i.test(uuid);
}

/** Names as they appear in lists; Floodgate/Geyser players carry a leading dot. */
function cleanName(name: string): string {
    return name.replace(/^\./, '');
}

/**
 * Who to look the skin up as: the real account UUID, the name (offline mode, when
 * allowed), or null for the default skin.
 */
function lookup(source: SkinSource, name: string, uuid: string | null | undefined): string | null {
    if (source === 'off') return null;
    if (isPremiumUuid(uuid)) return uuid.replace(/-/g, '');
    if (offlineSkins() === 'default') return null;
    // crafatar only knows UUIDs, so a name lookup is impossible there.
    return source === 'mc-heads' ? cleanName(name) : null;
}

/** Full skin texture for the 3D viewer, or null when no outside request may or can be made. */
export function skinUrl(name: string, uuid?: string | null, source: SkinSource = skinSource()): string | null {
    const who = lookup(source, name, uuid);
    switch (source) {
        case 'off':
            return null;
        case 'crafatar':
            return `https://crafatar.com/skins/${who ?? '00000000000000000000000000000000'}?default=MHF_Steve`;
        default:
            return `https://mc-heads.net/skin/${encodeURIComponent(who ?? 'MHF_Steve')}`;
    }
}

/** Square head picture, or null when skins are off. */
export function headUrl(name: string, uuid: string | null | undefined, size: number, source: SkinSource = skinSource()): string | null {
    const who = lookup(source, name, uuid);
    switch (source) {
        case 'off':
            return null;
        case 'crafatar':
            return `https://crafatar.com/avatars/${who ?? '00000000000000000000000000000000'}?size=${size}&overlay&default=MHF_Steve`;
        default:
            return `https://mc-heads.net/avatar/${encodeURIComponent(who ?? 'MHF_Steve')}/${size}`;
    }
}

const LINKS: Record<ProfileLink, { label: string; url: (name: string) => string }> = {
    namemc: { label: 'NameMC', url: (name) => `https://namemc.com/profile/${encodeURIComponent(name)}` },
    laby: { label: 'Laby.net', url: (name) => `https://laby.net/@${encodeURIComponent(name)}` },
    mcuuid: { label: 'mcuuid.net', url: (name) => `https://mcuuid.net/?q=${encodeURIComponent(name)}` },
};

/** Outside profile pages. Only for real accounts: an offline-mode name may belong to someone else. */
export function profileUrls(name: string, links: ProfileLink[]): { label: string; url: string }[] {
    return links.map((link) => ({ label: LINKS[link].label, url: LINKS[link].url(cleanName(name)) }));
}
