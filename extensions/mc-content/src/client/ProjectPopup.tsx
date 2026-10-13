import { useEffect, useMemo, useState, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
    Alert,
    Button,
    Spinner,
    httpErrorToHuman,
    invalidateServerData,
    toast,
    useCurrentServerRequired,
    useServerPermission,
} from '@pterodactyl/sdk';
import {
    getProject,
    getProjectNames,
    getVersions,
    install,
    keys,
    type ContentKind,
    type Filters,
    type ProjectVersion,
} from './api';
import { formatBytes, formatCount, formatDate, loaderLabel, versionRange } from './format';
import Markdown from './Markdown';
import ProjectIcon from './ProjectIcon';

type Tab = 'description' | 'gallery' | 'versions' | 'dependencies';

interface Props {
    kind: ContentKind;
    projectId: string;
    filters: Filters;
    /** Open on this tab, e.g. "versions" from an Install button. */
    initialTab?: Tab;
    onClose: () => void;
    onInstalled?: () => void;
}

/**
 * A project's Modrinth page inside the panel: description, gallery, versions with
 * changelogs and install buttons, dependencies and links. Modrinth refuses to be
 * framed, so this is built from its API rather than embedding the site.
 */
export default function ProjectPopup({ kind, projectId, filters, initialTab = 'description', onClose, onInstalled }: Props) {
    const server = useCurrentServerRequired();
    const identifier = server.attributes.identifier;
    const queryClient = useQueryClient();
    const canInstall = useServerPermission('file.create');
    const canReplace = useServerPermission(['file.create', 'file.delete']);
    const [tab, setTab] = useState<Tab>(initialTab);
    const [openChangelog, setOpenChangelog] = useState<string | null>(null);
    const [zoomed, setZoomed] = useState<number | null>(null);

    const project = useQuery({ queryKey: keys.project(identifier, kind, projectId), queryFn: () => getProject(identifier, kind, projectId) });
    const versions = useQuery({
        queryKey: keys.versions(identifier, kind, projectId, filters),
        queryFn: () => getVersions(identifier, kind, projectId, filters),
    });

    const installed = versions.data?.installed ?? null;
    const newest: ProjectVersion | undefined = versions.data?.data[0];
    const required = useMemo(
        () => (newest?.dependencies ?? []).filter((dependency) => dependency.type === 'required' && dependency.project_id).map((d) => d.project_id as string),
        [newest],
    );
    const optional = useMemo(
        () => (newest?.dependencies ?? []).filter((dependency) => dependency.type === 'optional' && dependency.project_id).map((d) => d.project_id as string),
        [newest],
    );
    const names = useQuery({
        queryKey: ['mc-content', identifier, kind, 'project-names', [...required, ...optional]],
        queryFn: () => getProjectNames(identifier, kind, [...required, ...optional]),
        enabled: required.length + optional.length > 0,
        staleTime: 10 * 60_000,
    });

    const installVersion = useMutation({
        mutationFn: (versionId: string) => install(identifier, kind, versionId, filters, installed?.filename),
        onSuccess: async (result) => {
            const done = result.installed.map((item) => `${item.title} ${item.version_number}`.trim());
            toast.success(`${installed ? 'Switched to' : 'Installed'} ${done.join(', ')}. Restart the server to load it.`);
            for (const skipped of result.skipped_dependencies) {
                toast.warning(`A required dependency was not installed: ${skipped.reason}`);
            }
            await Promise.all([
                queryClient.invalidateQueries({ queryKey: ['mc-content', identifier, kind] }),
                invalidateServerData(server.attributes.uuid, ['files']),
            ]);
            onInstalled?.();
        },
        onError: (error) => toast.error(httpErrorToHuman(error)),
    });

    // Esc closes the popup (or the enlarged image first); the page behind does not scroll.
    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if (event.key !== 'Escape' || installVersion.isPending) return;
            event.stopPropagation();
            if (zoomed !== null) setZoomed(null);
            else onClose();
        };
        window.addEventListener('keydown', onKey, true);
        const overflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        return () => {
            window.removeEventListener('keydown', onKey, true);
            document.body.style.overflow = overflow;
        };
    }, [onClose, zoomed, installVersion.isPending]);

    const data = project.data;
    const gallery = data?.gallery ?? [];
    const tabs: { id: Tab; label: string; hidden?: boolean }[] = [
        { id: 'description', label: 'Description' },
        { id: 'gallery', label: `Gallery${gallery.length ? ` (${gallery.length})` : ''}`, hidden: gallery.length === 0 },
        { id: 'versions', label: 'Versions' },
        { id: 'dependencies', label: `Dependencies${required.length ? ` (${required.length})` : ''}` },
    ];

    return createPortal(
        <div
            className={'mcc:fixed mcc:inset-0 mcc:z-50 mcc:flex mcc:items-center mcc:justify-center mcc:bg-black/60 mcc:p-2 mcc:sm:p-6'}
            onMouseDown={(event) => event.target === event.currentTarget && !installVersion.isPending && onClose()}
            role={'presentation'}
        >
            <div
                role={'dialog'}
                aria-modal={'true'}
                aria-label={data?.title ?? 'Project'}
                className={
                    'mcc:flex mcc:h-full mcc:max-h-[52rem] mcc:w-full mcc:max-w-5xl mcc:flex-col mcc:overflow-hidden mcc:rounded-lg mcc:border mcc:border-border mcc:bg-background mcc:shadow-2xl'
                }
            >
                {project.isLoading ? (
                    <div className={'mcc:flex mcc:flex-1 mcc:items-center mcc:justify-center'}>
                        <Spinner centered />
                    </div>
                ) : project.error || !data ? (
                    <div className={'mcc:p-6'}>
                        <Alert type={'danger'}>{httpErrorToHuman(project.error)}</Alert>
                        <div className={'mcc:mt-4 mcc:flex mcc:justify-end'}>
                            <Button isSecondary onClick={onClose}>
                                Close
                            </Button>
                        </div>
                    </div>
                ) : (
                    <>
                        {/* Header */}
                        <div className={'mcc:flex mcc:items-start mcc:gap-4 mcc:border-b mcc:border-border mcc:p-4 mcc:sm:p-5'}>
                            <div className={'mcc:shrink-0 mcc:scale-125 mcc:origin-top-left mcc:sm:mr-2'}>
                                <ProjectIcon url={data.icon_url} title={data.title} />
                            </div>
                            <div className={'mcc:min-w-0 mcc:flex-1'}>
                                <div className={'mcc:flex mcc:flex-wrap mcc:items-baseline mcc:gap-x-3 mcc:gap-y-1'}>
                                    <h2 className={'mcc:text-xl mcc:font-semibold mcc:text-foreground'}>{data.title}</h2>
                                    {data.installed && (
                                        <span className={'mcc:rounded mcc:bg-muted mcc:px-1.5 mcc:py-0.5 mcc:text-xs mcc:font-medium'}>
                                            Installed {data.installed}
                                        </span>
                                    )}
                                </div>
                                <p className={'mcc:mt-1 mcc:text-sm mcc:text-muted-foreground'}>{data.description}</p>
                                <div className={'mcc:mt-2 mcc:flex mcc:flex-wrap mcc:items-center mcc:gap-x-4 mcc:gap-y-1 mcc:text-xs mcc:text-muted-foreground'}>
                                    <span>{formatCount(data.downloads)} downloads</span>
                                    <span>{formatCount(data.followers)} followers</span>
                                    {data.updated && <span>Updated {formatDate(data.updated)}</span>}
                                    {data.categories.slice(0, 6).map((category) => (
                                        <span key={category} className={'mcc:rounded mcc:bg-muted mcc:px-1.5 mcc:py-0.5'}>
                                            {category}
                                        </span>
                                    ))}
                                </div>
                            </div>
                            <div className={'mcc:flex mcc:shrink-0 mcc:items-center mcc:gap-2'}>
                                {canInstall && newest && (
                                    <Button
                                        size={'small'}
                                        disabled={installVersion.isPending || installed?.version_id === newest.id || (installed !== null && !canReplace)}
                                        isLoading={installVersion.isPending && installVersion.variables === newest.id}
                                        onClick={() => installVersion.mutate(newest.id)}
                                    >
                                        {installed?.version_id === newest.id ? 'Up to date' : installed ? `Update to ${newest.version_number}` : 'Install latest'}
                                    </Button>
                                )}
                                <button
                                    type={'button'}
                                    onClick={onClose}
                                    disabled={installVersion.isPending}
                                    aria-label={'Close'}
                                    className={'mcc:rounded mcc:p-1.5 mcc:text-muted-foreground mcc:hover:bg-muted mcc:hover:text-foreground'}
                                >
                                    <svg width={'18'} height={'18'} viewBox={'0 0 24 24'} fill={'none'} stroke={'currentColor'} strokeWidth={'2'} aria-hidden>
                                        <path d={'M18 6 6 18M6 6l12 12'} />
                                    </svg>
                                </button>
                            </div>
                        </div>

                        {/* Tabs */}
                        <div className={'mcc:flex mcc:gap-1 mcc:overflow-x-auto mcc:border-b mcc:border-border mcc:px-4'} role={'tablist'}>
                            {tabs
                                .filter((item) => !item.hidden)
                                .map((item) => (
                                    <button
                                        key={item.id}
                                        type={'button'}
                                        role={'tab'}
                                        aria-selected={tab === item.id}
                                        onClick={() => setTab(item.id)}
                                        className={`mcc:-mb-px mcc:whitespace-nowrap mcc:border-b-2 mcc:px-3 mcc:py-2 mcc:text-sm ${
                                            tab === item.id
                                                ? 'mcc:border-primary mcc:text-foreground'
                                                : 'mcc:border-transparent mcc:text-muted-foreground mcc:hover:text-foreground'
                                        }`}
                                    >
                                        {item.label}
                                    </button>
                                ))}
                        </div>

                        {/* Body + sidebar */}
                        <div className={'mcc:flex mcc:min-h-0 mcc:flex-1 mcc:flex-col mcc:md:flex-row'}>
                            <div className={'mcc:min-h-0 mcc:flex-1 mcc:overflow-y-auto mcc:p-4 mcc:sm:p-5'}>
                                {tab === 'description' &&
                                    (data.body.trim() ? <Markdown text={data.body} /> : <Muted>This project has no description.</Muted>)}

                                {tab === 'gallery' && (
                                    <div className={'mcc:grid mcc:grid-cols-1 mcc:gap-3 mcc:sm:grid-cols-2'}>
                                        {gallery.map((image, index) => (
                                            <button
                                                key={image.url}
                                                type={'button'}
                                                onClick={() => setZoomed(index)}
                                                className={'mcc:group mcc:overflow-hidden mcc:rounded-md mcc:border mcc:border-border mcc:text-left'}
                                            >
                                                <img
                                                    src={image.url}
                                                    alt={image.title ?? ''}
                                                    loading={'lazy'}
                                                    referrerPolicy={'no-referrer'}
                                                    className={'mcc:aspect-video mcc:w-full mcc:bg-muted mcc:object-cover mcc:transition mcc:group-hover:opacity-90'}
                                                />
                                                {(image.title || image.description) && (
                                                    <div className={'mcc:p-2'}>
                                                        {image.title && <p className={'mcc:text-sm mcc:font-medium mcc:text-foreground'}>{image.title}</p>}
                                                        {image.description && <p className={'mcc:text-xs mcc:text-muted-foreground'}>{image.description}</p>}
                                                    </div>
                                                )}
                                            </button>
                                        ))}
                                    </div>
                                )}

                                {tab === 'versions' &&
                                    (versions.isLoading ? (
                                        <Spinner centered />
                                    ) : versions.error ? (
                                        <Alert type={'danger'}>{httpErrorToHuman(versions.error)}</Alert>
                                    ) : !versions.data?.data.length ? (
                                        <Alert type={'warning'}>
                                            No version supports {loaderLabel(filters.loader)}
                                            {filters.gameVersion ? ` on Minecraft ${filters.gameVersion}` : ''}.
                                        </Alert>
                                    ) : (
                                        <ul className={'mcc:flex mcc:flex-col mcc:divide-y mcc:divide-border'}>
                                            {versions.data.data.map((version, index) => {
                                                const current = installed?.version_id === version.id;
                                                const showing = openChangelog === version.id;

                                                return (
                                                    <li key={version.id} className={'mcc:py-3'}>
                                                        <div className={'mcc:flex mcc:items-center mcc:gap-3'}>
                                                            <div className={'mcc:min-w-0 mcc:flex-1'}>
                                                                <div className={'mcc:flex mcc:flex-wrap mcc:items-center mcc:gap-2'}>
                                                                    <span className={'mcc:font-medium mcc:text-foreground'}>{version.version_number}</span>
                                                                    {index === 0 && <Badge tone={'primary'}>Latest</Badge>}
                                                                    {current && <Badge>Installed</Badge>}
                                                                    {version.version_type !== 'release' && <Badge>{version.version_type}</Badge>}
                                                                </div>
                                                                <p className={'mcc:mt-0.5 mcc:text-xs mcc:text-muted-foreground'}>
                                                                    {versionRange(version.game_versions)} · {version.loaders.map(loaderLabel).join(', ')}
                                                                    {version.file ? ` · ${formatBytes(version.file.size)}` : ''}
                                                                    {version.date_published ? ` · ${formatDate(version.date_published)}` : ''}
                                                                </p>
                                                            </div>
                                                            {version.changelog?.trim() && (
                                                                <Button.Text size={'xsmall'} onClick={() => setOpenChangelog(showing ? null : version.id)}>
                                                                    {showing ? 'Hide changes' : 'Changes'}
                                                                </Button.Text>
                                                            )}
                                                            {canInstall && (
                                                                <Button
                                                                    size={'small'}
                                                                    isSecondary={index !== 0 || current}
                                                                    disabled={installVersion.isPending || current || (installed !== null && !canReplace)}
                                                                    isLoading={installVersion.isPending && installVersion.variables === version.id}
                                                                    onClick={() => installVersion.mutate(version.id)}
                                                                >
                                                                    {current ? 'Installed' : installed ? 'Switch' : 'Install'}
                                                                </Button>
                                                            )}
                                                        </div>
                                                        {showing && version.changelog && (
                                                            <div className={'mcc:mt-2 mcc:rounded-md mcc:bg-muted/50 mcc:p-3'}>
                                                                <Markdown text={version.changelog} className={'mcc:text-sm'} />
                                                            </div>
                                                        )}
                                                    </li>
                                                );
                                            })}
                                        </ul>
                                    ))}

                                {tab === 'dependencies' &&
                                    (!newest ? (
                                        <Muted>No version for this server, so no dependencies to show.</Muted>
                                    ) : required.length + optional.length === 0 ? (
                                        <Muted>{newest.version_number} needs nothing else.</Muted>
                                    ) : (
                                        <div className={'mcc:flex mcc:flex-col mcc:gap-4'}>
                                            {[
                                                { label: 'Required', ids: required, note: 'Installed automatically with it, unless turned off in the extension settings.' },
                                                { label: 'Optional', ids: optional, note: 'Not installed automatically.' },
                                            ]
                                                .filter((group) => group.ids.length > 0)
                                                .map((group) => (
                                                    <section key={group.label}>
                                                        <h3 className={'mcc:text-sm mcc:font-medium mcc:text-foreground'}>{group.label}</h3>
                                                        <p className={'mcc:mb-2 mcc:text-xs mcc:text-muted-foreground'}>{group.note}</p>
                                                        <ul className={'mcc:flex mcc:flex-col mcc:divide-y mcc:divide-border mcc:rounded-md mcc:border mcc:border-border'}>
                                                            {group.ids.map((id) => {
                                                                const name = names.data?.[id];
                                                                const here = data.installed_projects.includes(id);
                                                                return (
                                                                    <li key={id} className={'mcc:flex mcc:items-center mcc:gap-3 mcc:px-3 mcc:py-2'}>
                                                                        <ProjectIcon url={name?.icon_url ?? null} title={name?.title ?? id} />
                                                                        <span className={'mcc:flex-1 mcc:text-sm mcc:text-foreground'}>{name?.title ?? id}</span>
                                                                        {here ? <Badge tone={'primary'}>On this server</Badge> : <Badge>Not installed</Badge>}
                                                                    </li>
                                                                );
                                                            })}
                                                        </ul>
                                                    </section>
                                                ))}
                                            <p className={'mcc:text-xs mcc:text-muted-foreground'}>For version {newest.version_number}.</p>
                                        </div>
                                    ))}
                            </div>

                            {/* Sidebar */}
                            <aside
                                className={
                                    'mcc:flex mcc:shrink-0 mcc:flex-col mcc:gap-4 mcc:border-t mcc:border-border mcc:p-4 mcc:text-sm mcc:md:w-64 mcc:md:border-t-0 mcc:md:border-l mcc:md:overflow-y-auto'
                                }
                            >
                                <Side title={'Links'}>
                                    <a
                                        href={`https://modrinth.com/${data.project_type || 'project'}/${data.slug}`}
                                        target={'_blank'}
                                        rel={'noreferrer noopener'}
                                        className={'mcc:block mcc:text-primary mcc:hover:underline'}
                                    >
                                        Open on Modrinth ↗
                                    </a>
                                    {data.links.map((link) => (
                                        <a
                                            key={link.url}
                                            href={link.url}
                                            target={'_blank'}
                                            rel={'noreferrer noopener'}
                                            className={'mcc:block mcc:truncate mcc:text-foreground mcc:hover:underline'}
                                        >
                                            {link.label} ↗
                                        </a>
                                    ))}
                                </Side>
                                <Side title={'Runs on'}>
                                    <p className={'mcc:text-muted-foreground'}>{data.loaders.map(loaderLabel).join(', ') || 'Unknown'}</p>
                                    {kind === 'mods' && (
                                        <p className={'mcc:text-xs mcc:text-muted-foreground'}>
                                            Server: {sideLabel(data.server_side)} · Client: {sideLabel(data.client_side)}
                                        </p>
                                    )}
                                </Side>
                                {data.license.name && (
                                    <Side title={'License'}>
                                        {data.license.url ? (
                                            <a href={data.license.url} target={'_blank'} rel={'noreferrer noopener'} className={'mcc:text-foreground mcc:hover:underline'}>
                                                {data.license.name}
                                            </a>
                                        ) : (
                                            <p className={'mcc:text-muted-foreground'}>{data.license.name}</p>
                                        )}
                                    </Side>
                                )}
                                {data.published && (
                                    <Side title={'Published'}>
                                        <p className={'mcc:text-muted-foreground'}>{formatDate(data.published)}</p>
                                    </Side>
                                )}
                            </aside>
                        </div>
                    </>
                )}
            </div>

            {zoomed !== null && gallery[zoomed] && (
                <div
                    className={'mcc:fixed mcc:inset-0 mcc:z-50 mcc:flex mcc:items-center mcc:justify-center mcc:bg-black/85 mcc:p-4'}
                    onMouseDown={() => setZoomed(null)}
                    role={'presentation'}
                >
                    <img
                        src={gallery[zoomed].raw_url}
                        alt={gallery[zoomed].title ?? ''}
                        referrerPolicy={'no-referrer'}
                        className={'mcc:max-h-full mcc:max-w-full mcc:rounded'}
                    />
                </div>
            )}
        </div>,
        document.body,
    );
}

function sideLabel(value: string): string {
    return { required: 'required', optional: 'optional', unsupported: 'not needed' }[value] ?? 'unknown';
}

function Badge({ children, tone }: { children: ReactNode; tone?: 'primary' }) {
    return (
        <span
            className={`mcc:rounded mcc:px-1.5 mcc:py-0.5 mcc:text-xs mcc:capitalize ${
                tone === 'primary' ? 'mcc:bg-primary/15 mcc:text-primary' : 'mcc:bg-muted mcc:text-foreground'
            }`}
        >
            {children}
        </span>
    );
}

function Side({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className={'mcc:flex mcc:flex-col mcc:gap-1'}>
            <h3 className={'mcc:text-xs mcc:font-medium mcc:uppercase mcc:tracking-wide mcc:text-muted-foreground'}>{title}</h3>
            {children}
        </section>
    );
}

function Muted({ children }: { children: ReactNode }) {
    return <p className={'mcc:py-6 mcc:text-center mcc:text-sm mcc:text-muted-foreground'}>{children}</p>;
}
