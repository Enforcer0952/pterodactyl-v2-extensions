import type { ExtensionConfig } from '@pterodactyl/sdk';

let config: ExtensionConfig = {};

export function setConfig(value: ExtensionConfig): void {
    config = value;
}

export function resultsPerPage(): number {
    return typeof config.results_per_page === 'number' ? config.results_per_page : 20;
}
