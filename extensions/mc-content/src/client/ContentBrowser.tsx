import { useEffect, useMemo, useRef, useState } from 'react';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
    Alert,
    Button,
    Input,
    Select,
    ServerContentBlock,
    Spinner,
    TitledGreyBox,
    httpErrorToHuman,
    toast,
    useCurrentServerRequired,
    useServerPermission,
    type SelectOption,
} from '@pterodactyl/sdk';
import { getContext, getInstalled, keys, saveGameVersion, search, type ContentKind, type Filters, type SearchHit } from './api';
import { projectLinks, resultsPerPage } from './config';
import { formatCount, isProxyLoader, loaderLabel } from './format';
import InstalledList from './InstalledList';
import ProjectIcon from './ProjectIcon';
import ProjectPopup from './ProjectPopup';

const ANY_VERSION = 'any';

const SORT_OPTIONS: SelectOption[] = [
    { value: 'relevance', label: 'Relevance' },
    { value: 'downloads', label: 'Downloads' },
    { value: 'follows', label: 'Followers' },
    { value: 'updated', label: 'Recently updated' },
    { value: 'newest', label: 'Newest' },
];

function useDebounced<T>(value: T, delay: number): T {
    const [debounced, setDebounced] = useState(value);
    useEffect(() => {
        const timer = window.setTimeout(() => setDebounced(value), delay);
        return () => window.clearTimeout(timer);
    }, [value, delay]);
    return debounced;
}

interface Props {
    kind: ContentKind;
    title: string;
}

