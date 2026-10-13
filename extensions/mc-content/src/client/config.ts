import type { ExtensionConfig } from '@pterodactyl/sdk';

let config: ExtensionConfig = {};

export function setConfig(value: ExtensionConfig): void {
    config = value;
}

export function resultsPerPage(): number {
    return typeof config.results_per_page === 'number' ? config.results_per_page : 20;
}

/** Whether clicking a project opens the in-panel popup (default) or modrinth.com. */
export function projectLinks(): 'popup' | 'modrinth' {
    return config.project_links === 'modrinth' ? 'modrinth' : 'popup';
}
