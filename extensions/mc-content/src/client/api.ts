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
    /** What the server's files say it runs, when they tell. */
    platform: Platform | null;
}

export interface Platform {
    loader: string;
    kind: ContentKind;
}

export interface PlatformResponse {
    platform: Platform | null;
    /** The content pages this server should show. */
    kinds: ContentKind[];
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
    dependencies?: { project_id: string | null; version_id: string | null; type: string }[];
    /** Markdown, only on the versions endpoint. */
    changelog?: string;
}

export interface ProjectDetails {
    id: string;
    slug: string;
    project_type: string;
    title: string;
    description: string;
    body: string;
    icon_url: string | null;
    downloads: number;
    followers: number;
    categories: string[];
    loaders: string[];
    client_side: string;
    server_side: string;
    published: string | null;
    updated: string | null;
    license: { id: string; name: string; url: string | null };
    links: { label: string; url: string }[];
    gallery: { url: string; raw_url: string; title: string | null; description: string | null; featured: boolean }[];
    /** Version number installed on this server, if any. */
    installed: string | null;
    installed_projects: string[];
}

export async function getProject(server: string, kind: ContentKind, project: string): Promise<ProjectDetails> {
    const { data } = await http.get<ProjectDetails>(`${base(server, kind)}/projects/${encodeURIComponent(project)}`);
    return data;
}

export async function getProjectNames(
    server: string,
    kind: ContentKind,
    ids: string[],
): Promise<Record<string, { title: string; slug: string; icon_url: string | null }>> {
    if (ids.length === 0) return {};
    const { data } = await http.get<{ data: Record<string, { title: string; slug: string; icon_url: string | null }> }>(
        `${base(server, kind)}/project-names`,
        { params: { ids } },
    );
    return Array.isArray(data.data) ? {} : data.data;
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
    platform: (server: string) => ['mc-content', server, 'platform'] as const,
    context: (server: string, kind: ContentKind) => ['mc-content', server, kind, 'context'] as const,
    search: (server: string, kind: ContentKind, params: unknown) => ['mc-content', server, kind, 'search', params] as const,
    project: (server: string, kind: ContentKind, project: string) => ['mc-content', server, kind, 'project', project] as const,
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

export async function getPlatform(server: string): Promise<PlatformResponse> {
    const { data } = await http.get<PlatformResponse>(`/api/client/servers/${server}/extensions/mc-content/platform`);
    return data;
}
