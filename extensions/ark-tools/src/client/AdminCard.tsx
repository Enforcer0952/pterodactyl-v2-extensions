import { useEffect, useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
    Button,
    Dialog,
    Input,
    Select,
    SocketEvent,
    Switch,
    TitledGreyBox,
    httpErrorToHuman,
    toast,
    useServerPermission,
    useServerWebsocketEvent,
    type SdkServer,
} from '@pterodactyl/sdk';
import {
    cancelRestart,
    getRestart,
    keys,
    runAdminCommand,
    saveDailyRestart,
    startRestart,
    type AdminAction,
    type RestartStatus,
} from './api';
import { isArk } from './PlayersCard';

export default function AdminSlot({ data: server }: { data: SdkServer }) {
    const canAdmin = useServerPermission('ext.ark-tools.admin');
    const canRestart = useServerPermission('ext.ark-tools.restart');
    if (!isArk(server) || (!canAdmin && !canRestart)) return null;

    return <AdminCard server={server} canAdmin={canAdmin} canRestart={canRestart} />;
}

const MINUTE_LABEL = (minutes: number) => (minutes === 0 ? 'No countdown' : `${minutes} minute${minutes === 1 ? '' : 's'} warning`);

export function AdminCard({ server, canAdmin, canRestart }: { server: SdkServer; canAdmin: boolean; canRestart: boolean }) {
    const identifier = server.attributes.identifier;
    const queryClient = useQueryClient();
    const [message, setMessage] = useState('');
    const [confirmDinos, setConfirmDinos] = useState(false);
    const [state, setState] = useState<string | null>(null);

    useServerWebsocketEvent(SocketEvent.STATUS, (status) => {
        setState(status);
        if (canRestart) void queryClient.invalidateQueries({ queryKey: keys.restart(identifier) });
    });

    const command = useMutation({
        mutationFn: (input: { action: AdminAction; message?: string }) => runAdminCommand(identifier, input.action, input.message),
        onSuccess: (_, input) => {
            toast.success(
                {
                    save: 'World saved.',
                    'wild-dinos': 'Wild dinos wiped. They respawn over the next minutes.',
                    broadcast: 'Broadcast sent.',
                    chat: 'Message sent.',
                }[input.action],
            );
            if (input.action === 'broadcast' || input.action === 'chat') setMessage('');
            setConfirmDinos(false);
        },
        onError: (error) => toast.error(httpErrorToHuman(error)),
    });

    const send = (action: 'broadcast' | 'chat') => (event?: FormEvent) => {
        event?.preventDefault();
        if (message.trim() !== '') command.mutate({ action, message });
    };

    const offline = state !== null && state !== 'running';

    return (
        <TitledGreyBox title={'Server admin'}>
            <div className={'ark:flex ark:flex-col ark:gap-4'}>
                {canAdmin && (
                    <div className={'ark:flex ark:flex-col ark:gap-3'}>
                        <div className={'ark:flex ark:flex-wrap ark:gap-2'}>
                            <Button size={'small'} isSecondary disabled={command.isPending || offline} onClick={() => command.mutate({ action: 'save' })}>
                                Save world
                            </Button>
                            <Button.Danger size={'small'} isSecondary disabled={command.isPending || offline} onClick={() => setConfirmDinos(true)}>
                                Wipe wild dinos
                            </Button.Danger>
                        </div>
                        <form className={'ark:flex ark:flex-wrap ark:gap-2'} onSubmit={send('broadcast')}>
                            <div className={'ark:min-w-48 ark:flex-1'}>
                                <Input
                                    value={message}
                                    maxLength={300}
                                    placeholder={'Message to all players'}
                                    aria-label={'Message to all players'}
                                    onChange={(event) => setMessage(event.target.value)}
                                />
                            </div>
                            <Button size={'small'} type={'submit'} disabled={command.isPending || offline || message.trim() === ''}>
                                Broadcast
                            </Button>
                            <Button size={'small'} isSecondary disabled={command.isPending || offline || message.trim() === ''} onClick={() => send('chat')()}>
                                Chat
                            </Button>
                        </form>
                        <p className={'ark:text-xs ark:text-muted-foreground'}>
                            Broadcast shows the message on every screen; Chat posts it in chat as SERVER.
                        </p>
                    </div>
                )}

                {canRestart && <RestartSection identifier={identifier} offline={offline} />}
            </div>

            {confirmDinos && (
                <Dialog.Confirm
                    open
                    title={'Wipe all wild dinos?'}
                    confirm={'Wipe'}
                    pending={command.isPending}
                    onClose={() => setConfirmDinos(false)}
                    onConfirmed={() => command.mutate({ action: 'wild-dinos' })}
                >
                    <p className={'ark:text-sm'}>
                        Every untamed creature on the map is removed and respawns fresh. Tamed dinos are not touched. This can lag the server for a
                        moment.
                    </p>
                </Dialog.Confirm>
            )}
        </TitledGreyBox>
    );
}

