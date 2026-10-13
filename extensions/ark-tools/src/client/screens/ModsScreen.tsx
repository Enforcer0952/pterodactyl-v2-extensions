import { useEffect, useMemo, useState, type FormEvent, type ReactNode } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
    Alert,
    Button,
    Dialog,
    Input,
    ServerContentBlock,
    Spinner,
    httpErrorToHuman,
    toast,
    useCurrentServerRequired,
} from '@pterodactyl/sdk';
import { deleteModFiles, getMods, keys, lookupMods, parseModInput, saveMods, type Mod, type ModsResponse, type WorkshopItem } from '../api';

const WORKSHOP = 'https://steamcommunity.com/app/346110/workshop/';

export default function ModsScreen() {
    const server = useCurrentServerRequired();
    const identifier = server.attributes.identifier;
    const queryClient = useQueryClient();
    const mods = useQuery({ queryKey: keys.mods(identifier), queryFn: () => getMods(identifier) });

    // The working copy of the list; saved explicitly so several changes become one edit.
    const [order, setOrder] = useState<string[] | null>(null);
    const [added, setAdded] = useState<Record<string, WorkshopItem>>({});
    const [confirmDelete, setConfirmDelete] = useState<Mod | null>(null);

    const saved = useMemo(() => (mods.data?.mods ?? []).map((mod) => mod.id), [mods.data]);
    const current = order ?? saved;
    const dirty = order !== null && order.join(',') !== saved.join(',');

    useEffect(() => {
        if (!dirty) return;
        const warn = (event: BeforeUnloadEvent) => event.preventDefault();
        window.addEventListener('beforeunload', warn);
        return () => window.removeEventListener('beforeunload', warn);
    }, [dirty]);

    const onSaved = (result: ModsResponse) => {
        queryClient.setQueryData(keys.mods(identifier), result);
        setOrder(null);
        setAdded({});
    };

    const save = useMutation({
        mutationFn: () => saveMods(identifier, current),
        onSuccess: (result) => {
            onSaved(result);
            toast.success(
                result.state === 'running' ? 'Mod list saved. Restart the server to download and load the changes.' : 'Mod list saved.',
            );
        },
        onError: (error) => toast.error(httpErrorToHuman(error)),
    });

    const remove = useMutation({
        mutationFn: (id: string) => deleteModFiles(identifier, id),
        onSuccess: (result) => {
            queryClient.setQueryData(keys.mods(identifier), result);
            setConfirmDelete(null);
            toast.success('Mod files deleted.');
        },
        onError: (error) => toast.error(httpErrorToHuman(error)),
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
    const byId = new Map<string, Mod | WorkshopItem>([...data.unlisted, ...data.mods].map((mod) => [mod.id, mod]));
    for (const [id, item] of Object.entries(added)) if (!byId.has(id)) byId.set(id, item);

    const edit = (change: (ids: string[]) => string[]) => setOrder(change([...current]));
    const move = (index: number, step: number) =>
        edit((ids) => {
            const target = index + step;
            if (target < 0 || target >= ids.length) return ids;
            [ids[index], ids[target]] = [ids[target], ids[index]];
            return ids;
        });

    const updates = data.mods.filter((mod) => mod.status === 'update_available').length;
    const missing = data.mods.filter((mod) => mod.status === 'not_downloaded').length;
    const unlisted = data.unlisted.filter((mod) => !current.includes(mod.id));

    return (
        <ServerContentBlock title={'Mods'}>
            <div className={'ark:flex ark:flex-col ark:gap-4'}>
                {!data.editable && <Alert type={'warning'}>The MOD_ID startup variable is read-only on this server, so the list cannot be changed here.</Alert>}

                {(updates > 0 || missing > 0) && !dirty && (
                    <Alert type={'info'}>
                        {[
                            updates > 0 && `${updates} mod${updates === 1 ? ' has an update' : 's have updates'} on the Workshop`,
                            missing > 0 && `${missing} mod${missing === 1 ? ' is' : 's are'} not downloaded yet`,
                        ]
                            .filter(Boolean)
                            .join(' and ')}
                        . The server downloads them when it starts, so a restart takes care of it.
                    </Alert>
                )}

                {data.editable && (
                    <AddMods
                        identifier={identifier}
                        current={current}
                        max={data.max}
                        onAdd={(items) => {
                            setAdded((previous) => ({ ...previous, ...Object.fromEntries(items.map((item) => [item.id, item])) }));
                            edit((ids) => [...ids, ...items.map((item) => item.id).filter((id) => !ids.includes(id))]);
                        }}
                    />
                )}

                <div className={'ark:flex ark:flex-col ark:gap-2'}>
                    <div className={'ark:flex ark:items-center ark:justify-between ark:gap-2'}>
                        <p className={'ark:text-sm ark:text-muted-foreground'}>
                            {current.length} mod{current.length === 1 ? '' : 's'} · loaded top to bottom; when two mods change the same thing, the higher one wins.
                        </p>
                        {dirty && (
                            <div className={'ark:flex ark:gap-2'}>
                                <Button.Text size={'small'} disabled={save.isPending} onClick={() => setOrder(null)}>
                                    Undo changes
                                </Button.Text>
                                <Button size={'small'} isLoading={save.isPending} onClick={() => save.mutate()}>
                                    Save
                                </Button>
                            </div>
                        )}
                    </div>

                    {current.length === 0 ? (
                        <p className={'ark:py-6 ark:text-center ark:text-sm ark:text-muted-foreground'}>
                            No mods yet. Add Workshop ids or links above, or browse the{' '}
                            <a href={WORKSHOP} target={'_blank'} rel={'noreferrer'} className={'ark:underline'}>
                                ARK Workshop
                            </a>
                            .
                        </p>
                    ) : (
                        <ol className={'ark:flex ark:flex-col ark:divide-y ark:divide-border ark:rounded-md ark:border ark:border-border'}>
                            {current.map((id, index) => (
                                <ModRow
                                    key={id}
                                    position={index + 1}
                                    item={byId.get(id) ?? null}
                                    id={id}
                                    pendingSave={!saved.includes(id)}
                                >
                                    {data.editable && (
                                        <>
                                            <Button.Text size={'xsmall'} aria-label={`Move ${id} up`} disabled={index === 0} onClick={() => move(index, -1)}>
                                                ↑
                                            </Button.Text>
                                            <Button.Text
                                                size={'xsmall'}
                                                aria-label={`Move ${id} down`}
                                                disabled={index === current.length - 1}
                                                onClick={() => move(index, 1)}
                                            >
                                                ↓
                                            </Button.Text>
                                            <Button.Danger size={'xsmall'} isSecondary onClick={() => edit((ids) => ids.filter((other) => other !== id))}>
                                                Remove
                                            </Button.Danger>
                                        </>
                                    )}
                                </ModRow>
                            ))}
                        </ol>
                    )}
                </div>

                {unlisted.length > 0 && (
                    <div className={'ark:flex ark:flex-col ark:gap-2'}>
                        <p className={'ark:text-sm ark:font-medium ark:text-foreground'}>Downloaded but not loaded</p>
                        <p className={'ark:text-xs ark:text-muted-foreground'}>
                            These are in ShooterGame/Content/Mods but not in the mod list. Add them back, or delete the files to free up space.
                        </p>
                        <ul className={'ark:flex ark:flex-col ark:divide-y ark:divide-border ark:rounded-md ark:border ark:border-border'}>
                            {unlisted.map((mod) => (
                                <ModRow key={mod.id} item={mod} id={mod.id}>
                                    {data.editable && (
                                        <Button.Text size={'xsmall'} onClick={() => edit((ids) => [...ids, mod.id])}>
                                            Add to list
                                        </Button.Text>
                                    )}
                                    {data.can_delete_files && (
                                        <Button.Danger size={'xsmall'} isSecondary onClick={() => setConfirmDelete(mod)}>
                                            Delete files
                                        </Button.Danger>
                                    )}
                                </ModRow>
                            ))}
                        </ul>
                    </div>
                )}
            </div>

            {confirmDelete && (
                <Dialog.Confirm
                    open
                    title={`Delete ${confirmDelete.title ?? confirmDelete.id}?`}
                    confirm={'Delete'}
                    pending={remove.isPending}
                    onClose={() => setConfirmDelete(null)}
                    onConfirmed={() => remove.mutate(confirmDelete.id)}
                >
                    <p className={'ark:text-sm'}>
                        Deletes {confirmDelete.id}.mod and the {confirmDelete.id} folder from ShooterGame/Content/Mods. Anything this mod added to the
                        world is lost if it is loaded again later without them.
                    </p>
                </Dialog.Confirm>
            )}
        </ServerContentBlock>
    );
}

function AddMods({
    identifier,
    current,
    max,
    onAdd,
}: {
    identifier: string;
    current: string[];
    max: number;
    onAdd: (items: WorkshopItem[]) => void;
}) {
    const [value, setValue] = useState('');
    const [error, setError] = useState<string | null>(null);

    const lookup = useMutation({
        mutationFn: (ids: string[]) => lookupMods(identifier, ids),
        onSuccess: (items) => {
            const good = items.filter((item) => item.found && (item.app_id === 346110 || item.app_id === 0));
            const bad = items.filter((item) => !good.includes(item));
            if (good.length > 0) onAdd(good);
            setError(
                bad.length > 0
                    ? `Not added: ${bad.map((item) => (item.found ? `${item.title ?? item.id} is not an ARK: Survival Evolved mod` : `${item.id} was not found on the Workshop`)).join('; ')}.`
                    : null,
            );
            if (bad.length === 0) setValue('');
        },
        onError: (failure) => setError(httpErrorToHuman(failure)),
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const ids = parseModInput(value).filter((id) => !current.includes(id));
        if (ids.length === 0) {
            setError(parseModInput(value).length > 0 ? 'Those mods are already in the list.' : 'Paste a Workshop id or link.');
            return;
        }
        if (current.length + ids.length > max) {
            setError(`A server can have at most ${max} mods here.`);
            return;
        }
        lookup.mutate(ids.slice(0, 20));
    };

    return (
        <form onSubmit={submit} className={'ark:flex ark:flex-col ark:gap-1'}>
            <div className={'ark:flex ark:gap-2'}>
                <Input
                    value={value}
                    placeholder={'Workshop id or link, several separated by commas'}
                    aria-label={'Workshop id or link'}
                    onChange={(event) => setValue(event.target.value)}
                />
                <Button size={'small'} type={'submit'} isLoading={lookup.isPending} disabled={value.trim() === ''}>
                    Add
                </Button>
            </div>
            {error ? (
                <p className={'ark:text-xs ark:text-destructive'}>{error}</p>
            ) : (
                <p className={'ark:text-xs ark:text-muted-foreground'}>
                    Find mods on the{' '}
                    <a href={WORKSHOP} target={'_blank'} rel={'noreferrer'} className={'ark:underline'}>
                        ARK Workshop
                    </a>{' '}
                    and paste the page link here.
                </p>
            )}
        </form>
    );
}

const STATUS: Record<Mod['status'], { label: string; tone: string }> = {
    up_to_date: { label: 'Up to date', tone: 'ark:text-muted-foreground' },
    update_available: { label: 'Update on restart', tone: 'ark:text-warning' },
    not_downloaded: { label: 'Downloads on restart', tone: 'ark:text-primary' },
    downloading: { label: 'Downloading', tone: 'ark:text-primary' },
};

function ModRow({
    id,
    item,
    position,
    pendingSave,
    children,
}: {
    id: string;
    item: Mod | WorkshopItem | null;
    position?: number;
    pendingSave?: boolean;
    children?: ReactNode;
}) {
    const mod = item && 'status' in item ? item : null;
    const title = item?.title ?? (item && !item.found ? 'Not found on the Workshop' : id);
    const status = pendingSave ? { label: 'Not saved yet', tone: 'ark:text-primary' } : mod && mod.listed ? STATUS[mod.status] : null;

    return (
        <li className={'ark:flex ark:items-center ark:gap-3 ark:px-3 ark:py-2'}>
            {position !== undefined && <span className={'ark:w-6 ark:shrink-0 ark:text-right ark:text-xs ark:text-muted-foreground'}>{position}</span>}
            {item?.preview_url ? (
                <img src={item.preview_url} alt={''} loading={'lazy'} className={'ark:size-10 ark:shrink-0 ark:rounded-md ark:bg-muted ark:object-cover'} />
            ) : (
                <div aria-hidden className={'ark:size-10 ark:shrink-0 ark:rounded-md ark:bg-muted'} />
            )}
            <div className={'ark:min-w-0 ark:flex-1'}>
                <a
                    href={item?.url ?? `https://steamcommunity.com/sharedfiles/filedetails/?id=${id}`}
                    target={'_blank'}
                    rel={'noreferrer'}
                    className={'ark:block ark:truncate ark:text-sm ark:font-medium ark:text-foreground ark:hover:underline'}
                >
                    {title}
                </a>
                <p className={'ark:truncate ark:text-xs ark:text-muted-foreground'}>
                    {[
                        id,
                        item?.updated_at ? `updated ${new Date(item.updated_at * 1000).toLocaleDateString()}` : null,
                        item?.size ? formatSize(item.size) : null,
                        mod?.wrong_game ? 'not an ARK: Survival Evolved mod' : null,
                    ]
                        .filter(Boolean)
                        .join(' · ')}
                </p>
            </div>
            {status && <span className={`ark:hidden ark:shrink-0 ark:text-xs ark:sm:inline ${status.tone}`}>{status.label}</span>}
            <div className={'ark:flex ark:shrink-0 ark:items-center ark:gap-1'}>{children}</div>
        </li>
    );
}

function formatSize(bytes: number): string {
    if (bytes >= 1024 ** 3) return `${(bytes / 1024 ** 3).toFixed(1)} GB`;
    if (bytes >= 1024 ** 2) return `${Math.round(bytes / 1024 ** 2)} MB`;
    return `${Math.max(1, Math.round(bytes / 1024))} KB`;
}
