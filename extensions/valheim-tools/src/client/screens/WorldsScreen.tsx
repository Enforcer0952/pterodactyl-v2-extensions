import { useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
    Alert,
    Button,
    Checkbox,
    Dialog,
    Input,
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
    deleteWorld,
    getWorlds,
    keys,
    restoreBackup,
    switchWorld,
    validWorldName,
    type AutomaticBackup,
    type World,
    type WorldBackup,
    type WorldsResponse,
    type WorldsResult,
} from '../api';
import { backupKind, formatBytes, formatDate, parseFolderStamp } from '../format';
import { Empty, Field, Row, Rows } from '../shared';

type Pending =
    | { kind: 'delete'; world: World }
    | { kind: 'restore'; backup: WorldBackup }
    | { kind: 'restore-auto'; backup: AutomaticBackup }
    | { kind: 'delete-backup'; backup: WorldBackup }
    | { kind: 'switch'; world: World }
    | { kind: 'backup-running'; world: World };

export default function WorldsScreen() {
    const server = useCurrentServerRequired();
    const identifier = server.attributes.identifier;
    const queryClient = useQueryClient();
    const [pending, setPending] = useState<Pending | null>(null);
    const [backupFirst, setBackupFirst] = useState(true);

    const can = {
        update: useServerPermission('file.update'),
        create: useServerPermission('file.create'),
        delete: useServerPermission('file.delete'),
        archive: useServerPermission('file.archive'),
        startup: useServerPermission('startup.update'),
    };

    const worlds = useQuery({ queryKey: keys.worlds(identifier), queryFn: () => getWorlds(identifier), refetchOnWindowFocus: true });

    const done = (message: (result: WorldsResult) => string) => ({
        onSuccess: (result: WorldsResult) => {
            queryClient.setQueryData<WorldsResponse>(keys.worlds(identifier), result);
            toast.success(message(result));
            setPending(null);
        },
        onError: (error: unknown) => {
            toast.error(httpErrorToHuman(error));
            setPending(null);
        },
    });

    const switching = useMutation({
        mutationFn: (input: { name: string; create: boolean }) => switchWorld(identifier, input.name, input.create),
        ...done((r) => `${r.result?.name ?? 'The world'} loads the next time the server starts.`),
    });
    const backup = useMutation({
        mutationFn: (input: { name: string; running: boolean }) => backupWorld(identifier, input.name, input.running),
        ...done(() => 'Backup made.'),
    });
    const restore = useMutation({
        mutationFn: (input: { id: string; automatic: boolean }) => restoreBackup(identifier, input.id, input.automatic),
        ...done(
            (r) => `Restored ${r.result?.world ?? 'the world'}.${r.result?.safety ? ' The world it replaced was backed up first.' : ''}`,
        ),
    });
    const removeBackup = useMutation({ mutationFn: (id: string) => deleteBackup(identifier, id), ...done(() => 'Backup deleted.') });
    const remove = useMutation({
        mutationFn: (input: { name: string; backup: boolean }) => deleteWorld(identifier, input.name, input.backup),
        ...done((r) => (r.result?.backup ? 'World deleted. A backup was made first.' : 'World deleted.')),
    });

    if (worlds.isLoading) {
        return (
            <ServerContentBlock title={'Worlds'}>
                <Spinner centered />
            </ServerContentBlock>
        );
    }

    if (worlds.error || !worlds.data) {
        return (
            <ServerContentBlock title={'Worlds'}>
                <Alert type={'danger'}>{httpErrorToHuman(worlds.error)}</Alert>
            </ServerContentBlock>
        );
    }

    const data = worlds.data;
    const stopped = data.state === 'offline';
    const busy = [switching, backup, restore, removeBackup, remove].some((mutation) => mutation.isPending);
    const canBackup = can.create && can.archive && can.update && can.delete;

    const startBackup = (world: World) =>
        stopped ? backup.mutate({ name: world.name, running: false }) : setPending({ kind: 'backup-running', world });

    return (
        <ServerContentBlock title={'Worlds'}>
            <div className={'vh:flex vh:flex-col vh:gap-4'}>
                {!stopped && (
                    <Alert type={'info'}>
                        The server is {data.state}. Stop it to restore or delete worlds. A backup of a running world may catch a file while
                        it is being written: Valheim has no save command for dedicated servers.
                    </Alert>
                )}
                {data.active && !data.active_exists && (
                    <Alert type={'info'}>
                        The server is set to load {data.active}, which has no files yet. Valheim creates it when the server starts.
                    </Alert>
                )}
                {data.worlds.some((world) => world.format === 'files') && (
                    <Alert type={'info'}>
                        Some worlds are still in the older .db and .fwl format. Valheim 1.0 converts a world to its new folder format when
                        the server loads it, and keeps the old files as .old.
                    </Alert>
                )}

                <TitledGreyBox title={`Worlds (${data.worlds.length})`}>
                    {data.worlds.length === 0 ? (
                        <Empty>No worlds in {data.worlds_directory} yet. Start the server to let it create one.</Empty>
                    ) : (
                        <Rows>
                            {data.worlds.map((world) => (
                                <Row
                                    key={world.name}
                                    name={world.name}
                                    badge={world.active ? 'Active' : !world.complete ? 'Incomplete' : undefined}
                                    detail={[
                                        formatBytes(world.size),
                                        formatDate(world.modified),
                                        world.format === 'folder' ? 'folder' : '.db + .fwl',
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')}
                                >
                                    {can.startup && data.variable.editable && !world.active && (
                                        <Button
                                            size={'xsmall'}
                                            isSecondary
                                            disabled={busy}
                                            onClick={() => setPending({ kind: 'switch', world })}
                                        >
                                            Use
                                        </Button>
                                    )}
                                    {canBackup && (
                                        <Button
                                            size={'xsmall'}
                                            isSecondary
                                            isLoading={backup.isPending && backup.variables?.name === world.name}
                                            disabled={busy}
                                            onClick={() => startBackup(world)}
                                        >
                                            Back up
                                        </Button>
                                    )}
                                    {can.delete && !world.active && (
                                        <Button.Danger
                                            size={'xsmall'}
                                            isSecondary
                                            disabled={busy || !stopped}
                                            onClick={() => setPending({ kind: 'delete', world })}
                                        >
                                            Delete
                                        </Button.Danger>
                                    )}
                                </Row>
                            ))}
                        </Rows>
                    )}
                </TitledGreyBox>

                {can.startup && data.variable.editable && (
                    <NewWorldForm data={data} pending={switching.isPending} onCreate={(name) => switching.mutate({ name, create: true })} />
                )}

                <TitledGreyBox title={`Backups (${data.backups.length})`}>
                    <div className={'vh:flex vh:flex-col vh:gap-2'}>
                        <p className={'vh:text-xs vh:text-muted-foreground'}>
                            Kept in {data.backups_directory}. Each world keeps its newest {data.keep} backups (the ones made before a
                            restore or delete are counted apart). Restoring first backs up the world it replaces.
                        </p>
                        {data.backups.length === 0 ? (
                            <Empty>No backups yet.</Empty>
                        ) : (
                            <Rows tall>
                                {data.backups.map((item) => (
                                    <Row
                                        key={item.id}
                                        name={item.world}
                                        badge={item.kind === '' ? undefined : backupKind(item.kind)}
                                        detail={[formatDate(parseFolderStamp(item.id) ?? item.modified), formatBytes(item.size)]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    >
                                        {canBackup && (
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
                            </Rows>
                        )}
                    </div>
                </TitledGreyBox>

                {data.automatic_backups.length > 0 && (
                    <TitledGreyBox title={`Valheim's automatic backups (${data.automatic_backups.length})`}>
                        <div className={'vh:flex vh:flex-col vh:gap-2'}>
                            <p className={'vh:text-xs vh:text-muted-foreground'}>
                                The game makes these itself while the server runs, next to the worlds. How many it keeps is set by the
                                -backups, -backupshort and -backuplong options in the startup command.
                            </p>
                            <Rows tall>
                                {data.automatic_backups.map((item) => (
                                    <Row
                                        key={item.id}
                                        name={item.world}
                                        detail={[formatDate(item.time ?? item.modified), formatBytes(item.size)]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    >
                                        {canBackup && (
                                            <Button
                                                size={'xsmall'}
                                                isSecondary
                                                disabled={busy || !stopped}
                                                onClick={() => setPending({ kind: 'restore-auto', backup: item })}
                                            >
                                                Restore
                                            </Button>
                                        )}
                                    </Row>
                                ))}
                            </Rows>
                        </div>
                    </TitledGreyBox>
                )}
            </div>

            {pending?.kind === 'switch' && (
                <Dialog.Confirm
                    open
                    title={`Use ${pending.world.name}?`}
                    confirm={'Use this world'}
                    pending={switching.isPending}
                    onClose={() => setPending(null)}
                    onConfirmed={() => switching.mutate({ name: pending.world.name, create: false })}
                >
                    <p className={'vh:text-sm'}>
                        The WORLD startup variable is set to {pending.world.name}. The server loads it the next time it starts. Nothing is
                        deleted.
                    </p>
                </Dialog.Confirm>
            )}

            {pending?.kind === 'backup-running' && (
                <Dialog.Confirm
                    open
                    title={`Back up ${pending.world.name} while the server runs?`}
                    confirm={'Back up anyway'}
                    pending={backup.isPending}
                    onClose={() => setPending(null)}
                    onConfirmed={() => backup.mutate({ name: pending.world.name, running: true })}
                >
                    <p className={'vh:text-sm'}>
                        The world files can change while they are copied, so this backup may be incomplete. For a safe copy, stop the server
                        first.
                    </p>
                </Dialog.Confirm>
            )}

            {pending?.kind === 'delete' && (
                <Dialog.Confirm
                    open
                    title={`Delete ${pending.world.name}?`}
                    confirm={'Delete'}
                    pending={remove.isPending}
                    onClose={() => setPending(null)}
                    onConfirmed={() => remove.mutate({ name: pending.world.name, backup: backupFirst })}
                >
                    <div className={'vh:flex vh:flex-col vh:gap-3'}>
                        <p className={'vh:text-sm'}>
                            This removes the world {pending.world.name} ({pending.world.entries.join(', ')}).
                            {!backupFirst && ' Without a backup it cannot be undone.'}
                        </p>
                        <label className={'vh:flex vh:items-center vh:gap-2 vh:text-sm'}>
                            <Checkbox checked={backupFirst} onChange={setBackupFirst} />
                            Make a backup first
                        </label>
                    </div>
                </Dialog.Confirm>
            )}

            {pending?.kind === 'restore' && (
                <Dialog.Confirm
                    open
                    title={`Restore ${pending.backup.world}?`}
                    confirm={'Restore'}
                    pending={restore.isPending}
                    onClose={() => setPending(null)}
                    onConfirmed={() => restore.mutate({ id: pending.backup.id, automatic: false })}
                >
                    <p className={'vh:text-sm'}>
                        This backup replaces the world called {pending.backup.world}. If that world exists it is backed up first, so you can
                        go back.
                    </p>
                </Dialog.Confirm>
            )}

            {pending?.kind === 'restore-auto' && (
                <Dialog.Confirm
                    open
                    title={`Restore ${pending.backup.world} from an automatic backup?`}
                    confirm={'Restore'}
                    pending={restore.isPending}
                    onClose={() => setPending(null)}
                    onConfirmed={() => restore.mutate({ id: pending.backup.id, automatic: true })}
                >
                    <p className={'vh:text-sm'}>
                        The automatic backup from {formatDate(pending.backup.time ?? pending.backup.modified) || pending.backup.stamp}{' '}
                        replaces the world called {pending.backup.world}. The world it replaces is backed up first.
                    </p>
                </Dialog.Confirm>
            )}

            {pending?.kind === 'delete-backup' && (
                <Dialog.Confirm
                    open
                    title={'Delete this backup?'}
                    confirm={'Delete'}
                    pending={removeBackup.isPending}
                    onClose={() => setPending(null)}
                    onConfirmed={() => removeBackup.mutate(pending.backup.id)}
                >
                    <p className={'vh:text-sm'}>The backup {pending.backup.id} is deleted for good.</p>
                </Dialog.Confirm>
            )}
        </ServerContentBlock>
    );
}

function NewWorldForm({ data, pending, onCreate }: { data: WorldsResponse; pending: boolean; onCreate: (name: string) => void }) {
    const [name, setName] = useState('');
    const trimmed = name.trim();
    const exists = data.worlds.some((world) => world.name.toLowerCase() === trimmed.toLowerCase());
    const error =
        trimmed === ''
            ? null
            : !validWorldName(trimmed)
              ? 'Use letters, numbers, spaces, dashes, underscores and dots, up to 20 characters.'
              : exists
                ? 'A world with this name exists already. Use it from the list above.'
                : null;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (trimmed !== '' && error === null) {
            onCreate(trimmed);
            setName('');
        }
    };

    return (
        <TitledGreyBox title={'New world'}>
            <form className={'vh:flex vh:flex-col vh:gap-3'} onSubmit={submit}>
                <p className={'vh:text-sm vh:text-muted-foreground'}>
                    Sets the WORLD startup variable to a name that has no files yet. Valheim generates that world when the server starts.
                </p>
                <Field label={'World name'} htmlFor={'vh-new-world'} error={error}>
                    <Input
                        id={'vh-new-world'}
                        value={name}
                        maxLength={20}
                        $hasError={error !== null}
                        onChange={(event) => setName(event.target.value)}
                    />
                </Field>
                <div>
                    <Button type={'submit'} size={'small'} isLoading={pending} disabled={trimmed === '' || error !== null}>
                        Use new world
                    </Button>
                </div>
            </form>
        </TitledGreyBox>
    );
}