export default function ContentBrowser({ kind, title }: Props) {
    const server = useCurrentServerRequired();
    const identifier = server.attributes.identifier;
    const canInstall = useServerPermission('file.create');
    const canChangeVersion = useServerPermission(['file.create', 'file.delete']);

    const context = useQuery({ queryKey: keys.context(identifier, kind), queryFn: () => getContext(identifier, kind), staleTime: 60_000 });

    const [tab, setTab] = useState<'browse' | 'installed'>('browse');
    const [loader, setLoader] = useState<string | null>(null);
    const [gameVersion, setGameVersion] = useState<string | null>(null);
    const [versionTouched, setVersionTouched] = useState(false);
    const queryClient = useQueryClient();

    // Remember the chosen version for this server so nobody has to pick it again.
    const rememberVersion = useMutation({
        mutationFn: (value: string | null) => saveGameVersion(identifier, kind, value),
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['mc-content', identifier] }),
        onError: (error) => toast.error(httpErrorToHuman(error)),
    });
    const [query, setQuery] = useState('');
    const [sort, setSort] = useState('relevance');
    const [page, setPage] = useState(1);
    // The project open in the popup, and the tab it opened on.
    const [selected, setSelected] = useState<{ id: string; tab: 'description' | 'versions' } | null>(null);
    const openProject = (id: string, slug: string, tab: 'description' | 'versions' = 'description') => {
        if (projectLinks() === 'modrinth' && tab === 'description') {
            window.open(`https://modrinth.com/project/${encodeURIComponent(slug || id)}`, '_blank', 'noopener,noreferrer');
        } else {
            setSelected({ id, tab });
        }
    };

    // Seed the filters from what the backend detected about this server.
    useEffect(() => {
        if (!context.data) return;
        setLoader((current) => current ?? context.data.detected_loader ?? context.data.loaders[0] ?? null);
        if (!versionTouched) {
            setGameVersion(context.data.detected_game_version);
        }
    }, [context.data, versionTouched]);

    const proxy = isProxyLoader(loader);
    const filters: Filters | null = loader ? { loader, gameVersion: proxy ? null : gameVersion } : null;
    const debouncedQuery = useDebounced(query.trim(), 350);

    useEffect(() => setPage(1), [debouncedQuery, sort, loader, gameVersion]);

    const results = useQuery({
        queryKey: keys.search(identifier, kind, { filters, q: debouncedQuery, sort, page }),
        queryFn: () => search(identifier, kind, filters!, debouncedQuery, sort, page),
        enabled: filters !== null && tab === 'browse',
        placeholderData: keepPreviousData,
        staleTime: 60_000,
    });

    // Start identifying jars that were added by hand as soon as the page opens,
    // so search results can mark them as installed.
    const identifying = useQuery({
        queryKey: [...keys.installed(identifier, kind), filters],
        queryFn: () => getInstalled(identifier, kind, filters!),
        enabled: filters !== null && tab === 'browse',
        refetchInterval: (query) => ((query.state.data?.pending ?? 0) > 0 ? 1500 : false),
    });

    const pending = identifying.data?.pending ?? 0;
    const wasPending = useRef(pending);
    useEffect(() => {
        if (wasPending.current > 0 && pending === 0) {
            void queryClient.invalidateQueries({ queryKey: ['mc-content', identifier, kind, 'search'] });
        }
        wasPending.current = pending;
    }, [pending, identifier, kind, queryClient]);

    const loaderOptions: SelectOption[] = useMemo(
        () => (context.data?.loaders ?? []).map((value) => ({ value, label: loaderLabel(value) })),
        [context.data],
    );

    const versionOptions: SelectOption[] = useMemo(() => {
        const versions = context.data?.game_versions ?? [];
        const list = gameVersion && !versions.includes(gameVersion) ? [gameVersion, ...versions] : versions;
        return [{ value: ANY_VERSION, label: 'Any version' }, ...list.map((value) => ({ value, label: value }))];
    }, [context.data, gameVersion]);

    const totalPages = results.data ? Math.max(1, Math.ceil(results.data.total / (results.data.limit || resultsPerPage()))) : 1;

    if (context.isLoading) {
        return (
            <ServerContentBlock title={title}>
                <Spinner centered />
            </ServerContentBlock>
        );
    }

    if (context.error || !context.data || !filters) {
        return (
            <ServerContentBlock title={title}>
                <Alert type={'danger'}>{context.error ? httpErrorToHuman(context.error) : 'Could not load this page.'}</Alert>
            </ServerContentBlock>
        );
    }

    const noun = kind === 'plugins' ? 'plugin' : 'mod';
    const wrongPage = context.data.platform !== null && context.data.platform !== undefined && context.data.platform.kind !== kind;

    return (
        <ServerContentBlock title={title}>
            <div className={'mcc:flex mcc:flex-col mcc:gap-4'}>
                {wrongPage && (
                    <Alert type={'warning'}>
                        This server runs {loaderLabel(context.data.platform!.loader)}, which loads {context.data.platform!.kind}, not {kind}.
                        Installing here is turned off; use the {context.data.platform!.kind === 'mods' ? 'Mods' : 'Plugins'} page.
                    </Alert>
                )}
                <div className={'mcc:flex mcc:flex-wrap mcc:items-end mcc:gap-3'}>
                    <div className={'mcc:flex mcc:gap-2'} role={'tablist'}>
                        <Button
                            size={'small'}
                            isSecondary={tab !== 'browse'}
                            onClick={() => setTab('browse')}
                            role={'tab'}
                            aria-selected={tab === 'browse'}
                        >
                            Browse
                        </Button>
                        <Button
                            size={'small'}
                            isSecondary={tab !== 'installed'}
                            onClick={() => setTab('installed')}
                            role={'tab'}
                            aria-selected={tab === 'installed'}
                        >
                            Installed
                        </Button>
                    </div>
                    <div className={'mcc:ml-auto mcc:flex mcc:flex-wrap mcc:gap-3'}>
                        <label className={'mcc:flex mcc:w-40 mcc:flex-col mcc:gap-1 mcc:text-xs mcc:text-muted-foreground'}>
                            Loader
                            <Select options={loaderOptions} value={loader} onChange={(value) => setLoader(String(value))} />
                        </label>
                        {!proxy && (
                            <label className={'mcc:flex mcc:w-40 mcc:flex-col mcc:gap-1 mcc:text-xs mcc:text-muted-foreground'}>
                                Minecraft version
                                <Select
                                    options={versionOptions}
                                    value={gameVersion ?? ANY_VERSION}
                                    searchPlaceholder={'Search versions'}
                                    onChange={(value) => {
                                        const next = value === ANY_VERSION ? null : String(value);
                                        setVersionTouched(true);
                                        setGameVersion(next);
                                        if (canInstall && next !== null) {
                                            rememberVersion.mutate(next);
                                        }
                                    }}
                                />
                                <VersionSource
                                    source={
                                        versionTouched && gameVersion !== context.data.detected_game_version
                                            ? null
                                            : context.data.game_version_source
                                    }
                                    onReset={
                                        canInstall
                                            ? () => {
                                                  setVersionTouched(false);
                                                  rememberVersion.mutate(null);
                                              }
                                            : undefined
                                    }
                                />
                            </label>
                        )}
                    </div>
                </div>

                {!proxy && !context.data.detected_game_version && !versionTouched && (
                    <Alert type={'warning'}>
                        This server's Minecraft version could not be detected, so {noun}s for every version are shown. Pick the version it
                        runs above; it is remembered for this server. Starting the server once also lets it be detected from its files.
                    </Alert>
                )}

                {tab === 'installed' ? (
                    <InstalledList kind={kind} filters={filters} directory={context.data.directory} onOpen={(id) => openProject(id, id)} />
                ) : (
                    <TitledGreyBox title={`Find ${noun}s on Modrinth`}>
                        <div className={'mcc:flex mcc:flex-col mcc:gap-3'}>
                            <div className={'mcc:flex mcc:flex-wrap mcc:gap-3'}>
                                <div className={'mcc:min-w-0 mcc:flex-1'}>
                                    <Input
                                        type={'search'}
                                        value={query}
                                        placeholder={`Search ${noun}s`}
                                        aria-label={`Search ${noun}s`}
                                        onChange={(event) => setQuery(event.target.value)}
                                    />
                                </div>
                                <div className={'mcc:w-48'}>
                                    <Select options={SORT_OPTIONS} value={sort} onChange={(value) => setSort(String(value))} />
                                </div>
                            </div>

                            {results.error ? (
                                <Alert type={'danger'}>{httpErrorToHuman(results.error)}</Alert>
                            ) : !results.data ? (
                                <Spinner centered />
                            ) : results.data.hits.length === 0 ? (
                                <p className={'mcc:py-6 mcc:text-center mcc:text-sm mcc:text-muted-foreground'}>
                                    No {noun}s match these filters.
                                </p>
                            ) : (
                                <ul className={'mcc:flex mcc:flex-col mcc:divide-y mcc:divide-border'} aria-busy={results.isFetching}>
                                    {results.data.hits.map((hit) => (
                                        <ResultRow
                                            key={hit.project_id}
                                            hit={hit}
                                            canInstall={canInstall}
                                            canChangeVersion={canChangeVersion}
                                            onInstall={() => openProject(hit.project_id, hit.slug, 'versions')}
                                            onOpen={() => openProject(hit.project_id, hit.slug)}
                                        />
                                    ))}
                                </ul>
                            )}

                            {results.data && results.data.total > results.data.limit && (
                                <div
                                    className={
                                        'mcc:flex mcc:items-center mcc:justify-between mcc:gap-3 mcc:text-sm mcc:text-muted-foreground'
                                    }
                                >
                                    <span>
                                        {formatCount(results.data.total)} results · page {page} of {totalPages}
                                    </span>
                                    <div className={'mcc:flex mcc:gap-2'}>
                                        <Button size={'small'} isSecondary disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
                                            Previous
                                        </Button>
                                        <Button
                                            size={'small'}
                                            isSecondary
                                            disabled={page >= totalPages}
                                            onClick={() => setPage((p) => p + 1)}
                                        >
                                            Next
                                        </Button>
                                    </div>
                                </div>
                            )}
                        </div>
                    </TitledGreyBox>
                )}
            </div>

            {selected && (
                <ProjectPopup
                    key={selected.id}
                    kind={kind}
                    projectId={selected.id}
                    filters={filters}
                    initialTab={selected.tab}
                    onClose={() => setSelected(null)}
                    onInstalled={() => setTab('installed')}
                />
            )}
        </ServerContentBlock>
    );
}

