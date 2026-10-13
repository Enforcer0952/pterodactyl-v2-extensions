import { useEffect, useMemo, useState, type ReactNode } from 'react';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
    Alert,
    Button,
    Dialog,
    Input,
    PanelLink,
    Select,
    ServerContentBlock,
    Spinner,
    Switch,
    TitledGreyBox,
    httpErrorToHuman,
    toast,
    useCurrentServerRequired,
    useServerPermission,
} from '@pterodactyl/sdk';
import {
    BADGES,
    badForcedHosts,
    checkDraft,
    countChanges,
    draftFrom,
    getNetwork,
    keys,
    moveItem,
    plainMotd,
    problemCount,
    renameServer,
    restartProxy,
    saveNetwork,
    suggestName,
    validateEntry,
    type Analysis,
    type Draft,
    type Entry,
    type GlobalProblem,
    type Level,
    type NetworkResponse,
    type PanelChoice,
    type Problem,
} from '../api';

const EMPTY_ENTRY: Entry = { name: '', address: 'localhost:', motd: '', restricted: false, was: null };

/** A value that follows the given one after it has been still for a moment. */
function useSettled<T>(value: T, delay = 400): T {
    const [settled, setSettled] = useState(value);
    useEffect(() => {
        const timer = setTimeout(() => setSettled(value), delay);
        return () => clearTimeout(timer);
    }, [value, delay]);
    return settled;
}

const isConflict = (error: unknown) => (error as { response?: { status?: number } } | null)?.response?.status === 409;

type Editing = { index: number | null; entry: Entry };