function RestartSection({ identifier, offline }: { identifier: string; offline: boolean }) {
    const queryClient = useQueryClient();
    const [minutes, setMinutes] = useState(5);
    const status = useQuery({
        queryKey: keys.restart(identifier),
        queryFn: () => getRestart(identifier),
        refetchInterval: (query) => (query.state.data?.running ? 10_000 : false),
    });

    const update = (result: RestartStatus) => queryClient.setQueryData(keys.restart(identifier), result);
    const onError = (error: unknown) => toast.error(httpErrorToHuman(error));

    const start = useMutation({
        mutationFn: () => startRestart(identifier, minutes),
        onSuccess: (result) => {
            update(result);
            toast.success(minutes === 0 ? 'Saving and restarting now.' : `Restart in ${minutes} minute${minutes === 1 ? '' : 's'}. Players are warned in game.`);
        },
        onError,
    });
    const cancel = useMutation({
        mutationFn: () => cancelRestart(identifier),
        onSuccess: (result) => {
            update(result);
            toast.success('Restart cancelled.');
        },
        onError,
    });

    const running = status.data?.running;
    const options = (status.data?.minutes ?? [0, 1, 5, 10, 15, 30]).map((value) => ({ value, label: MINUTE_LABEL(value) }));

    return (
        <div className={'ark:flex ark:flex-col ark:gap-3 ark:border-t ark:border-border ark:pt-4'}>
            <div>
                <p className={'ark:text-sm ark:font-medium ark:text-foreground'}>Safe restart</p>
                <p className={'ark:text-xs ark:text-muted-foreground'}>
                    Warns players in game, saves the world, then restarts. Also installs Workshop mod updates.
                </p>
            </div>

            {running ? (
                <div className={'ark:flex ark:flex-wrap ark:items-center ark:gap-3'}>
                    <Countdown to={running.restart_at} kind={running.kind} />
                    <Button.Danger size={'small'} isSecondary isLoading={cancel.isPending} onClick={() => cancel.mutate()}>
                        Cancel restart
                    </Button.Danger>
                </div>
            ) : (
                <div className={'ark:flex ark:flex-wrap ark:items-center ark:gap-2'}>
                    <div className={'ark:w-56'}>
                        <Select options={options} value={minutes} onChange={(value) => setMinutes(Number(value))} />
                    </div>
                    <Button size={'small'} isLoading={start.isPending} disabled={offline || status.isLoading} onClick={() => start.mutate()}>
                        Restart
                    </Button>
                </div>
            )}

            {status.data && <DailyRestart identifier={identifier} status={status.data} options={options} onSaved={update} />}
        </div>
    );
}

function DailyRestart({
    identifier,
    status,
    options,
    onSaved,
}: {
    identifier: string;
    status: RestartStatus;
    options: { value: number; label: string }[];
    onSaved: (status: RestartStatus) => void;
}) {
    const canSchedule = useServerPermission('schedule.create');
    const [time, setTime] = useState(status.daily?.time ?? '05:00');
    const [minutes, setMinutes] = useState(status.daily?.minutes ?? 10);

    useEffect(() => {
        if (status.daily) {
            setTime(status.daily.time);
            setMinutes(status.daily.minutes);
        }
    }, [status.daily?.time, status.daily?.minutes]); // eslint-disable-line react-hooks/exhaustive-deps

    const save = useMutation({
        mutationFn: (enabled: boolean) => saveDailyRestart(identifier, { enabled, time, minutes }),
        onSuccess: (result, enabled) => {
            onSaved(result);
            toast.success(enabled ? `Daily restart set for ${time} (${result.timezone}).` : 'Daily restart turned off.');
        },
        onError: (error) => toast.error(httpErrorToHuman(error)),
    });

    const enabled = status.daily?.enabled ?? false;
    const changed = enabled && (time !== status.daily?.time || minutes !== status.daily?.minutes);

    return (
        <div className={'ark:flex ark:flex-col ark:gap-2'}>
            <Switch
                checked={enabled}
                disabled={!canSchedule || save.isPending}
                onChange={(checked) => save.mutate(checked)}
                label={'Daily restart'}
                description={
                    enabled && status.daily?.next_run_at
                        ? `Next countdown starts ${new Date(status.daily.next_run_at).toLocaleString()}. Shows on the Schedules page.`
                        : `Restart at the same time every day (panel time zone: ${status.timezone}).`
                }
            />
            <div className={'ark:flex ark:flex-wrap ark:items-center ark:gap-2'}>
                <Input
                    type={'time'}
                    value={time}
                    aria-label={'Daily restart time'}
                    className={'ark:w-32'}
                    disabled={!canSchedule}
                    onChange={(event) => setTime(event.target.value)}
                />
                <div className={'ark:w-56'}>
                    <Select options={options} value={minutes} disabled={!canSchedule} onChange={(value) => setMinutes(Number(value))} />
                </div>
                {changed && (
                    <Button size={'small'} isLoading={save.isPending} onClick={() => save.mutate(true)}>
                        Save
                    </Button>
                )}
            </div>
        </div>
    );
}

function Countdown({ to, kind }: { to: string | null; kind: 'now' | 'daily' | 'apply' }) {
    const [now, setNow] = useState(() => Date.now());
    useEffect(() => {
        const timer = window.setInterval(() => setNow(Date.now()), 1000);
        return () => window.clearInterval(timer);
    }, []);

    const left = to ? Math.max(0, Math.round((new Date(to).getTime() - now) / 1000)) : null;
    const label = left === null ? '' : left === 0 ? 'any moment' : `in ${Math.floor(left / 60)}:${String(left % 60).padStart(2, '0')}`;

    return (
        <p className={'ark:text-sm ark:text-foreground'} role={'status'}>
            {kind === 'daily' ? 'Daily restart' : kind === 'apply' ? 'Restart to apply settings' : 'Restart'} {label}
        </p>
    );
}