interface ResultRowProps {
    hit: SearchHit;
    canInstall: boolean;
    canChangeVersion: boolean;
    onInstall: () => void;
    onOpen: () => void;
}

function ResultRow({ hit, canInstall, canChangeVersion, onInstall, onOpen }: ResultRowProps) {
    const installed = hit.installed;

    return (
        <li className={'mcc:flex mcc:items-start mcc:gap-3 mcc:py-3'}>
            <div className={installed ? 'mcc:opacity-60' : undefined}>
                <ProjectIcon url={hit.icon_url} title={hit.title} />
            </div>
            <div className={`mcc:min-w-0 mcc:flex-1 ${installed ? 'mcc:opacity-60' : ''}`}>
                <div className={'mcc:flex mcc:flex-wrap mcc:items-baseline mcc:gap-x-2'}>
                    <button type={'button'} onClick={onOpen} className={'mcc:text-left mcc:font-medium mcc:text-foreground mcc:hover:underline'}>
                        {hit.title}
                    </button>
                    <span className={'mcc:text-xs mcc:text-muted-foreground'}>by {hit.author}</span>
                    {installed && (
                        <span className={'mcc:rounded mcc:bg-muted mcc:px-1.5 mcc:py-0.5 mcc:text-xs mcc:font-medium mcc:text-foreground'}>
                            Installed {installed.version_number}
                        </span>
                    )}
                </div>
                <p className={'mcc:mt-0.5 mcc:line-clamp-2 mcc:text-sm mcc:text-muted-foreground'}>{hit.description}</p>
                <div className={'mcc:mt-1.5 mcc:flex mcc:flex-wrap mcc:items-center mcc:gap-1.5 mcc:text-xs mcc:text-muted-foreground'}>
                    <span>{formatCount(hit.downloads)} downloads</span>
                    {hit.categories.slice(0, 4).map((category) => (
                        <span key={category} className={'mcc:rounded mcc:bg-muted mcc:px-1.5 mcc:py-0.5'}>
                            {category}
                        </span>
                    ))}
                </div>
            </div>
            {installed ? (
                canInstall && canChangeVersion ? (
                    <Button size={'small'} isSecondary onClick={onInstall}>
                        Change version
                    </Button>
                ) : (
                    <Button size={'small'} isSecondary disabled>
                        Installed
                    </Button>
                )
            ) : (
                canInstall && (
                    <Button size={'small'} onClick={onInstall}>
                        Install
                    </Button>
                )
            )}
        </li>
    );
}

const SOURCE_LABELS: Record<string, string> = {
    saved: 'Saved for this server',
    startup: 'From startup settings',
    files: 'Detected from server files',
};

function VersionSource({ source, onReset }: { source: string | null; onReset?: () => void }) {
    if (!source) return null;

    return (
        <span className={'mcc:text-[11px] mcc:text-muted-foreground'}>
            {SOURCE_LABELS[source] ?? source}
            {source === 'saved' && onReset && (
                <>
                    {' · '}
                    <button type={'button'} className={'mcc:underline mcc:hover:text-foreground'} onClick={onReset}>
                        Detect
                    </button>
                </>
            )}
        </span>
    );
}
