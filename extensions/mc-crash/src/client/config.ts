import type { ExtensionConfig } from '@pterodactyl/sdk';

let config: ExtensionConfig = {};

export function setConfig(value: ExtensionConfig): void {
    config = value;
}

/** The admin setting that turns the Share button on or off. */
export function sharingEnabled(): boolean {
    return config.sharing !== 'off';
}