export default function NetworkScreen() {
    const server = useCurrentServerRequired();
    const identifier = server.attributes.identifier;
    const queryClient = useQueryClient();
    const canEdit = useServerPermission('file.update');
    const canRestart = useServerPermission('control.restart');

    const [draft, setDraft] = useState<Draft | null>(null);
    // The file version the unsaved changes were made against. A refetch while editing
    // must not move it, or a stale draft would overwrite a newer file.
    const [baseHash, setBaseHash] = useState<string | null>(null);
    const [editing, setEditing] = useState<Editing | null>(null);
    const [removing, setRemoving] = useState<number | null>(null);
    const [applied, setApplied] = useState(false);
    const [conflict, setConflict] = useState(false);
    const [confirmRestart, setConfirmRestart] = useState(false);

    const network = useQuery({
        queryKey: keys.network(identifier),
        queryFn: () => getNetwork(identifier),
        refetchOnWindowFocus: draft === null,
    });
    const data = network.data;
    const saved = useMemo(() => (data ? draftFrom(data) : null), [data]);
    const current = draft ?? saved;
    const changes = draft && saved ? countChanges(saved, draft) : 0;
    const dirty = changes > 0;
    const hostsInvalid = current !== null && badForcedHosts(current.forced_hosts).length > 0;

    // Problems of the unsaved draft come from the panel, so there is one set of rules.
    const draftKey = useSettled(dirty ? JSON.stringify(draft) : '');
    const check = useQuery({
        queryKey: keys.check(identifier, draftKey),
        queryFn: () => checkDraft(identifier, JSON.parse(draftKey) as Draft),
        enabled: draftKey !== '',
        placeholderData: keepPreviousData,
        staleTime: 30_000,
    });

    useEffect(() => {
        if (!dirty) return;
        const warn = (event: BeforeUnloadEvent) => event.preventDefault();
        window.addEventListener('beforeunload', warn);
        return () => window.removeEventListener('beforeunload', warn);
    }, [dirty]);

    const reset = () => {
        setDraft(null);
        setBaseHash(null);
        setConflict(false);
    };

    const edit = (change: (draft: Draft) => Draft) => {
        if (!current || !data) return;
        if (draft === null) setBaseHash(data.hash);
        setDraft(change(current));
    };

    const save = useMutation({
        mutationFn: () => saveNetwork(identifier, draft as Draft, baseHash ?? data?.hash ?? ''),
        onSuccess: (result) => {
            queryClient.setQueryData(keys.network(identifier), result);
            reset();
            setApplied(true);
            toast.success('Saved. The proxy uses the new list after a restart.');
        },
        onError: (error) => {
            if (isConflict(error)) setConflict(true);
            toast.error(httpErrorToHuman(error));
        },
    });

    const restart = useMutation({
        mutationFn: () => restartProxy(identifier),
        onSuccess: () => {
            setConfirmRestart(false);
            setApplied(false);
            toast.success('Restarting the proxy.');
            void queryClient.invalidateQueries({ queryKey: keys.network(identifier) });
        },
        onError: (error) => {
            setConfirmRestart(false);
            toast.error(httpErrorToHuman(error));
        },
    });

    if (network.isLoading) {
        return (
            <ServerContentBlock title={'Network'}>
                <Spinner centered />
            </ServerContentBlock>
        );
    }

    if (network.error || !data || !current || !saved) {
        return (
            <ServerContentBlock title={'Network'}>
                <Alert type={'danger'}>{httpErrorToHuman(network.error)}</Alert>
            </ServerContentBlock>
        );
    }

    if (data.status !== 'ok') {
        return (
            <ServerContentBlock title={'Network'}>
                <Unavailable data={data} />
            </ServerContentBlock>
        );
    }

    // Per-entry analysis: the saved file's own while nothing is edited, the panel's check of the draft otherwise.
    const checked = draft !== null && check.data && check.data.entries.length === draft.servers.length ? check.data : null;
    const analysis: (Analysis | null)[] = current.servers.map((_, index) =>
        checked ? checked.entries[index] : draft ? null : data.servers[index],
    );
    const globalProblems: GlobalProblem[] = checked ? checked.problems : draft ? [] : data.problems;
    const states = new Map(data.servers.flatMap((row) => (row.server ? [[row.server.id, row.server.state ?? null] as const] : [])));
    const choices = checked?.panel_servers ?? data.panel_servers;
    const names = current.servers.map((entry) => entry.name);
    const total =
        analysis.reduce((sum, row) => sum + (row ? problemCount(row.problems) : 0), 0) +
        globalProblems.filter((problem) => problem.level !== 'info').length;

    const apply = (entry: Entry, index: number | null) => {
        edit((value) => {
            if (index === null) return { ...value, servers: [...value.servers, entry] };
            const old = value.servers[index];
            const next = { ...value, servers: value.servers.map((item, position) => (position === index ? entry : item)) };
            return renameServer(next, old.name, entry.name);
        });
        setEditing(null);
    };

    return (
        <ServerContentBlock title={'Network'}>
            <div className={'mcnet:flex mcnet:flex-col mcnet:gap-4 mcnet:pb-24'}>
                {applied && (
                    <Alert type={'warning'} title={'Restart the proxy to apply the changes'}>
                        <div className={'mcnet:flex mcnet:flex-wrap mcnet:items-center mcnet:justify-between mcnet:gap-2'}>
                            <span>config.yml is saved. BungeeCord reads it only when it starts.</span>
                            {canRestart && (
                                <Button size={'small'} onClick={() => setConfirmRestart(true)}>
                                    Restart proxy
                                </Button>
                            )}
                        </div>
                    </Alert>
                )}

                {conflict && (
                    <Alert type={'danger'} title={'config.yml changed'}>
                        <div className={'mcnet:flex mcnet:flex-wrap mcnet:items-center mcnet:justify-between mcnet:gap-2'}>
                            <span>Someone or something else changed the file. Reload it, then make your changes again.</span>
                            <Button
                                size={'small'}
                                onClick={() => {
                                    reset();
                                    void network.refetch();
                                }}
                            >
                                Reload
                            </Button>
                        </div>
                    </Alert>
                )}

                {!data.has_listener && (
                    <Alert type={'warning'}>
                        This config.yml has no listener, so priorities and forced hosts cannot be shown or saved.
                    </Alert>
                )}

                <TitledGreyBox title={`Backend servers (${current.servers.length})`}>
                    <div className={'mcnet:flex mcnet:flex-col mcnet:gap-3'}>
                        <div className={'mcnet:flex mcnet:flex-wrap mcnet:items-center mcnet:justify-between mcnet:gap-2'}>
                            <p className={'mcnet:text-sm mcnet:text-muted-foreground'}>
                                {total === 0 ? 'No problems found.' : `${total} problem${total === 1 ? '' : 's'} found.`} Each address is
                                matched with a server on this node by its port.
                                {data.proxy_state && <> The proxy is {data.proxy_state}.</>}
                            </p>
                            {canEdit && (
                                <div className={'mcnet:flex mcnet:flex-wrap mcnet:items-center mcnet:gap-2'}>
                                    <div className={'mcnet:w-56'}>
                                        <Select
                                            placeholder={'Add a panel server…'}
                                            searchPlaceholder={'Search servers'}
                                            emptyMessage={'No Minecraft servers on this node.'}
                                            value={null}
                                            options={choices.map((choice) => ({
                                                value: choice.id,
                                                label: choiceLabel(choice, current),
                                                textLabel: choice.name,
                                            }))}
                                            onChange={(id) => {
                                                const choice = choices.find((item) => item.id === id);
                                                if (choice)
                                                    setEditing({
                                                        index: null,
                                                        entry: {
                                                            ...EMPTY_ENTRY,
                                                            name: suggestName(choice.name, names),
                                                            address: `localhost:${choice.port}`,
                                                        },
                                                    });
                                            }}
                                        />
                                    </div>
                                    <Button size={'small'} isSecondary onClick={() => setEditing({ index: null, entry: EMPTY_ENTRY })}>
                                        Add other address
                                    </Button>
                                </div>
                            )}
                        </div>

                        {current.servers.length === 0 ? (
                            <p className={'mcnet:py-6 mcnet:text-center mcnet:text-sm mcnet:text-muted-foreground'}>
                                No backend servers in config.yml.
                            </p>
                        ) : (
                            <div className={'mcnet:overflow-x-auto mcnet:rounded-md mcnet:border mcnet:border-border'}>
                                <table className={'mcnet:w-full mcnet:text-sm'}>
                                    <thead>
                                        <tr className={'mcnet:text-left mcnet:text-xs mcnet:text-muted-foreground'}>
                                            <Th>Name</Th>
                                            <Th>Address</Th>
                                            <Th>Description</Th>
                                            <Th>Panel server</Th>
                                            {canEdit && (
                                                <Th>
                                                    <span className={'mcnet:sr-only'}>Actions</span>
                                                </Th>
                                            )}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {current.servers.map((entry, index) => (
                                            <BackendRow
                                                key={`${entry.was ?? 'new'}:${index}`}
                                                entry={entry}
                                                analysis={analysis[index]}
                                                state={
                                                    analysis[index]?.server ? (states.get(analysis[index]?.server?.id ?? '') ?? null) : null
                                                }
                                                canEdit={canEdit}
                                                onEdit={() => setEditing({ index, entry })}
                                                onRemove={() => setRemoving(index)}
                                            />
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </div>
                </TitledGreyBox>

                {globalProblems.filter((problem) => problem.level !== 'info').length > 0 && (
                    <TitledGreyBox title={'Names that do not exist'}>
                        <ul
                            className={
                                'mcnet:flex mcnet:flex-col mcnet:divide-y mcnet:divide-border mcnet:rounded-md mcnet:border mcnet:border-border'
                            }
                        >
                            {globalProblems
                                .filter((problem) => problem.level !== 'info')
                                .map((problem) => (
                                    <GlobalRow
                                        key={`${problem.code}:${problem.name}`}
                                        problem={problem}
                                        names={names}
                                        canEdit={canEdit}
                                        onFix={(change) => edit(change)}
                                    />
                                ))}
                        </ul>
                    </TitledGreyBox>
                )}

                {data.has_listener && (
                    <div className={'mcnet:grid mcnet:gap-4 mcnet:lg:grid-cols-2'}>
                        <Priorities
                            priorities={current.priorities}
                            names={names}
                            canEdit={canEdit}
                            onChange={(priorities) => edit((value) => ({ ...value, priorities }))}
                        />
                        <ForcedHosts
                            hosts={current.forced_hosts}
                            names={names}
                            canEdit={canEdit}
                            onChange={(forced) => edit((value) => ({ ...value, forced_hosts: forced }))}
                        />
                    </div>
                )}
            </div>

            {dirty && (
                <div
                    className={
                        'mcnet:fixed mcnet:inset-x-0 mcnet:bottom-0 mcnet:z-30 mcnet:border-t mcnet:border-border mcnet:bg-background/95 mcnet:px-4 mcnet:py-3 mcnet:backdrop-blur'
                    }
                >
                    <div
                        className={
                            'mcnet:mx-auto mcnet:flex mcnet:max-w-5xl mcnet:flex-wrap mcnet:items-center mcnet:justify-between mcnet:gap-3'
                        }
                    >
                        <p className={'mcnet:text-sm mcnet:text-foreground'}>
                            {changes} unsaved change{changes === 1 ? '' : 's'}
                            <span className={'mcnet:ml-2 mcnet:text-muted-foreground'}>config.yml keeps a backup of the old file.</span>
                        </p>
                        <div className={'mcnet:flex mcnet:items-center mcnet:gap-2'}>
                            <Button isSecondary onClick={reset} disabled={save.isPending}>
                                Discard
                            </Button>
                            <Button onClick={() => save.mutate()} isLoading={save.isPending} disabled={conflict || hostsInvalid}>
                                Save
                            </Button>
                        </div>
                    </div>
                </div>
            )}

            {editing && (
                <EntryDialog
                    key={`${editing.index ?? 'new'}`}
                    editing={editing}
                    others={current.servers.filter((_, position) => position !== editing.index).map((entry) => entry.name)}
                    onClose={() => setEditing(null)}
                    onApply={(entry) => apply(entry, editing.index)}
                />
            )}

            {removing !== null && current.servers[removing] && (
                <Dialog.Confirm
                    open
                    title={`Remove ${current.servers[removing].name}?`}
                    confirm={'Remove'}
                    onClose={() => setRemoving(null)}
                    onConfirmed={() => {
                        const name = current.servers[removing].name;
                        edit((value) => ({ ...value, servers: value.servers.filter((_, position) => position !== removing) }));
                        setRemoving(null);
                        toast.success(`${name} removed. Nothing is saved until you press Save.`);
                    }}
                >
                    <p className={'mcnet:text-sm'}>
                        Players can no longer join this server through the proxy. The priority list and forced hosts are not changed, so
                        check them afterwards.
                    </p>
                </Dialog.Confirm>
            )}

            {confirmRestart && (
                <Dialog.Confirm
                    open
                    title={'Restart the proxy?'}
                    confirm={'Restart'}
                    pending={restart.isPending}
                    onClose={() => setConfirmRestart(false)}
                    onConfirmed={() => restart.mutate()}
                >
                    <p className={'mcnet:text-sm'}>
                        Everyone who is connected through the proxy is disconnected for a moment and can join again right away.
                    </p>
                </Dialog.Confirm>
            )}
        </ServerContentBlock>
    );
}

function choiceLabel(choice: PanelChoice, draft: Draft): string {
    const used = draft.servers.some((entry) => entry.address.endsWith(`:${choice.port}`));
    return `${choice.name} (${choice.port})${used ? ' - already added' : ''}`;
}

function Unavailable({ data }: { data: NetworkResponse }) {
    if (data.status === 'velocity') {
        return (
            <Alert type={'info'} title={'Velocity is not supported yet'}>
                This proxy has a velocity.toml and no config.yml. This page only works with BungeeCord and Waterfall for now.
            </Alert>
        );
    }
    if (data.status === 'invalid') {
        return (
            <Alert type={'danger'} title={'config.yml cannot be read'}>
                {data.message} Nothing was changed. Fix the file in the file manager, then reload this page.
            </Alert>
        );
    }
    return (
        <Alert type={'info'} title={'No config.yml yet'}>
            Start the proxy once so it creates config.yml, then reload this page.
        </Alert>
    );
}

function Th({ children }: { children: ReactNode }) {
    return <th className={'mcnet:px-3 mcnet:py-2 mcnet:font-normal'}>{children}</th>;
}

const LEVEL_CLASS: Record<Level, string> = {
    error: 'mcnet:border-red-500/40 mcnet:bg-red-500/10 mcnet:text-red-400',
    warning: 'mcnet:border-amber-500/40 mcnet:bg-amber-500/10 mcnet:text-amber-400',
    info: 'mcnet:border-border mcnet:bg-muted mcnet:text-muted-foreground',
};

function Badge({ problem }: { problem: Problem }) {
    return (
        <span
            title={problem.message}
            className={`mcnet:inline-flex mcnet:items-center mcnet:rounded mcnet:border mcnet:px-1.5 mcnet:py-0.5 mcnet:text-xs ${LEVEL_CLASS[problem.level]}`}
        >
            {BADGES[problem.code] ?? problem.code}
        </span>
    );
}

const STATE_DOT: Record<string, string> = {
    running: 'mcnet:bg-green-500',
    starting: 'mcnet:bg-amber-500',
    stopping: 'mcnet:bg-amber-500',
    offline: 'mcnet:bg-red-500',
};

function BackendRow({
    entry,
    analysis,
    state,
    canEdit,
    onEdit,
    onRemove,
}: {
    entry: Entry;
    analysis: Analysis | null;
    state: string | null;
    canEdit: boolean;
    onEdit: () => void;
    onRemove: () => void;
}) {
    const problems = analysis?.problems ?? [];
    const plain = plainMotd(entry.motd);
    const match = analysis?.server ?? null;

    return (
        <>
            <tr className={'mcnet:border-t mcnet:border-border mcnet:align-top'}>
                <td className={'mcnet:px-3 mcnet:py-2 mcnet:font-medium mcnet:text-foreground'}>
                    {entry.name}
                    {entry.restricted && (
                        <span
                            title={'Only players with the permission can join'}
                            className={'mcnet:ml-2 mcnet:text-xs mcnet:font-normal mcnet:text-muted-foreground'}
                        >
                            Restricted
                        </span>
                    )}
                </td>
                <td className={'mcnet:px-3 mcnet:py-2 mcnet:font-mono mcnet:text-xs mcnet:text-foreground'}>{entry.address}</td>
                <td className={'mcnet:max-w-64 mcnet:px-3 mcnet:py-2 mcnet:text-muted-foreground'}>{plain === '' ? '-' : plain}</td>
                <td className={'mcnet:px-3 mcnet:py-2'}>
                    {match ? (
                        <div className={'mcnet:flex mcnet:items-center mcnet:gap-2'}>
                            <span
                                title={state ?? 'State unknown'}
                                role={'img'}
                                aria-label={state ?? 'State unknown'}
                                className={`mcnet:size-2 mcnet:shrink-0 mcnet:rounded-full ${state ? (STATE_DOT[state] ?? 'mcnet:bg-muted-foreground') : 'mcnet:bg-muted-foreground'}`}
                            />
                            <PanelLink
                                destination={{ to: '/server/$id', params: { id: match.id } }}
                                className={'mcnet:text-foreground mcnet:hover:underline'}
                            >
                                {match.name}
                            </PanelLink>
                            <span className={'mcnet:text-xs mcnet:text-muted-foreground'}>{state ?? 'unknown'}</span>
                        </div>
                    ) : (
                        <span className={'mcnet:text-muted-foreground'}>{analysis?.local === false ? 'Other host' : '-'}</span>
                    )}
                </td>
                {canEdit && (
                    <td className={'mcnet:whitespace-nowrap mcnet:px-3 mcnet:py-2 mcnet:text-right'}>
                        <Button.Text size={'xsmall'} aria-label={`Edit ${entry.name}`} onClick={onEdit}>
                            Edit
                        </Button.Text>
                        <Button.Danger size={'xsmall'} isSecondary aria-label={`Remove ${entry.name}`} onClick={onRemove}>
                            Remove
                        </Button.Danger>
                    </td>
                )}
            </tr>
            {problems.length > 0 && (
                <tr>
                    <td colSpan={canEdit ? 5 : 4} className={'mcnet:px-3 mcnet:pb-2'}>
                        <ul className={'mcnet:flex mcnet:flex-col mcnet:gap-1'}>
                            {problems.map((problem) => (
                                <li
                                    key={problem.code}
                                    className={
                                        'mcnet:flex mcnet:flex-wrap mcnet:items-center mcnet:gap-2 mcnet:text-xs mcnet:text-muted-foreground'
                                    }
                                >
                                    <Badge problem={problem} />
                                    {problem.message}
                                </li>
                            ))}
                        </ul>
                    </td>
                </tr>
            )}
        </>
    );
}

function GlobalRow({
    problem,
    names,
    canEdit,
    onFix,
}: {
    problem: GlobalProblem;
    names: string[];
    canEdit: boolean;
    onFix: (change: (draft: Draft) => Draft) => void;
}) {
    const similar = names.find((name) => name.toLowerCase() === problem.name.toLowerCase() && name !== problem.name);
    const forced = problem.code === 'unknown_forced_host';

    return (
        <li className={'mcnet:flex mcnet:flex-wrap mcnet:items-center mcnet:justify-between mcnet:gap-2 mcnet:px-3 mcnet:py-2'}>
            <p className={'mcnet:text-sm mcnet:text-foreground'}>{problem.message}</p>
            {canEdit && problem.code !== 'no_priorities' && (
                <div className={'mcnet:flex mcnet:gap-2'}>
                    {similar && (
                        <Button
                            size={'xsmall'}
                            isSecondary
                            onClick={() =>
                                onFix((draft) =>
                                    forced
                                        ? {
                                              ...draft,
                                              forced_hosts: draft.forced_hosts.map((item) =>
                                                  item.server === problem.name ? { ...item, server: similar } : item,
                                              ),
                                          }
                                        : {
                                              ...draft,
                                              priorities: draft.priorities.map((name) => (name === problem.name ? similar : name)),
                                          },
                                )
                            }
                        >
                            Use {similar}
                        </Button>
                    )}
                    <Button
                        size={'xsmall'}
                        isSecondary
                        onClick={() =>
                            onFix((draft) =>
                                forced
                                    ? { ...draft, forced_hosts: draft.forced_hosts.filter((item) => item.server !== problem.name) }
                                    : { ...draft, priorities: draft.priorities.filter((name) => name !== problem.name) },
                            )
                        }
                    >
                        {forced ? 'Remove forced host' : 'Remove from list'}
                    </Button>
                </div>
            )}
        </li>
    );
}

function Priorities({
    priorities,
    names,
    canEdit,
    onChange,
}: {
    priorities: string[];
    names: string[];
    canEdit: boolean;
    onChange: (priorities: string[]) => void;
}) {
    const available = names.filter((name) => !priorities.includes(name));

    return (
        <TitledGreyBox title={'Priorities'}>
            <div className={'mcnet:flex mcnet:flex-col mcnet:gap-3'}>
                <p className={'mcnet:text-sm mcnet:text-muted-foreground'}>
                    New players join the first server that is online. The first one is the default and the fallback when a server stops.
                </p>
                {priorities.length === 0 ? (
                    <p className={'mcnet:text-sm mcnet:text-muted-foreground'}>The list is empty.</p>
                ) : (
                    <ol
                        className={
                            'mcnet:flex mcnet:flex-col mcnet:divide-y mcnet:divide-border mcnet:rounded-md mcnet:border mcnet:border-border'
                        }
                    >
                        {priorities.map((name, index) => (
                            <li
                                key={name}
                                className={'mcnet:flex mcnet:items-center mcnet:justify-between mcnet:gap-2 mcnet:px-3 mcnet:py-1.5'}
                            >
                                <span className={'mcnet:text-sm mcnet:text-foreground'}>
                                    {index + 1}. {name}
                                    {index === 0 && <span className={'mcnet:ml-2 mcnet:text-xs mcnet:text-muted-foreground'}>Default</span>}
                                    {!names.includes(name) && (
                                        <span className={'mcnet:ml-2 mcnet:text-xs mcnet:text-red-400'}>Missing</span>
                                    )}
                                </span>
                                {canEdit && (
                                    <span className={'mcnet:flex mcnet:items-center'}>
                                        <Button.Text
                                            size={'xsmall'}
                                            aria-label={`Move ${name} up`}
                                            disabled={index === 0}
                                            onClick={() => onChange(moveItem(priorities, index, -1))}
                                        >
                                            ↑
                                        </Button.Text>
                                        <Button.Text
                                            size={'xsmall'}
                                            aria-label={`Move ${name} down`}
                                            disabled={index === priorities.length - 1}
                                            onClick={() => onChange(moveItem(priorities, index, 1))}
                                        >
                                            ↓
                                        </Button.Text>
                                        <Button.Danger
                                            size={'xsmall'}
                                            isSecondary
                                            aria-label={`Remove ${name} from priorities`}
                                            onClick={() => onChange(priorities.filter((other) => other !== name))}
                                        >
                                            Remove
                                        </Button.Danger>
                                    </span>
                                )}
                            </li>
                        ))}
                    </ol>
                )}
                {canEdit && available.length > 0 && (
                    <div className={'mcnet:w-56'}>
                        <Select
                            placeholder={'Add to the list…'}
                            value={null}
                            options={available.map((name) => ({ value: name, label: name }))}
                            onChange={(name) => onChange([...priorities, String(name)])}
                        />
                    </div>
                )}
            </div>
        </TitledGreyBox>
    );
}

function ForcedHosts({
    hosts,
    names,
    canEdit,
    onChange,
}: {
    hosts: { host: string; server: string }[];
    names: string[];
    canEdit: boolean;
    onChange: (hosts: { host: string; server: string }[]) => void;
}) {
    const change = (index: number, patch: Partial<{ host: string; server: string }>) =>
        onChange(hosts.map((item, position) => (position === index ? { ...item, ...patch } : item)));
    const invalid = badForcedHosts(hosts);

    return (
        <TitledGreyBox title={'Forced hosts'}>
            <div className={'mcnet:flex mcnet:flex-col mcnet:gap-3'}>
                <p className={'mcnet:text-sm mcnet:text-muted-foreground'}>
                    Players who join with this address go straight to the server, for example survival.example.com.
                </p>
                {hosts.length === 0 && <p className={'mcnet:text-sm mcnet:text-muted-foreground'}>No forced hosts.</p>}
                {hosts.map((item, index) => {
                    const bad = invalid.includes(index);
                    const options = names.includes(item.server) || item.server === '' ? names : [...names, item.server];

                    return (
                        <div key={index} className={'mcnet:flex mcnet:flex-wrap mcnet:items-start mcnet:gap-2'}>
                            <div className={'mcnet:min-w-0 mcnet:flex-1 mcnet:basis-48'}>
                                <Input
                                    value={item.host}
                                    disabled={!canEdit}
                                    placeholder={'play.example.com'}
                                    aria-label={`Forced host ${index + 1}`}
                                    $hasError={bad}
                                    maxLength={253}
                                    onChange={(event) => change(index, { host: event.target.value.trim() })}
                                />
                            </div>
                            <div className={'mcnet:w-44'}>
                                <Select
                                    value={item.server === '' ? null : item.server}
                                    placeholder={'Server'}
                                    disabled={!canEdit}
                                    options={options.map((name) => ({
                                        value: name,
                                        label: names.includes(name) ? name : `${name} (missing)`,
                                    }))}
                                    onChange={(name) => change(index, { server: String(name) })}
                                />
                            </div>
                            {canEdit && (
                                <Button.Danger
                                    size={'xsmall'}
                                    isSecondary
                                    aria-label={`Remove forced host ${item.host || index + 1}`}
                                    onClick={() => onChange(hosts.filter((_, position) => position !== index))}
                                >
                                    Remove
                                </Button.Danger>
                            )}
                        </div>
                    );
                })}
                {canEdit && (
                    <div>
                        <Button
                            size={'small'}
                            isSecondary
                            disabled={names.length === 0}
                            onClick={() => onChange([...hosts, { host: '', server: names[0] ?? '' }])}
                        >
                            Add forced host
                        </Button>
                    </div>
                )}
                {hosts.some((item) => item.host === '') && (
                    <p className={'mcnet:text-xs mcnet:text-muted-foreground'}>Empty hosts are not saved.</p>
                )}
            </div>
        </TitledGreyBox>
    );
}

function EntryDialog({
    editing,
    others,
    onClose,
    onApply,
}: {
    editing: Editing;
    others: string[];
    onClose: () => void;
    onApply: (entry: Entry) => void;
}) {
    const [entry, setEntry] = useState<Entry>(editing.entry);
    const [submitted, setSubmitted] = useState(false);
    const errors = validateEntry(entry, others);
    const invalid = Object.keys(errors).length > 0;
    const isNew = editing.index === null;
    const show = (field: keyof typeof errors) => (submitted ? errors[field] : undefined);

    return (
        <Dialog.Confirm
            open
            title={isNew ? 'Add a backend server' : `Edit ${editing.entry.name}`}
            confirm={isNew ? 'Add' : 'Apply'}
            onClose={onClose}
            onConfirmed={() => {
                if (invalid) {
                    setSubmitted(true);
                    return;
                }
                onApply({ ...entry, address: entry.address.trim() });
            }}
        >
            <div className={'mcnet:flex mcnet:flex-col mcnet:gap-3'}>
                <Field
                    label={'Name'}
                    error={show('name')}
                    help={'What players type in /server. Renaming also updates the priorities and forced hosts.'}
                >
                    <Input
                        value={entry.name}
                        maxLength={32}
                        $hasError={show('name') !== undefined}
                        aria-label={'Name'}
                        onChange={(event) => setEntry({ ...entry, name: event.target.value.trim() })}
                    />
                </Field>
                <Field label={'Address'} error={show('address')} help={'The host and port of the server, for example localhost:25566.'}>
                    <Input
                        value={entry.address}
                        maxLength={300}
                        $hasError={show('address') !== undefined}
                        aria-label={'Address'}
                        onChange={(event) => setEntry({ ...entry, address: event.target.value })}
                    />
                </Field>
                <Field
                    label={'Description'}
                    error={show('motd')}
                    help={
                        plainMotd(entry.motd) === ''
                            ? 'Shown in the server list. Colour codes like &a are allowed.'
                            : `Shown as: ${plainMotd(entry.motd)}`
                    }
                >
                    <Input
                        value={entry.motd}
                        maxLength={256}
                        $hasError={show('motd') !== undefined}
                        aria-label={'Description'}
                        onChange={(event) => setEntry({ ...entry, motd: event.target.value })}
                    />
                </Field>
                <Switch
                    checked={entry.restricted}
                    onChange={(restricted) => setEntry({ ...entry, restricted })}
                    label={'Restricted'}
                    description={'Only players with the bungeecord.server.<name> permission can join.'}
                />
                <p className={'mcnet:text-xs mcnet:text-muted-foreground'}>Nothing is saved until you press Save.</p>
            </div>
        </Dialog.Confirm>
    );
}

function Field({ label, error, help, children }: { label: string; error?: string; help: string; children: ReactNode }) {
    return (
        <div className={'mcnet:flex mcnet:flex-col mcnet:gap-1'}>
            <span className={'mcnet:text-sm mcnet:font-medium mcnet:text-foreground'}>{label}</span>
            {children}
            <span className={`mcnet:text-xs ${error ? 'mcnet:text-red-400' : 'mcnet:text-muted-foreground'}`}>{error ?? help}</span>
        </div>
    );
}
