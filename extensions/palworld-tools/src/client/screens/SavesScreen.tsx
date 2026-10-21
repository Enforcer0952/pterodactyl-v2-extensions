import { useState, type ReactNode } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
    Alert,
    Button,
    Dialog,
    ServerContentBlock,
    Spinner,
    TitledGreyBox,
    httpErrorToHuman,
    toast,
    useCurrentServerRequired,
    useServerPermission,
} from '@pterodactyl/sdk';
import {
    backupWorld,
    deleteBackup,
    getBans,
    getSaves,
    keys,
    removeBan,
    restoreBackup,
    restoreSnapshot,
    type BansResponse,
    type SavesResponse,
    type World,
    type WorldBackup,
} from '../api';
import { formatBytes, formatDate, parseStamp, shortId, snapshotDate } from '../format';

type Pending =
    | { kind: 'restore'; backup: WorldBackup }
    | { kind: 'delete-backup'; backup: WorldBackup }
    | { kind: 'snapshot'; world: World; snapshot: string; at: string }
    | { kind: 'unban'; id: string };

export default function SavesScreen() {
    const server = useCurrentServerRequired();
    const identifier = server.attributes.identifier;
    const queryClient = useQueryClient();
    const [pending, setPending] = useState<Pending | null>(null);

    const can = {
        backup: useServerPermission('file.archive') && useServerPermission('file.create') && useServerPermission('file.update'),
        restore:
            useServerPermission('file.archive') &&
            useServerPermission('file.create') &&
            useServerPermission('file.update') &&
            useServerPermission('file.delete'),
        delete: useServerPermission('file.delete'),
        update: useServerPermission('file.update'),
        read: useServerPermission('file.read'),
    };

    const saves = useQuery({
        queryKey: keys.saves(identifier),
        queryFn: () => getSaves(identifier),
        enabled: can.read,
        refetchOnWindowFocus: true,
    });
    const bans = useQuery({ queryKey: keys.bans(identifier), queryFn: () => getBans(identifier), enabled: can.read, retry: false });

    const done = (message: (result: SavesResponse) => string) => ({
        onSuccess: (result: SavesResponse) => {
            queryClient.setQueryData<SavesResponse>(keys.saves(identifier), result);
            toast.success(message(result));
            setPending(null);
        },
        onError: (error: unknown) => {
            toast.error(httpErrorToHuman(error));
            setPending(null);
        },
    });

    const backup = useMutation({
        mutationFn: (world: string) => backupWorld(identifier, world),
        onSuccess: (result) => {
            queryClient.setQueryData<SavesResponse>(keys.saves(identifier), result);
            if (result.result?.saved === false) toast.warning('The game did not confirm the save; the backup may be a few minutes old.');
            else toast.success('Backup made.');
            setPending(null);
        },
        onError: (error: unknown) => {
            toast.error(httpErrorToHuman(error));
            setPending(null);
        },
    });
    const restore = useMutation({
        mutationFn: (file: string) => restoreBackup(identifier, file),
        ...done((r) => `Restored. The old world was moved to ${r.result?.kept ?? 'palworld-backups/replaced'}.`),
    });
    const snapshot = useMutation({
        mutationFn: (input: { world: string; snapshot: string }) => restoreSnapshot(identifier, input.world, input.snapshot),
        ...done((r) => `Restored. The old world was moved to ${r.result?.kept ?? 'palworld-backups/replaced'}.`),
    });
    const remove = useMutation({ mutationFn: (file: string) => deleteBackup(identifier, file), ...done(() => 'Backup deleted.') });
    const unban = useMutation({
        mutationFn: (id: string) => removeBan(identifier, id),
        onSuccess: (result) => {
            queryClient.setQueryData<BansResponse>(keys.bans(identifier), result);
            toast.success('Ban removed.');
            setPending(null);
        },
        onError: (error) => {
            toast.error(httpErrorToHuman(error));
            setPending(null);
        },
    });

    const title = 'Palworld saves';

    if (!can.read) {
        return (
            <ServerContentBlock title={title}>
                <Alert type={'info'}>You need permission to see files to use this page.</Alert>
            </ServerContentBlock>
        );
    }

    if (saves.isLoading) {
        return (
            <ServerContentBlock title={title}>
                <Spinner centered />
            </ServerContentBlock>
        );
    }

    if (saves.error || !saves.data) {
        return (
            <ServerContentBlock title={title}>
                <Alert type={'danger'}>{httpErrorToHuman(saves.error)}</Alert>
            </ServerContentBlock>
        );
    }

    const data = saves.data;
    const stopped = data.state === 'offline';
    const busy = [backup, restore, snapshot, remove, unban].some((mutation) => mutation.isPending);

    return (
        <ServerContentBlock title={title}>
            <div className={'pal:flex pal:flex-col pal:gap-4'}>
                {!stopped && (
                    <Alert type={'info'}>
                        The server is {data.state}. Stop it to restore a world or remove a ban. A backup can be made while it runs: the
                        world is saved first when the REST API is on.
                    </Alert>
                )}

                <TitledGreyBox title={`Worlds (${data.worlds.length})`}>
                    {data.worlds.length === 0 ? (
                        <Empty>No world in {data.saves_directory} yet. Start the server once so the game creates it.</Empty>
                    ) : (
                        <ul className={'pal:flex pal:flex-col pal:divide-y pal:divide-border'}>
                            {data.worlds.map((world) => (
                                <WorldRow
                                    key={world.id}
                                    world={world}
                                    stopped={stopped}
                                    busy={busy}
                                    canBackup={can.backup}
                                    canRestore={can.restore}
                                    backingUp={backup.isPending && backup.variables === world.id}
                                    onBackup={() => backup.mutate(world.id)}
                                    onRestore={(name, at) => setPending({ kind: 'snapshot', world, snapshot: name, at })}
                                />
                            ))}
                        </ul>
                    )}
                </TitledGreyBox>

                <TitledGreyBox title={`Backups (${data.backups.length})`}>
                    <div className={'pal:flex pal:flex-col pal:gap-2'}>
                        <p className={'pal:text-xs pal:text-muted-foreground'}>
                            Kept in {data.directory}. Each world keeps its newest {data.keep} backups. A backup holds the world and its
                            players, not the game&apos;s own snapshots. Restoring moves the current world aside first.
                        </p>
                        {data.backups.length === 0 ? (
                            <Empty>No backups yet.</Empty>
                        ) : (
                            <ul className={'pal:flex pal:max-h-96 pal:flex-col pal:divide-y pal:divide-border pal:overflow-y-auto'}>
                                {data.backups.map((item) => (
                                    <Row
                                        key={item.file}
                                        name={shortId(item.world)}
                                        title={item.world}
                                        detail={[formatDate(parseStamp(item.stamp) ?? item.modified), formatBytes(item.size)]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    >
                                        {can.restore && (
                                            <Button
                                                size={'xsmall'}
                                                isSecondary
                                                disabled={busy || !stopped}
                                                onClick={() => setPending({ kind: 'restore', backup: item })}
                                            >
                                                Restore
                                            </Button>
                                        )}
                                        {can.delete && (
                                            <Button.Danger
                                                size={'xsmall'}
                                                isSecondary
                                                disabled={busy}
                                                onClick={() => setPending({ kind: 'delete-backup', backup: item })}
                                            >
                                                Delete
                                            </Button.Danger>
                                        )}
                                    </Row>
                                ))}
                            </ul>
                        )}
                    </div>
                </TitledGreyBox>

                <TitledGreyBox title={`Ban list${bans.data ? ` (${bans.data.ids.length})` : ''}`}>
                    {bans.isLoading ? (
                        <Spinner centered size={'small'} />
                    ) : bans.error || !bans.data ? (
                        <Alert type={'danger'}>{httpErrorToHuman(bans.error)}</Alert>
                    ) : (
                        <div className={'pal:flex pal:flex-col pal:gap-2'}>
                            <p className={'pal:text-xs pal:text-muted-foreground'}>
                                The game&apos;s own ban list ({bans.data.path}). Ban players with the Players card on the console page.
                                Entries can only be removed while the server is stopped.
                            </p>
                            {bans.data.ids.length === 0 ? (
                                <Empty>{bans.data.exists ? 'Nobody is banned.' : 'There is no ban list file yet.'}</Empty>
                            ) : (
                                <ul className={'pal:flex pal:max-h-72 pal:flex-col pal:divide-y pal:divide-border pal:overflow-y-auto'}>
                                    {bans.data.ids.map((id) => (
                                        <Row key={id} name={id} mono>
                                            {can.update && (
                                                <Button
                                                    size={'xsmall'}
                                                    isSecondary
                                                    disabled={busy || !stopped}
                                                    onClick={() => setPending({ kind: 'unban', id })}
                                                >
                                                    Remove
                                                </Button>
                                            )}
                                        </Row>
                                    ))}
                                </ul>
                            )}
                        </div>
                    )}
                </TitledGreyBox>
            </div>

            {pending?.kind === 'restore' && (
                <Dialog.Confirm
                    open
                    title={`Restore ${shortId(pending.backup.world)}?`}
                    confirm={'Restore'}
                    pending={restore.isPending}
                    onClose={() => setPending(null)}
                    onConfirmed={() => restore.mutate(pending.backup.file)}
                >
                    <p className={'pal:text-sm'}>
                        The backup from {formatDate(parseStamp(pending.backup.stamp)) || pending.backup.stamp} replaces the world. The
                        current world is moved to palworld-backups/replaced, not deleted. Players who joined since the backup lose that
                        progress.
                    </p>
                </Dialog.Confirm>
            )}

            {pending?.kind === 'snapshot' && (
                <Dialog.Confirm
                    open
                    title={`Restore the game backup from ${snapshotDate(pending.at)}?`}
                    confirm={'Restore'}
                    pending={snapshot.isPending}
                    onClose={() => setPending(null)}
                    onConfirmed={() => snapshot.mutate({ world: pending.world.id, snapshot: pending.snapshot })}
                >
                    <p className={'pal:text-sm'}>
                        This puts the game&apos;s own backup {pending.snapshot} in place of world {shortId(pending.world.id)}. The current
                        world is moved to palworld-backups/replaced, not deleted.
                    </p>
                </Dialog.Confirm>
            )}

            {pending?.kind === 'delete-backup' && (
                <Dialog.Confirm
                    open
                    title={'Delete this backup?'}
                    confirm={'Delete'}
                    pending={remove.isPending}
                    onClose={() => setPending(null)}
                    onConfirmed={() => remove.mutate(pending.backup.file)}
                >
                    <p className={'pal:text-sm'}>
                        The backup from {formatDate(parseStamp(pending.backup.stamp)) || pending.backup.stamp} is deleted for good.
                    </p>
                </Dialog.Confirm>
            )}

            {pending?.kind === 'unban' && (
                <Dialog.Confirm
                    open
                    title={`Remove the ban on ${pending.id}?`}
                    confirm={'Remove ban'}
                    pending={unban.isPending}
                    onClose={() => setPending(null)}
                    onConfirmed={() => unban.mutate(pending.id)}
                >
                    <p className={'pal:text-sm'}>This player can join again once the server is started.</p>
                </Dialog.Confirm>
            )}
        </ServerContentBlock>
    );
}

function WorldRow({
    world,
    stopped,
    busy,
    canBackup,
    canRestore,
    backingUp,
    onBackup,
    onRestore,
}: {
    world: World;
    stopped: boolean;
    busy: boolean;
    canBackup: boolean;
    canRestore: boolean;
    backingUp: boolean;
    onBackup: () => void;
    onRestore: (snapshot: string, at: string) => void;
}) {
    const [open, setOpen] = useState(false);

    return (
        <li className={'pal:flex pal:flex-col pal:gap-2 pal:py-2'}>
            <div className={'pal:flex pal:items-center pal:gap-3'}>
                <div className={'pal:min-w-0 pal:flex-1'}>
                    <div className={'pal:flex pal:items-center pal:gap-2'}>
                        <span className={'pal:truncate pal:font-mono pal:text-sm pal:font-medium pal:text-foreground'} title={world.id}>
                            {shortId(world.id)}
                        </span>
                        {world.active && (
                            <span className={'pal:rounded pal:bg-muted pal:px-1.5 pal:py-0.5 pal:text-[11px] pal:text-muted-foreground'}>
                                Active
                            </span>
                        )}
                        {!world.has_level && (
                            <span className={'pal:rounded pal:bg-warning/15 pal:px-1.5 pal:py-0.5 pal:text-[11px] pal:text-warning'}>
                                No Level.sav
                            </span>
                        )}
                    </div>
                    <p className={'pal:truncate pal:text-xs pal:text-muted-foreground'}>
                        {[
                            world.size !== null ? formatBytes(world.size) : null,
                            `${world.players} player${world.players === 1 ? '' : 's'}`,
                            formatDate(world.modified),
                        ]
                            .filter(Boolean)
                            .join(' · ')}
                    </p>
                </div>
                <div className={'pal:flex pal:shrink-0 pal:flex-wrap pal:items-center pal:justify-end pal:gap-1.5'}>
                    {world.game_backups.length > 0 && (
                        <Button.Text size={'xsmall'} onClick={() => setOpen((value) => !value)}>
                            {open ? 'Hide' : 'Show'} game backups ({world.game_backups.length})
                        </Button.Text>
                    )}
                    {canBackup && world.has_level && (
                        <Button size={'xsmall'} isSecondary isLoading={backingUp} disabled={busy} onClick={onBackup}>
                            Back up
                        </Button>
                    )}
                </div>
            </div>
            {open && (
                <ul
                    className={
                        'pal:ml-4 pal:flex pal:max-h-72 pal:flex-col pal:divide-y pal:divide-border pal:overflow-y-auto pal:rounded-md pal:border pal:border-border'
                    }
                >
                    {world.game_backups.map((item) => (
                        <Row key={item.name} name={snapshotDate(item.at)} detail={item.name}>
                            {canRestore && (
                                <Button
                                    size={'xsmall'}
                                    isSecondary
                                    disabled={busy || !stopped}
                                    onClick={() => onRestore(item.name, item.at)}
                                >
                                    Restore
                                </Button>
                            )}
                        </Row>
                    ))}
                </ul>
            )}
        </li>
    );
}

function Row({
    name,
    title,
    detail,
    mono,
    children,
}: {
    name: string;
    title?: string;
    detail?: string;
    mono?: boolean;
    children?: ReactNode;
}) {
    return (
        <li className={'pal:flex pal:items-center pal:gap-3 pal:px-1 pal:py-2'}>
            <div className={'pal:min-w-0 pal:flex-1'}>
                <p className={`pal:truncate pal:text-sm pal:font-medium pal:text-foreground ${mono ? 'pal:font-mono' : ''}`} title={title}>
                    {name}
                </p>
                {detail && <p className={'pal:truncate pal:text-xs pal:text-muted-foreground'}>{detail}</p>}
            </div>
            <div className={'pal:flex pal:shrink-0 pal:flex-wrap pal:items-center pal:justify-end pal:gap-1.5'}>{children}</div>
        </li>
    );
}

function Empty({ children }: { children: ReactNode }) {
    return <p className={'pal:py-4 pal:text-center pal:text-sm pal:text-muted-foreground'}>{children}</p>;
}
