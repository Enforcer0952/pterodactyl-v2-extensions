import type { ExtensionConfig } from '@pterodactyl/sdk';

let config: ExtensionConfig = {};

export function setConfig(value: ExtensionConfig): void {
    config = value;
}

export type SkinSource = 'mc-heads' | 'crafatar' | 'off';
export type ProfileLink = 'namemc' | 'laby' | 'mcuuid';

export function skinSource(): SkinSource {
    return config.skin_source === 'crafatar' || config.skin_source === 'off' ? config.skin_source : 'mc-heads';
}

export function offlineSkins(): 'by_name' | 'default' {
    return config.offline_skins === 'default' ? 'default' : 'by_name';
}

export function profileLinks(): ProfileLink[] {
    const value = config.profile_links;
    const list = Array.isArray(value) ? value : ['namemc', 'laby'];
    return list.filter((entry): entry is ProfileLink => entry === 'namemc' || entry === 'laby' || entry === 'mcuuid');
}

export function linkBehaviour(): 'popup' | 'page' {
    return config.link_behaviour === 'page' ? 'page' : 'popup';
}
