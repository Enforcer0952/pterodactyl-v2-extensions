import { useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
    Alert,
    Button,
    Dialog,
    Input,
    Select,
    ServerContentBlock,
    Spinner,
    TitledGreyBox,
    httpErrorToHuman,
    toast,
    useCurrentServerRequired,
    useServerPermission,
} from '@pterodactyl/sdk';
import {
    getModUpdates,
    getMods,
    installMod,
    keys,
    removeMod,
    searchMods,
    type InstalledMod,
    type InstallResult,
    type ModsResponse,
    type Ordering,
    type SearchResult,
} from '../api';
import { formatBytes, formatCount, formatDate } from '../format';
import { Empty, Row, Rows } from '../shared';

type Tab = 'installed' | 'find';

const ORDERINGS: { value: Ordering; label: string }[] = [
    { value: 'most-downloaded', label: 'Most downloaded' },
    { value: 'top-rated', label: 'Top rated' },
    { value: 'last-updated', label: 'Recently updated' },
    { value: 'newest', label: 'Newest' },
];

export default function ModsScreen() {
    const server = useCurrentServerRequired();
    const identifier = server.attributes.identifier;
    const queryClient = useQueryClient();
    const [tab, setTab] = useState<Tab>('installed');
    const [removing, setRemoving] = useState<InstalledMod | null>(null);

    const can = {
        install: [
            useServerPermission('file.create'),
            useServerPermission('file.update'),
            useServerPermission('file.delete'),
            useServerPermission('file.archive'),
        ].every(Boolean),
        remove: useServerPermission('file.delete'),
    };

    const mods = useQuery({ queryKey: keys.mods(identifier), queryFn: () => getMods(identifier), refetchOnWindowFocus: true });
    // Update checks ask Thunderstore once per mod, so they load after the list is on screen.
    const updates = useQuery({
        queryKey: keys.updates(identifier),
        queryFn: () => getModUpdates(identifier),
        enabled: mods.data?.available === true && (mods.data?.mods.length ?? 0) > 0,
        staleTime: 300_000,
        retry: false,
    });

    const finished = (result: InstallResult) => {
        queryClient.setQueryData<ModsResponse>(keys.mods(identifier), result);
        void queryClient.invalidateQueries({ queryKey: keys.updates(identifier) });
    };

    const install = useMutation({
        mutationFn: (input: { pkg: string; version?: string }) => installMod(identifier, input.pkg, input.version),
        onSuccess: (result) => {
            finished(result);
            const list = result.result?.installed ?? [];
            toast.success(
                list.length === 1
                    ? `${list[0].package} ${list[0].version} is installed. Start the server to load it.`
                    : `Installed ${list.map((item) => item.package).join(', ')}. Start the server to load them.`,
            );
            for (const note of result.result?.notes ?? []) toast.info(note);
        },
        onError: (error) => toast.error(httpErrorToHuman(error)),
    });

    const remove = useMutation({
        mutationFn: (mod: InstalledMod) => removeMod(identifier, mod.kind, mod.folder),
        onSuccess: (result) => {
            queryClient.setQueryData<ModsResponse>(keys.mods(identifier), result);
            toast.success('Mod removed.');
            setRemoving(null);
        },
        onError: (error) => {
            toast.error(httpErrorToHuman(error));
            setRemoving(null);
        },
    });

    if (mods.isLoading) {
        return (
            <ServerContentBlock title={'Mods'}>
                <Spinner centered />
            </ServerContentBlock>
        );
    }

    if (mods.error || !mods.data) {
        return (
            <ServerContentBlock title={'Mods'}>
                <Alert type={'danger'}>{httpErrorToHuman(mods.error)}</Alert>
            </ServerContentBlock>
        );
    }

    const data = mods.data;
    const stopped = data.state === 'offline';
    const installing = install.isPending;

    if (!data.available) {
        return (
            <ServerContentBlock title={'Mods'}>
                <Alert type={'info'}>
                    This server has no BepInEx folder. Use an egg that installs BepInEx for Valheim, then come back to add mods here.
                </Alert>
            </ServerContentBlock>
        );
    }

    return (
        <ServerContentBlock title={'Mods'}>
            <div className={'vh:flex vh:flex-col vh:gap-4'}>
                {!stopped && <Alert type={'info'}>The server is {data.state}. Stop it to install, update or remove mods.</Alert>}
                {installing && (
                    <Alert type={'info'}>Installing. This downloads the mod and the mods it needs, and can take a minute.</Alert>
                )}

                <div className={'vh:flex vh:gap-1 vh:self-start vh:rounded-md vh:border vh:border-border vh:p-1'} role={'tablist'}>
                    <Button
                        size={'xsmall'}
                        isSecondary={tab !== 'installed'}
                        role={'tab'}
                        aria-selected={tab === 'installed'}
                        onClick={() => setTab('installed')}
                    >
                        {`Installed (${data.mods.length})`}
                    </Button>
                    <Button
                        size={'xsmall'}
                        isSecondary={tab !== 'find'}
                        role={'tab'}
                        aria-selected={tab === 'find'}
                        onClick={() => setTab('find')}
                    >
                        Find on Thunderstore
                    </Button>
                </div>

                {tab === 'installed' ? (
                    <TitledGreyBox title={'Installed mods'}>
                        {data.mods.length === 0 ? (
                            <Empty>No mods in BepInEx/plugins yet. Find one on Thunderstore.</Empty>
                        ) : (
                            <Rows tall>
                                {data.mods.map((mod) => {
                                    const update = mod.package ? updates.data?.updates[mod.package] : undefined;
                                    return (
                                        <Row
                                            key={mod.id}
                                            name={mod.name}
                                            badge={mod.kind === 'plugins' ? undefined : mod.kind}
                                            detail={[
                                                mod.version ? `v${mod.version}` : mod.file ? 'single file' : 'no manifest',
                                                mod.owner ? `by ${mod.owner}` : '',
                                                mod.file ? formatBytes(mod.size) : '',
                                                update?.update ? `${update.latest} is available` : '',
                                            ]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        >
                                            {update?.update && mod.package && can.install && (
                                                <Button
                                                    size={'xsmall'}
                                                    disabled={!stopped || installing}
                                                    onClick={() => install.mutate({ pkg: mod.package as string, version: update.latest })}
                                                >
                                                    Update
                                                </Button>
                                            )}
                                            {can.remove && (
                                                <Button.Danger
                                                    size={'xsmall'}
                                                    isSecondary
                                                    disabled={!stopped || installing || remove.isPending}
                                                    onClick={() => setRemoving(mod)}
                                                >
                                                    Remove
                                                </Button.Danger>
                                            )}
                                        </Row>
                                    );
                                })}
                            </Rows>
                        )}
                        <p className={'vh:pt-2 vh:text-xs vh:text-muted-foreground'}>
                            BepInEx itself is never changed here. Mod settings in BepInEx/config stay when a mod is updated or removed.
                        </p>
                    </TitledGreyBox>
                ) : (
                    <Finder
                        identifier={identifier}
                        canInstall={can.install}
                        stopped={stopped}
                        installing={installing}
                        installed={data.mods}
                        onInstall={(pkg) => install.mutate({ pkg })}
                    />
                )}
            </div>

            {removing && (
                <Dialog.Confirm
                    open
                    title={`Remove ${removing.name}?`}
                    confirm={'Remove'}
                    pending={remove.isPending}
                    onClose={() => setRemoving(null)}
                    onConfirmed={() => remove.mutate(removing)}
                >
                    <p className={'vh:text-sm'}>
                        The {removing.file ? 'file' : 'folder'} BepInEx/{removing.kind}/{removing.folder} is deleted. Mods that need it stop
                        working.
                    </p>
                </Dialog.Confirm>
            )}
        </ServerContentBlock>
    );
}

function Finder({
    identifier,
    canInstall,
    stopped,
    installing,
    installed,
    onInstall,
}: {
    identifier: string;
    canInstall: boolean;
    stopped: boolean;
    installing: boolean;
    installed: InstalledMod[];
    onInstall: (pkg: string) => void;
}) {
    const [text, setText] = useState('');
    const [query, setQuery] = useState('');
    const [ordering, setOrdering] = useState<Ordering>('most-downloaded');
    const [page, setPage] = useState(1);
    const [direct, setDirect] = useState('');

    const search = useQuery({
        queryKey: keys.search(identifier, query, ordering, page),
        queryFn: () => searchMods(identifier, query, ordering, page),
        staleTime: 300_000,
        retry: false,
    });

    const have = new Set(installed.map((mod) => mod.package?.toLowerCase()).filter(Boolean));
    const submit = (event: FormEvent) => {
        event.preventDefault();
        setPage(1);
        setQuery(text.trim());
    };

    const installDirect = (event: FormEvent) => {
        event.preventDefault();
        if (direct.trim() !== '') {
            onInstall(direct.trim());
            setDirect('');
        }
    };

    return (
        <div className={'vh:flex vh:flex-col vh:gap-4'}>
            <TitledGreyBox title={'Search Thunderstore'}>
                <div className={'vh:flex vh:flex-col vh:gap-3'}>
                    <form className={'vh:flex vh:flex-wrap vh:gap-2'} onSubmit={submit}>
                        <div className={'vh:min-w-48 vh:flex-1'}>
                            <Input
                                aria-label={'Search mods'}
                                value={text}
                                placeholder={'Search mods'}
                                onChange={(event) => setText(event.target.value)}
                            />
                        </div>
                        <div className={'vh:w-48'}>
                            <Select
                                options={ORDERINGS}
                                value={ordering}
                                onChange={(value) => {
                                    setOrdering(value as Ordering);
                                    setPage(1);
                                }}
                            />
                        </div>
                        <Button type={'submit'} size={'small'} isLoading={search.isFetching}>
                            Search
                        </Button>
                    </form>

                    {search.isLoading ? (
                        <Spinner centered size={'small'} />
                    ) : search.error || !search.data ? (
                        <Alert type={'danger'}>{httpErrorToHuman(search.error)}</Alert>
                    ) : search.data.results.length === 0 ? (
                        <Empty>Nothing found.</Empty>
                    ) : (
                        <Rows tall>
                            {search.data.results.map((item) => (
                                <ResultRow
                                    key={`${item.namespace}-${item.name}`}
                                    item={item}
                                    installed={have.has(`${item.namespace}-${item.name}`.toLowerCase())}
                                    canInstall={canInstall && stopped && !installing}
                                    onInstall={() => onInstall(`${item.namespace}-${item.name}`)}
                                />
                            ))}
                        </Rows>
                    )}

                    {search.data && (page > 1 || search.data.has_more) && (
                        <div className={'vh:flex vh:items-center vh:justify-between'}>
                            <Button size={'xsmall'} isSecondary disabled={page <= 1} onClick={() => setPage((current) => current - 1)}>
                                Previous
                            </Button>
                            <span className={'vh:text-xs vh:text-muted-foreground'}>Page {page}</span>
                            <Button
                                size={'xsmall'}
                                isSecondary
                                disabled={!search.data.has_more}
                                onClick={() => setPage((current) => current + 1)}
                            >
                                Next
                            </Button>
                        </div>
                    )}
                    <p className={'vh:text-xs vh:text-muted-foreground'}>
                        Mods marked Client-side only do nothing on a server. Installing also installs the mods a mod needs, except BepInEx.
                    </p>
                </div>
            </TitledGreyBox>

            {canInstall && (
                <TitledGreyBox title={'Install by name or link'}>
                    <form className={'vh:flex vh:gap-2'} onSubmit={installDirect}>
                        <Input
                            aria-label={'Mod name or link'}
                            value={direct}
                            maxLength={300}
                            placeholder={'Owner-Name, or a thunderstore.io link'}
                            onChange={(event) => setDirect(event.target.value)}
                        />
                        <Button type={'submit'} size={'small'} disabled={direct.trim() === '' || !stopped || installing}>
                            Install
                        </Button>
                    </form>
                </TitledGreyBox>
            )}
        </div>
    );
}

function ResultRow({
    item,
    installed,
    canInstall,
    onInstall,
}: {
    item: SearchResult;
    installed: boolean;
    canInstall: boolean;
    onInstall: () => void;
}) {
    const tags = [
        item.client_side && !item.server_side ? 'Client-side only' : item.server_side ? 'Server-side' : '',
        item.deprecated ? 'Deprecated' : '',
    ].filter(Boolean);

    return (
        <Row
            name={`${item.name} by ${item.namespace}`}
            badge={tags[0]}
            detail={[
                item.description,
                `${formatCount(item.downloads)} downloads`,
                item.updated ? `updated ${formatDate(item.updated)}` : '',
            ]
                .filter(Boolean)
                .join(' · ')}
        >
            {installed ? (
                <span className={'vh:text-xs vh:text-muted-foreground'}>Installed</span>
            ) : (
                canInstall && (
                    <Button size={'xsmall'} disabled={item.deprecated} onClick={onInstall}>
                        Install
                    </Button>
                )
            )}
        </Row>
    );
}
