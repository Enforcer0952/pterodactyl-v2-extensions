import { useState, type FormEvent, type ReactNode } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
    Alert,
    Button,
    Checkbox,
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
    WORLD_DIFFICULTIES,
    WORLD_SIZES,
    backupWorld,
    createWorld,
    deleteBackup,
    deleteWorld,
    getWorlds,
    keys,
    restoreBackup,
    switchWorld,
    validWorldName,
    type World,
    type WorldBackup,
    type WorldsResponse,
    type WorldsResult,
} from '../api';
import { formatBytes, formatDate, parseStamp, skippedMessage } from '../format';

type Pending =
    | { kind: 'delete'; world: World }
    | { kind: 'restore'; backup: WorldBackup }
    | { kind: 'delete-backup'; backup: WorldBackup }
    | { kind: 'switch'; world: World };

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
    };

    const worlds = useQuery({ queryKey: keys.worlds(identifier), queryFn: () => getWorlds(identifier), refetchOnWindowFocus: true });

    const done = (message: (result: WorldsResult) => string) => ({
        onSuccess: (result: WorldsResult) => {
            queryClient.setQueryData<WorldsResponse>(keys.worlds(identifier), result);
            toast.success(message(result));
            const skipped = result.result?.variables?.skipped ?? [];
            if (skipped.length > 0) toast.info(skippedMessage(result.result?.variables));
            setPending(null);
        },
        onError: (error: unknown) => {
            toast.error(httpErrorToHuman(error));
            setPending(null);
        },
    });

    const switching = useMutation({
        mutationFn: (name: string) => switchWorld(identifier, name),
        ...done((r) => `${r.result?.name ?? 'The world'} loads on the next start.`),
    });
    const backup = useMutation({ mutationFn: (name: string) => backupWorld(identifier, name), ...done(() => 'Backup made.') });
    const restore = useMutation({
        mutationFn: (file: string) => restoreBackup(identifier, file),
        ...done(
            (r) => `Restored ${r.result?.world ?? 'the world'}.${r.result?.safety ? ' The world it replaced was backed up first.' : ''}`,
        ),
    });
    const removeBackup = useMutation({ mutationFn: (file: string) => deleteBackup(identifier, file), ...done(() => 'Backup deleted.') });
    const remove = useMutation({
        mutationFn: (input: { name: string; backup: boolean }) => deleteWorld(identifier, input.name, input.backup),
        ...done((r) => (r.result?.backup ? 'World deleted. A backup was made first.' : 'World deleted.')),
    });
    const create = useMutation({
        mutationFn: (input: Parameters<typeof createWorld>[1]) => createWorld(identifier, input),
        ...done((r) => `${r.result?.name ?? 'The world'} is generated when the server starts.`),
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
    const busy = [switching, backup, restore, removeBackup, remove, create].some((mutation) => mutation.isPending);

    return (
        <ServerContentBlock title={'Worlds'}>
            <div className={'terra:flex terra:flex-col terra:gap-4'}>
                {!stopped && (
                    <Alert type={'info'}>
                        The server is {data.state}. Stop it to switch, restore or delete worlds. Backups can be made while it runs: the
                        server saves first.
                    </Alert>
                )}

                <TitledGreyBox title={`Worlds (${data.worlds.length})`}>
                    {data.worlds.length === 0 ? (
                        <Empty>
                            No worlds in {data.worlds_directory} yet. Set one up below, or start the server to let it generate one.
                        </Empty>
                    ) : (
                        <ul className={'terra:flex terra:flex-col terra:divide-y terra:divide-border'}>
                            {data.worlds.map((world) => (
                                <Row
                                    key={world.name}
                                    name={world.name}
                                    badge={world.active ? 'Active' : undefined}
                                    detail={[formatBytes(world.size), formatDate(world.modified)].filter(Boolean).join(' · ')}
                                >
                                    {can.update && !world.active && (
                                        <Button
                                            size={'xsmall'}
                                            isSecondary
                                            disabled={busy || !stopped}
                                            onClick={() => setPending({ kind: 'switch', world })}
                                        >
                                            Use
                                        </Button>
                                    )}
                                    {can.create && can.update && (
                                        <Button
                                            size={'xsmall'}
                                            isSecondary
                                            isLoading={backup.isPending && backup.variables === world.name}
                                            disabled={busy}
                                            onClick={() => backup.mutate(world.name)}
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
                        </ul>
                    )}
                </TitledGreyBox>

                <TitledGreyBox title={`Backups (${data.backups.length})`}>
                    <div className={'terra:flex terra:flex-col terra:gap-2'}>
                        <p className={'terra:text-xs terra:text-muted-foreground'}>
                            Kept in {data.backups_directory}. Each world keeps its newest {data.keep} backups. Restoring first backs up the
                            world it replaces.
                        </p>
                        {data.backups.length === 0 ? (
                            <Empty>No backups yet.</Empty>
                        ) : (
                            <ul
                                className={
                                    'terra:flex terra:max-h-96 terra:flex-col terra:divide-y terra:divide-border terra:overflow-y-auto'
                                }
                            >
                                {data.backups.map((item) => (
                                    <Row
                                        key={item.file}
                                        name={item.world}
                                        detail={[formatDate(parseStamp(item.stamp) ?? item.modified), formatBytes(item.size)]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    >
                                        {can.create && can.update && can.delete && (
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

                {can.update && (
                    <NewWorldForm
                        data={data}
                        disabled={busy || !stopped}
                        pending={create.isPending}
                        onCreate={(input) => create.mutate(input)}
                    />
                )}
            </div>

            {pending?.kind === 'switch' && (
                <Dialog.Confirm
                    open
                    title={`Use ${pending.world.name}?`}
                    confirm={'Use this world'}
                    pending={switching.isPending}
                    onClose={() => setPending(null)}
                    onConfirmed={() => switching.mutate(pending.world.name)}
                >
                    <p className={'terra:text-sm'}>The server loads {pending.world.name} the next time it starts. Nothing is deleted.</p>
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
                    <div className={'terra:flex terra:flex-col terra:gap-3'}>
                        <p className={'terra:text-sm'}>
                            This removes {pending.world.name}.wld and the .wld.bak and .twld files next to it.
                            {!backupFirst && ' Without a backup it cannot be undone.'}
                        </p>
                        <label className={'terra:flex terra:items-center terra:gap-2 terra:text-sm'}>
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
                    onConfirmed={() => restore.mutate(pending.backup.file)}
                >
                    <p className={'terra:text-sm'}>
                        The backup from {formatDate(parseStamp(pending.backup.stamp)) || pending.backup.stamp} replaces the world called{' '}
                        {pending.backup.world}. If that world exists it is backed up first.
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
                    onConfirmed={() => removeBackup.mutate(pending.backup.file)}
                >
                    <p className={'terra:text-sm'}>
                        The backup of {pending.backup.world} from {formatDate(parseStamp(pending.backup.stamp)) || pending.backup.stamp} is
                        deleted for good.
                    </p>
                </Dialog.Confirm>
            )}
        </ServerContentBlock>
    );
}

function NewWorldForm({
    data,
    disabled,
    pending,
    onCreate,
}: {
    data: WorldsResponse;
    disabled: boolean;
    pending: boolean;
    onCreate: (input: { name: string; size: number; difficulty: number; seed: string }) => void;
}) {
    const [name, setName] = useState('');
    const [size, setSize] = useState(2);
    const [difficulty, setDifficulty] = useState(0);
    const [seed, setSeed] = useState('');
    const [confirm, setConfirm] = useState(false);

    const trimmed = name.trim();
    const exists = data.worlds.some((world) => world.name.toLowerCase() === trimmed.toLowerCase());
    const error =
        trimmed === ''
            ? null
            : !validWorldName(trimmed)
              ? 'Use letters, numbers, spaces, dashes and underscores (up to 40 characters).'
              : exists
                ? 'A world with this name already exists.'
                : null;
    const valid = trimmed !== '' && error === null;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (valid) setConfirm(true);
    };

    return (
        <TitledGreyBox title={'New world'}>
            <form className={'terra:flex terra:flex-col terra:gap-3'} onSubmit={submit}>
                <p className={'terra:text-sm terra:text-muted-foreground'}>
                    Sets up serverconfig.txt (and the startup variables, when you may change them) so the server generates this world the
                    next time it starts. Generating a large world takes a few minutes.
                </p>
                <div className={'terra:grid terra:grid-cols-1 terra:gap-4 terra:md:grid-cols-2'}>
                    <Field label={'World name'} htmlFor={'terra-new-name'} error={error}>
                        <Input
                            id={'terra-new-name'}
                            value={name}
                            maxLength={40}
                            $hasError={error !== null}
                            onChange={(event) => setName(event.target.value)}
                        />
                    </Field>
                    <Field label={'Seed (optional)'} htmlFor={'terra-new-seed'}>
                        <Input
                            id={'terra-new-seed'}
                            value={seed}
                            maxLength={64}
                            placeholder={'Random'}
                            onChange={(event) => setSeed(event.target.value)}
                        />
                    </Field>
                    <Field label={'Size'}>
                        <Select options={WORLD_SIZES} value={size} onChange={(value) => setSize(Number(value))} />
                    </Field>
                    <Field label={'Difficulty'}>
                        <Select options={WORLD_DIFFICULTIES} value={difficulty} onChange={(value) => setDifficulty(Number(value))} />
                    </Field>
                </div>
                <div>
                    <Button type={'submit'} size={'small'} isLoading={pending} disabled={disabled || !valid}>
                        Set up world
                    </Button>
                    {disabled && !pending && (
                        <span className={'terra:ml-3 terra:text-xs terra:text-muted-foreground'}>Stop the server first.</span>
                    )}
                </div>
            </form>

            {confirm && (
                <Dialog.Confirm
                    open
                    title={`Set up ${trimmed}?`}
                    confirm={'Set up'}
                    pending={pending}
                    onClose={() => setConfirm(false)}
                    onConfirmed={() => {
                        onCreate({ name: trimmed, size, difficulty, seed: seed.trim() });
                        setConfirm(false);
                        setName('');
                        setSeed('');
                    }}
                >
                    <p className={'terra:text-sm'}>
                        The server will create a new {WORLD_SIZES.find((item) => item.value === size)?.label.toLowerCase()}{' '}
                        {WORLD_DIFFICULTIES.find((item) => item.value === difficulty)?.label} world called {trimmed} when it starts, and
                        load it instead of {data.active ?? 'the current world'}. Your other worlds are not touched.
                    </p>
                </Dialog.Confirm>
            )}
        </TitledGreyBox>
    );
}

function Field({ label, htmlFor, error, children }: { label: string; htmlFor?: string; error?: string | null; children: ReactNode }) {
    return (
        <div className={'terra:flex terra:flex-col terra:gap-1'}>
            <label htmlFor={htmlFor} className={'terra:text-sm terra:font-medium terra:text-foreground'}>
                {label}
            </label>
            {children}
            {error && <p className={'terra:text-xs terra:text-red-400'}>{error}</p>}
        </div>
    );
}

function Row({ name, badge, detail, children }: { name: string; badge?: string; detail?: string; children?: ReactNode }) {
    return (
        <li className={'terra:flex terra:items-center terra:gap-3 terra:py-2'}>
            <div className={'terra:min-w-0 terra:flex-1'}>
                <div className={'terra:flex terra:items-center terra:gap-2'}>
                    <span className={'terra:truncate terra:text-sm terra:font-medium terra:text-foreground'}>{name}</span>
                    {badge && (
                        <span
                            className={
                                'terra:rounded terra:bg-muted terra:px-1.5 terra:py-0.5 terra:text-[11px] terra:text-muted-foreground'
                            }
                        >
                            {badge}
                        </span>
                    )}
                </div>
                {detail && <p className={'terra:truncate terra:text-xs terra:text-muted-foreground'}>{detail}</p>}
            </div>
            <div className={'terra:flex terra:shrink-0 terra:flex-wrap terra:items-center terra:justify-end terra:gap-1.5'}>{children}</div>
        </li>
    );
}

function Empty({ children }: { children: ReactNode }) {
    return <p className={'terra:py-4 terra:text-center terra:text-sm terra:text-muted-foreground'}>{children}</p>;
}
