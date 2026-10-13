import { useState, type ReactNode } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
    Alert,
    Button,
    Checkbox,
    Dialog,
    Input,
    Spinner,
    TitledGreyBox,
    httpErrorToHuman,
    toast,
    useServerPermission,
} from '@pterodactyl/sdk';
import {
    backupWorld,
    deleteBackup,
    deleteWorld,
    getWorlds,
    keys,
    resetWorld,
    restoreBackup,
    stopServer,
    switchWorld,
    type Backup,
    type ResetInput,
    type World,
    type WorldsResponse,
} from './api';
import { foldersSentence, formatBytes, formatStamp, formatWhen, isStopped } from './logic';

type Dialogs =
    | { kind: 'reset'; world: World; hardcore: boolean }
    | { kind: 'restore'; backup: Backup }
    | { kind: 'delete-backup'; backup: Backup }
    | { kind: 'delete-world'; world: World };

export default function WorldsCard({ identifier }: { identifier: string }) {
    const queryClient = useQueryClient();
    const [dialog, setDialog] = useState<Dialogs | null>(null);

    const can = {
        archive: useServerPermission(['file.archive', 'file.create', 'file.update']),
        update: useServerPermission('file.update'),
        delete: useServerPermission('file.delete'),
        start: useServerPermission('control.start'),
        stop: useServerPermission('control.stop'),
    };

    const worlds = useQuery({
        queryKey: keys.worlds(identifier),
        queryFn: () => getWorlds(identifier),
        // Follow a stop or start until it is done.
        refetchInterval: (query) => (['stopping', 'starting'].includes(query.state.data?.state ?? '') ? 3000 : false),
    });

    const done = (result: WorldsResponse) => {
        queryClient.setQueryData(keys.worlds(identifier), result);
        void queryClient.invalidateQueries({ queryKey: keys.properties(identifier) });
    };

    const backup = useMutation({
        mutationFn: (world: string) => backupWorld(identifier, world),
        onSuccess: (result) => {
            done(result);
            toast.success('Backup saved.');
        },
        onError: (error) => toast.error(httpErrorToHuman(error)),
    });

    const switching = useMutation({
        mutationFn: (world: string) => switchWorld(identifier, world),
        onSuccess: (result) => {
            done(result);
            toast.success(result.state === 'offline' ? 'Active world changed.' : 'Active world changed. Restart the server to load it.');
        },
        onError: (error) => toast.error(httpErrorToHuman(error)),
    });

    const stop = useMutation({
        mutationFn: () => stopServer(identifier),
        onSuccess: () => {
            toast.success('Stopping the server.');
            void queryClient.invalidateQueries({ queryKey: keys.worlds(identifier) });
        },
        onError: (error) => toast.error(httpErrorToHuman(error)),
    });

    const reset = useMutation({
        mutationFn: (input: ResetInput) => resetWorld(identifier, input),
        onSuccess: (result, input) => {
            done(result);
            setDialog(null);
            toast.success(
                input.start
                    ? 'World deleted. The server is starting with a new one.'
                    : 'World deleted. A new one is created on the next start.',
            );
        },
        onError: (error) => toast.error(httpErrorToHuman(error)),
    });

    const restore = useMutation({
        mutationFn: (file: string) => restoreBackup(identifier, file),
        onSuccess: (result) => {
            done(result);
            setDialog(null);
            toast.success('Backup restored.');
        },
        onError: (error) => toast.error(httpErrorToHuman(error)),
    });

    const removeBackup = useMutation({
        mutationFn: (file: string) => deleteBackup(identifier, file),
        onSuccess: (result) => {
            done(result);
            setDialog(null);
            toast.success('Backup deleted.');
        },
        onError: (error) => toast.error(httpErrorToHuman(error)),
    });

    const removeWorld = useMutation({
        mutationFn: (world: string) => deleteWorld(identifier, world),
        onSuccess: (result) => {
            done(result);
            setDialog(null);
            toast.success('World deleted.');
        },
        onError: (error) => toast.error(httpErrorToHuman(error)),
    });

    if (worlds.isLoading) {
        return (
            <TitledGreyBox title={'Worlds'}>
                <Spinner centered />
            </TitledGreyBox>
        );
    }

    if (worlds.error || !worlds.data) {
        return (
            <TitledGreyBox title={'Worlds'}>
                <Alert type={'danger'}>{httpErrorToHuman(worlds.error)}</Alert>
            </TitledGreyBox>
        );
    }

    const data = worlds.data;
    const stopped = isStopped(data.state);
    const activeWorld = data.worlds.find((world) => world.active) ?? null;
    const stopNotice = <StopNotice state={data.state} canStop={can.stop} stopping={stop.isPending} onStop={() => stop.mutate()} />;

    return (
        <div className={'mcw:flex mcw:flex-col mcw:gap-4'}>
            <TitledGreyBox title={'Worlds'}>
                <div className={'mcw:flex mcw:flex-col mcw:gap-3'}>
                    {!stopped && stopNotice}

                    {!data.properties_exists && (
                        <Alert type={'info'}>
                            server.properties does not exist yet. Start the server once so it creates the world and the file.
                        </Alert>
                    )}
                    {data.properties_exists && !data.active_valid && (
                        <Alert type={'warning'} title={'This world name cannot be managed here'}>
                            level-name is set to &quot;{data.active}&quot;. Use letters, digits, spaces, dots, dashes and underscores to
                            manage it on this page.
                        </Alert>
                    )}
                    {data.properties_exists && data.active_valid && activeWorld === null && (
                        <Alert type={'info'}>
                            There is no folder named &quot;{data.active}&quot; yet. The server creates it on the next start.
                        </Alert>
                    )}

                    {data.hardcore && activeWorld && can.delete && can.update && (
                        <div
                            className={
                                'mcw:flex mcw:flex-wrap mcw:items-center mcw:justify-between mcw:gap-3 mcw:rounded-md mcw:border mcw:border-destructive/50 mcw:bg-destructive/10 mcw:p-3'
                            }
                        >
                            <div className={'mcw:flex mcw:flex-col mcw:gap-1'}>
                                <p className={'mcw:text-sm mcw:font-medium mcw:text-foreground'}>Hardcore server</p>
                                <p className={'mcw:text-sm mcw:text-muted-foreground'}>
                                    Backs up {activeWorld.name}, deletes it, creates a new world with a random seed and starts the server.
                                </p>
                            </div>
                            <Button.Danger size={'small'} onClick={() => setDialog({ kind: 'reset', world: activeWorld, hardcore: true })}>
                                New hardcore run
                            </Button.Danger>
                        </div>
                    )}

                    {data.worlds.length === 0 ? (
                        <p className={'mcw:text-sm mcw:text-muted-foreground'}>
                            No worlds found. A world is a folder with a level.dat file in it.
                        </p>
                    ) : (
                        <ul className={'mcw:flex mcw:flex-col mcw:divide-y mcw:divide-border mcw:rounded-md mcw:border mcw:border-border'}>
                            {data.worlds.map((world) => (
                                <li
                                    key={world.name}
                                    className={'mcw:flex mcw:flex-wrap mcw:items-center mcw:justify-between mcw:gap-3 mcw:px-3 mcw:py-2'}
                                >
                                    <div className={'mcw:flex mcw:min-w-0 mcw:flex-col mcw:gap-0.5'}>
                                        <p
                                            className={
                                                'mcw:flex mcw:items-center mcw:gap-2 mcw:text-sm mcw:font-medium mcw:text-foreground'
                                            }
                                        >
                                            <span className={'mcw:break-all'}>{world.name}</span>
                                            {world.active && (
                                                <span
                                                    className={
                                                        'mcw:rounded mcw:bg-success/20 mcw:px-1.5 mcw:py-0.5 mcw:text-xs mcw:font-normal mcw:text-success'
                                                    }
                                                >
                                                    Active
                                                </span>
                                            )}
                                        </p>
                                        <p className={'mcw:text-xs mcw:text-muted-foreground'}>
                                            {world.folders.join(', ')}
                                            {world.modified && ` · saved ${formatWhen(world.modified)}`}
                                        </p>
                                    </div>
                                    <div className={'mcw:flex mcw:flex-wrap mcw:gap-2'}>
                                        {can.archive && (
                                            <Button
                                                size={'xsmall'}
                                                isSecondary
                                                isLoading={backup.isPending && backup.variables === world.name}
                                                disabled={backup.isPending}
                                                onClick={() => backup.mutate(world.name)}
                                            >
                                                Back up
                                            </Button>
                                        )}
                                        {!world.active && can.update && (
                                            <Button
                                                size={'xsmall'}
                                                isSecondary
                                                isLoading={switching.isPending && switching.variables === world.name}
                                                disabled={switching.isPending}
                                                onClick={() => switching.mutate(world.name)}
                                            >
                                                Make active
                                            </Button>
                                        )}
                                        {world.active && can.delete && can.update && (
                                            <Button.Danger
                                                size={'xsmall'}
                                                onClick={() => setDialog({ kind: 'reset', world, hardcore: false })}
                                            >
                                                Reset
                                            </Button.Danger>
                                        )}
                                        {!world.active && can.delete && (
                                            <Button.Danger size={'xsmall'} onClick={() => setDialog({ kind: 'delete-world', world })}>
                                                Delete
                                            </Button.Danger>
                                        )}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                    {!stopped && data.worlds.length > 0 && (
                        <p className={'mcw:text-xs mcw:text-muted-foreground'}>
                            Backing up a running server works, but the copy can catch a chunk half written. Stop the server first for a
                            clean one.
                        </p>
                    )}
                </div>
            </TitledGreyBox>

            <TitledGreyBox title={'Backups'}>
                <div className={'mcw:flex mcw:flex-col mcw:gap-3'}>
                    {data.backups.length === 0 ? (
                        <p className={'mcw:text-sm mcw:text-muted-foreground'}>
                            No backups yet. They are saved in the world-backups folder.
                        </p>
                    ) : (
                        <ul className={'mcw:flex mcw:flex-col mcw:divide-y mcw:divide-border mcw:rounded-md mcw:border mcw:border-border'}>
                            {data.backups.map((item) => (
                                <li
                                    key={item.name}
                                    className={'mcw:flex mcw:flex-wrap mcw:items-center mcw:justify-between mcw:gap-3 mcw:px-3 mcw:py-2'}
                                >
                                    <div className={'mcw:flex mcw:min-w-0 mcw:flex-col mcw:gap-0.5'}>
                                        <p className={'mcw:break-all mcw:text-sm mcw:font-medium mcw:text-foreground'}>{item.world}</p>
                                        <p className={'mcw:text-xs mcw:text-muted-foreground'}>
                                            {formatStamp(item.stamp)}
                                            {item.size !== null && ` · ${formatBytes(item.size)}`}
                                        </p>
                                    </div>
                                    <div className={'mcw:flex mcw:flex-wrap mcw:gap-2'}>
                                        {can.archive && (
                                            <Button
                                                size={'xsmall'}
                                                isSecondary
                                                onClick={() => setDialog({ kind: 'restore', backup: item })}
                                            >
                                                Restore
                                            </Button>
                                        )}
                                        {can.delete && (
                                            <Button.Danger
                                                size={'xsmall'}
                                                onClick={() => setDialog({ kind: 'delete-backup', backup: item })}
                                            >
                                                Delete
                                            </Button.Danger>
                                        )}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                    <p className={'mcw:text-xs mcw:text-muted-foreground'}>
                        The newest {data.keep} backup{data.keep === 1 ? '' : 's'} of each world are kept. Older ones are deleted when a new
                        one is made.
                    </p>
                </div>
            </TitledGreyBox>

            {dialog?.kind === 'reset' && (
                <ResetDialog
                    world={dialog.world}
                    hardcore={dialog.hardcore}
                    stopNotice={stopNotice}
                    stopped={stopped}
                    canArchive={can.archive}
                    canStart={can.start}
                    pending={reset.isPending}
                    onClose={() => setDialog(null)}
                    onConfirm={(input) => reset.mutate(input)}
                />
            )}

            {dialog?.kind === 'restore' && (
                <Dialog open title={`Restore ${dialog.backup.world}?`} onClose={() => (restore.isPending ? undefined : setDialog(null))}>
                    <div className={'mcw:flex mcw:flex-col mcw:gap-3 mcw:text-sm'}>
                        <p>
                            The backup of {dialog.backup.world} from {formatStamp(dialog.backup.stamp)} replaces the current world.
                        </p>
                        <Moves folders={restoreMoves(data.worlds, dialog.backup)} />
                        {!stopped && stopNotice}
                    </div>
                    <Dialog.Footer>
                        <Button.Text className={'mcw:w-full mcw:sm:w-auto'} disabled={restore.isPending} onClick={() => setDialog(null)}>
                            Cancel
                        </Button.Text>
                        <Button
                            className={'mcw:w-full mcw:sm:w-auto'}
                            isLoading={restore.isPending}
                            disabled={!stopped}
                            onClick={() => restore.mutate(dialog.backup.name)}
                        >
                            Restore
                        </Button>
                    </Dialog.Footer>
                </Dialog>
            )}

            {dialog?.kind === 'delete-backup' && (
                <Dialog.Confirm
                    open
                    title={'Delete this backup?'}
                    confirm={'Delete backup'}
                    pending={removeBackup.isPending}
                    onClose={() => setDialog(null)}
                    onConfirmed={() => removeBackup.mutate(dialog.backup.name)}
                >
                    <p className={'mcw:text-sm'}>
                        The backup of {dialog.backup.world} from {formatStamp(dialog.backup.stamp)} is deleted for good.
                    </p>
                </Dialog.Confirm>
            )}

            {dialog?.kind === 'delete-world' && (
                <Dialog open title={`Delete ${dialog.world.name}?`} onClose={() => (removeWorld.isPending ? undefined : setDialog(null))}>
                    <div className={'mcw:flex mcw:flex-col mcw:gap-3 mcw:text-sm'}>
                        <p>These folders are deleted for good:</p>
                        <FolderList folders={dialog.world.folders} />
                        <p className={'mcw:text-muted-foreground'}>Make a backup first if you may want it back.</p>
                        {!stopped && stopNotice}
                    </div>
                    <Dialog.Footer>
                        <Button.Text
                            className={'mcw:w-full mcw:sm:w-auto'}
                            disabled={removeWorld.isPending}
                            onClick={() => setDialog(null)}
                        >
                            Cancel
                        </Button.Text>
                        <Button.Danger
                            className={'mcw:w-full mcw:sm:w-auto'}
                            isLoading={removeWorld.isPending}
                            disabled={!stopped}
                            onClick={() => removeWorld.mutate(dialog.world.name)}
                        >
                            Delete world
                        </Button.Danger>
                    </Dialog.Footer>
                </Dialog>
            )}
        </div>
    );
}

/** The folders a restore of this backup moves aside: the ones that exist for that world. */
export function restoreMoves(worlds: World[], backup: Backup): string[] {
    return worlds.find((world) => world.name === backup.world)?.folders ?? [];
}

function Moves({ folders }: { folders: string[] }) {
    if (folders.length === 0)
        return <p className={'mcw:text-muted-foreground'}>No current folder has this name, so nothing is moved aside.</p>;
    return (
        <>
            <p>These folders are renamed to name.old-date and kept. Delete them later if you do not need them.</p>
            <FolderList folders={folders} />
        </>
    );
}

function FolderList({ folders }: { folders: string[] }) {
    return (
        <ul
            className={'mcw:flex mcw:flex-col mcw:gap-1 mcw:rounded-md mcw:border mcw:border-border mcw:p-2 mcw:font-mono mcw:text-xs'}
            aria-label={'Folders'}
        >
            {folders.map((folder) => (
                <li key={folder}>{folder}</li>
            ))}
        </ul>
    );
}

function StopNotice({ state, canStop, stopping, onStop }: { state: string; canStop: boolean; stopping: boolean; onStop: () => void }) {
    if (isStopped(state)) return null;

    return (
        <Alert type={'warning'} title={'The server must be stopped'}>
            <div className={'mcw:flex mcw:flex-wrap mcw:items-center mcw:justify-between mcw:gap-2'}>
                <span>
                    {state === 'stopping'
                        ? 'The server is stopping.'
                        : state === 'starting'
                          ? 'The server is starting. Wait until it is running, then stop it.'
                          : 'Resetting, restoring and deleting need the server to be off.'}
                </span>
                {state === 'running' && canStop && (
                    <Button size={'small'} color={'red'} isSecondary isLoading={stopping} onClick={onStop}>
                        Stop server
                    </Button>
                )}
            </div>
        </Alert>
    );
}

function ResetDialog({
    world,
    hardcore,
    stopNotice,
    stopped,
    canArchive,
    canStart,
    pending,
    onClose,
    onConfirm,
}: {
    world: World;
    hardcore: boolean;
    stopNotice: ReactNode;
    stopped: boolean;
    canArchive: boolean;
    canStart: boolean;
    pending: boolean;
    onClose: () => void;
    onConfirm: (input: ResetInput) => void;
}) {
    const [seed, setSeed] = useState('');
    const [keepBackup, setKeepBackup] = useState(canArchive);
    const [start, setStart] = useState(hardcore);
    const seedError = seed.includes('\n') || seed.length > 100 ? 'One line, at most 100 characters.' : null;
    const backupOn = hardcore ? true : keepBackup && canArchive;
    const startOn = hardcore ? true : start && canStart;
    const blocked = (hardcore && (!canArchive || !canStart)) || seedError !== null;

    return (
        <Dialog open title={hardcore ? 'New hardcore run?' : `Reset ${world.name}?`} onClose={() => (pending ? undefined : onClose())}>
            <div className={'mcw:flex mcw:flex-col mcw:gap-3 mcw:text-sm'}>
                <p>These folders are deleted for good, and the server creates a new world:</p>
                <FolderList folders={world.folders} />

                {!hardcore && (
                    <div className={'mcw:flex mcw:flex-col mcw:gap-1'}>
                        <label htmlFor={'mcw-seed'} className={'mcw:font-medium mcw:text-foreground'}>
                            New seed
                        </label>
                        <Input
                            id={'mcw-seed'}
                            value={seed}
                            placeholder={'Random'}
                            $hasError={seedError !== null}
                            onChange={(event: React.ChangeEvent<HTMLInputElement>) => setSeed(event.target.value)}
                        />
                        <p className={seedError ? 'mcw:text-xs mcw:text-destructive' : 'mcw:text-xs mcw:text-muted-foreground'}>
                            {seedError ?? 'Leave empty for a random world.'}
                        </p>
                    </div>
                )}

                {hardcore ? (
                    <p className={'mcw:text-muted-foreground'}>
                        A backup is made first, the new world gets a random seed, and the server starts right after.
                        {(!canArchive || !canStart) && ' You need the archive and start permissions for this.'}
                    </p>
                ) : (
                    <div className={'mcw:flex mcw:flex-col mcw:gap-2'}>
                        <label className={'mcw:flex mcw:items-center mcw:gap-2'}>
                            <Checkbox checked={backupOn} disabled={!canArchive} onChange={setKeepBackup} />
                            Keep a backup first
                        </label>
                        <label className={'mcw:flex mcw:items-center mcw:gap-2'}>
                            <Checkbox checked={startOn} disabled={!canStart} onChange={setStart} />
                            Start the server after the reset
                        </label>
                    </div>
                )}

                {!stopped && stopNotice}
            </div>
            <Dialog.Footer>
                <Button.Text className={'mcw:w-full mcw:sm:w-auto'} disabled={pending} onClick={onClose}>
                    Cancel
                </Button.Text>
                <Button.Danger
                    className={'mcw:w-full mcw:sm:w-auto'}
                    isLoading={pending}
                    disabled={!stopped || blocked}
                    onClick={() => onConfirm({ world: world.name, seed: hardcore ? '' : seed.trim(), backup: backupOn, start: startOn })}
                >
                    {hardcore ? 'Delete and start a new run' : `Delete ${foldersSentence(world.folders)}`}
                </Button.Danger>
            </Dialog.Footer>
        </Dialog>
    );
}
