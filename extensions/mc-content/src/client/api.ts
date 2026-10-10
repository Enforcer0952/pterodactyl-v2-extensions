import { http } from '@pterodactyl/sdk';

export type ContentKind = 'plugins' | 'mods';

export interface ContentContext {
    kind: ContentKind;
    directory: string;
    loaders: string[];
    detected_loader: string | null;
    detected_game_version: string | null;
    game_version_source: 'saved' | 'startup' | 'files' | null;
    game_versions: string[];
    egg: string | null;
}

export interface SearchHit {
    project_id: string;
    slug: string;
    title: string;
    description: string;
    author: string;
    downloads: number;
    icon_url: string | null;
    categories: string[];
    date_modified: string | null;
    installed: InstalledVersion | null;
}

export interface InstalledVersion {
    filename: string;
    version_id: string;
    version_number: string;
}

export interface SearchResult {
    hits: SearchHit[];
    total: number;
    offset: number;
    limit: number;
}

export interface ProjectVersion {
    id: string;
    project_id: string;
    name: string;
    version_number: string;
    version_type: 'release' | 'beta' | 'alpha' | string;
    game_versions: string[];
    loaders: string[];
    date_published: string | null;
    downloads: number;
    file: { filename: string; size: number } | null;
}

export interface InstalledFile {
    filename: string;
    size: number;
    modified: string | null;
    disabled: boolean;
    tracked: boolean;
    project_id: string | null;
    title: string | null;
    version_number: string | null;
    icon_url: string | null;
    update: { id: string; version_number: string } | null;
}

export interface InstallResult {
    installed: { filename: string; project_id: string; title: string; version_number: string }[];
    skipped_dependencies: { project_id: string | null; reason: string }[];
}

export interface Filters {
    loader: string;
    gameVersion: string | null;
}

const base = (server: string, kind: ContentKind) => `/api/client/servers/${server}/extensions/mc-content/${kind}`;

const filterParams = (filters: Filters) => ({
    loader: filters.loader,
    ...(filters.gameVersion ? { game_version: filters.gameVersion } : {}),
});

export const keys = {
    context: (server: string, kind: ContentKind) => ['mc-content', server, kind, 'context'] as const,
    search: (server: string, kind: ContentKind, params: unknown) => ['mc-content', server, kind, 'search', params] as const,
    versions: (server: string, kind: ContentKind, project: string, filters: Filters) =>
        ['mc-content', server, kind, 'versions', project, filters] as const,
    installed: (server: string, kind: ContentKind) => ['mc-content', server, kind, 'installed'] as const,
};

export async function getContext(server: string, kind: ContentKind): Promise<ContentContext> {
    const { data } = await http.get<ContentContext>(`${base(server, kind)}/context`);
    return data;
}

export async function search(
    server: string,
    kind: ContentKind,
    filters: Filters,
    query: string,
    sort: string,
    page: number,
): Promise<SearchResult> {
    const { data } = await http.get<SearchResult>(`${base(server, kind)}/search`, {
        params: { ...filterParams(filters), query, sort, page },
    });
    return data;
}

export interface ProjectVersions {
    data: ProjectVersion[];
    installed: InstalledVersion | null;
}

export async function getVersions(server: string, kind: ContentKind, project: string, filters: Filters): Promise<ProjectVersions> {
    const { data } = await http.get<ProjectVersions>(`${base(server, kind)}/projects/${encodeURIComponent(project)}/versions`, {
        params: filterParams(filters),
    });
    return data;
}

export interface InstalledResponse {
    data: InstalledFile[];
    /** Jars added by hand that are still being identified. */
    pending: number;
}

export async function getInstalled(server: string, kind: ContentKind, filters: Filters): Promise<InstalledResponse> {
    const { data } = await http.get<InstalledResponse>(`${base(server, kind)}/installed`, {
        params: filterParams(filters),
    });
    return { data: data.data, pending: data.pending ?? 0 };
}

export async function install(
    server: string,
    kind: ContentKind,
    versionId: string,
    filters: Filters,
    replace?: string,
): Promise<InstallResult> {
    const { data } = await http.post<InstallResult>(`${base(server, kind)}/install`, {
        version_id: versionId,
        loader: filters.loader,
        game_version: filters.gameVersion,
        replace: replace ?? null,
    });
    return data;
}

export async function uninstall(server: string, kind: ContentKind, filename: string): Promise<void> {
    await http.post(`${base(server, kind)}/uninstall`, { filename });
}

export async function saveGameVersion(server: string, kind: ContentKind, gameVersion: string | null): Promise<void> {
    await http.post(`${base(server, kind)}/preferences`, { game_version: gameVersion });
}
