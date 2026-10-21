import { useEffect, useRef, useState, type FormEvent, type ReactNode } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
    Alert,
    Button,
    Dialog,
    Input,
    SocketEvent,
    Spinner,
    TitledGreyBox,
    httpErrorToHuman,
    toast,
    useServerPermission,
    useServerWebsocketEvent,
    type SdkServer,
} from '@pterodactyl/sdk';
import {
    USER_ID,
    enableRestApi,
    getPlayers,
    isPalworldServer,
    keys,
    runPlayerAction,
    type OnlinePlayer,
    type PlayerAction,
    type PlayersResponse,
} from './api';
import { formatDuration } from './format';

interface Confirm {
    action: 'kick' | 'ban';
    player: OnlinePlayer;
}

export default function PlayersSlot({ data: server }: { data: SdkServer }) {
    const canView = useServerPermission('ext.palworld-tools.view');
    if (!canView || !isPalworldServer(server)) return null;

    return <PlayersCard server={server} />;
}

export function PlayersCard({ server }: { server: SdkServer }) {
    const identifier = server.attributes.identifier;
    const queryClient = useQueryClient();
    const [confirm, setConfirm] = useState<Confirm | null>(null);
    const [reason, setReason] = useState('');
    const [enable, setEnable] = useState(false);

    const can = {
        players: useServerPermission('ext.palworld-tools.players'),
        settings: useServerPermission('ext.palworld-tools.settings'),
        files: useServerPermission('file.update'),
        stop: useServerPermission('control.stop'),
        start: useServerPermission('control.start'),
    };

    const players = useQuery({
        queryKey: keys.players(identifier),
        queryFn: () => getPlayers(identifier),
        staleTime: 10_000,
        refetchInterval: (query) => (query.state.data?.available ? 20_000 : false),
    });

    const action = useMutation({
        mutationFn: (input: { action: PlayerAction; user_id?: string; message?: string }) => runPlayerAction(identifier, input),
        onSuccess: (result, input) => {
            queryClient.setQueryData(keys.players(identifier), result);
            toast.success(
                {
                    kick: 'Player kicked.',
                    ban: 'Player banned.',
                    unban: 'Player unbanned.',
                    announce: 'Message sent.',
                    save: 'World saved.',
                }[input.action],
            );
            setConfirm(null);
            setReason('');
        },
        onError: (error) => toast.error(httpErrorToHuman(error)),
    });

    const turnOn = useMutation({
        mutationFn: (restart: boolean) => enableRestApi(identifier, restart),
        onSuccess: (result) => {
            toast.success(
                result.result === 'waiting'
                    ? 'Turned on. The server restarts to apply it.'
                    : 'Turned on. It applies the next time the server starts.',
            );
            if (!result.allocated) {
                toast.info(
                    `Port ${result.port} is not one of this server's ports yet. Add it on the Network page so the panel can reach the REST API.`,
                );
            }
            setEnable(false);
            queryClient.invalidateQueries({ queryKey: keys.players(identifier) });
            queryClient.invalidateQueries({ queryKey: keys.settings(identifier) });
        },
        onError: (error) => {
            toast.error(httpErrorToHuman(error));
            setEnable(false);
        },
    });

    const refreshTimer = useRef<number>(undefined);
    useServerWebsocketEvent(SocketEvent.STATUS, (status) => {
        window.clearTimeout(refreshTimer.current);
        if (status === 'running') {
            // The REST API needs a moment after the game starts.
            refreshTimer.current = window.setTimeout(() => queryClient.invalidateQueries({ queryKey: keys.players(identifier) }), 15_000);
        } else {
            queryClient.setQueryData<PlayersResponse>(keys.players(identifier), (current) =>
                current
                    ? {
                          ...current,
                          state: status,
                          available: false,
                          reason: status === 'offline' ? 'offline' : current.reason,
                          players: [],
                      }
                    : current,
            );
        }
    });
    useEffect(() => () => window.clearTimeout(refreshTimer.current), []);

    const data = players.data;
    const canTurnOn = can.settings && can.files;
    const running = data?.state === 'running';

    return (
        <TitledGreyBox title={'Players'}>
            <div className={'pal:flex pal:flex-col pal:gap-3'}>
                {players.isLoading ? (
                    <Spinner centered size={'small'} />
                ) : players.error || !data ? (
                    <Alert type={'danger'}>{httpErrorToHuman(players.error)}</Alert>
                ) : !data.available ? (
                    <Unavailable
                        data={data}
                        canTurnOn={canTurnOn && (!running || (can.stop && can.start))}
                        pending={turnOn.isPending}
                        onTurnOn={() => setEnable(true)}
                        onRetry={() => players.refetch()}
                        retrying={players.isFetching}
                    />
                ) : (
                    <>
                        <InfoLine data={data} />
                        {data.players.length === 0 ? (
                            <p className={'pal:py-3 pal:text-center pal:text-sm pal:text-muted-foreground'}>Nobody is online.</p>
                        ) : (
                            <ul className={'pal:flex pal:flex-col pal:divide-y pal:divide-border'}>
                                {data.players.map((player) => (
                                    <li key={player.user_id} className={'pal:flex pal:items-center pal:gap-3 pal:py-2'}>
                                        <div className={'pal:min-w-0 pal:flex-1'}>
                                            <p className={'pal:truncate pal:text-sm pal:font-medium pal:text-foreground'}>{player.name}</p>
                                            <p className={'pal:truncate pal:text-xs pal:text-muted-foreground'}>{playerDetail(player)}</p>
                                        </div>
                                        {can.players && (
                                            <div className={'pal:flex pal:shrink-0 pal:gap-1.5'}>
                                                <Button
                                                    size={'xsmall'}
                                                    isSecondary
                                                    disabled={action.isPending}
                                                    onClick={() => setConfirm({ action: 'kick', player })}
                                                >
                                                    Kick
                                                </Button>
                                                <Button.Danger
                                                    size={'xsmall'}
                                                    isSecondary
                                                    disabled={action.isPending}
                                                    onClick={() => setConfirm({ action: 'ban', player })}
                                                >
                                                    Ban
                                                </Button.Danger>
                                            </div>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                        {can.players && <Tools pending={action.isPending} run={(input) => action.mutate(input)} />}
                        <div className={'pal:flex pal:justify-end'}>
                            <Button.Text size={'xsmall'} isLoading={players.isFetching} onClick={() => players.refetch()}>
                                Refresh
                            </Button.Text>
                        </div>
                    </>
                )}
            </div>

            {confirm && (
                <Dialog.Confirm
                    open
                    title={`${confirm.action === 'ban' ? 'Ban' : 'Kick'} ${confirm.player.name}?`}
                    confirm={confirm.action === 'ban' ? 'Ban' : 'Kick'}
                    pending={action.isPending}
                    onClose={() => {
                        setConfirm(null);
                        setReason('');
                    }}
                    onConfirmed={() =>
                        action.mutate({
                            action: confirm.action,
                            user_id: confirm.player.user_id,
                            ...(reason.trim() ? { message: reason.trim() } : {}),
                        })
                    }
                >
                    <div className={'pal:flex pal:flex-col pal:gap-3'}>
                        <p className={'pal:text-sm'}>
                            {confirm.action === 'ban'
                                ? 'They are disconnected and cannot join again until unbanned.'
                                : 'They are disconnected but can join again.'}
                        </p>
                        <div className={'pal:flex pal:flex-col pal:gap-1'}>
                            <label htmlFor={'pal-reason'} className={'pal:text-sm pal:font-medium pal:text-foreground'}>
                                Message to the player (optional)
                            </label>
                            <Input id={'pal-reason'} value={reason} maxLength={100} onChange={(event) => setReason(event.target.value)} />
                        </div>
                    </div>
                </Dialog.Confirm>
            )}

            {enable && (
                <Dialog.Confirm
                    open
                    title={'Turn on the REST API?'}
                    confirm={running ? 'Turn on and restart' : 'Turn on'}
                    pending={turnOn.isPending}
                    onClose={() => setEnable(false)}
                    onConfirmed={() => turnOn.mutate(running)}
                >
                    <p className={'pal:text-sm'}>
                        {running
                            ? 'This sets RESTAPIEnabled=True in PalWorldSettings.ini. The server is stopped, the file is written and the server starts again.'
                            : 'This sets RESTAPIEnabled=True in PalWorldSettings.ini. It applies the next time the server starts.'}{' '}
                        Keep the REST API port closed to the internet: it uses plain HTTP and only the admin password protects it.
                    </p>
                </Dialog.Confirm>
            )}
        </TitledGreyBox>
    );
}

function playerDetail(player: OnlinePlayer): string {
    return [
        player.level !== null ? `Level ${player.level}` : null,
        player.ping !== null ? `${player.ping} ms` : null,
        player.x !== null && player.y !== null ? `X ${player.x}, Y ${player.y}` : null,
        player.buildings !== null ? `${player.buildings} buildings` : null,
    ]
        .filter(Boolean)
        .join(' · ');
}

function InfoLine({ data }: { data: PlayersResponse }) {
    const metrics = data.metrics;
    const parts = [
        data.info?.version ? `Palworld ${data.info.version}` : null,
        metrics?.fps != null ? `${metrics.fps} FPS` : null,
        metrics?.uptime != null ? `Up ${formatDuration(metrics.uptime)}` : null,
        metrics?.days != null ? `Day ${metrics.days}` : null,
        `${metrics?.players ?? data.players.length}${metrics?.max_players != null ? ` of ${metrics.max_players}` : ''} players`,
    ].filter(Boolean);

    return <p className={'pal:text-xs pal:text-muted-foreground'}>{parts.join(' · ')}</p>;
}

function Tools({ pending, run }: { pending: boolean; run: (input: { action: PlayerAction; user_id?: string; message?: string }) => void }) {
    const [message, setMessage] = useState('');
    const [unban, setUnban] = useState('');
    const unbanError = unban.trim() !== '' && !USER_ID.test(unban.trim()) ? 'Use an id like steam_76561198000000000.' : null;

    const announce = (event: FormEvent) => {
        event.preventDefault();
        if (message.trim() === '') return;
        run({ action: 'announce', message: message.trim() });
        setMessage('');
    };
    const sendUnban = (event: FormEvent) => {
        event.preventDefault();
        if (unban.trim() === '' || unbanError) return;
        run({ action: 'unban', user_id: unban.trim() });
        setUnban('');
    };

    return (
        <div className={'pal:flex pal:flex-col pal:gap-3 pal:border-t pal:border-border pal:pt-3'}>
            <form className={'pal:flex pal:gap-2'} onSubmit={announce}>
                <Input
                    aria-label={'Announcement'}
                    placeholder={'Announce a message to everyone'}
                    maxLength={200}
                    value={message}
                    onChange={(event) => setMessage(event.target.value)}
                />
                <Button type={'submit'} size={'small'} isSecondary disabled={pending || message.trim() === ''}>
                    Announce
                </Button>
            </form>
            <form className={'pal:flex pal:gap-2'} onSubmit={sendUnban}>
                <Input
                    aria-label={'Player id to unban'}
                    placeholder={'Unban a player: steam_76561198000000000'}
                    maxLength={64}
                    $hasError={unbanError !== null}
                    value={unban}
                    onChange={(event) => setUnban(event.target.value)}
                />
                <Button type={'submit'} size={'small'} isSecondary disabled={pending || unban.trim() === '' || unbanError !== null}>
                    Unban
                </Button>
            </form>
            {unbanError && <p className={'pal:text-xs pal:text-destructive'}>{unbanError}</p>}
            <div>
                <Button size={'small'} isSecondary disabled={pending} onClick={() => run({ action: 'save' })}>
                    Save world
                </Button>
            </div>
        </div>
    );
}

function Unavailable({
    data,
    canTurnOn,
    pending,
    onTurnOn,
    onRetry,
    retrying,
}: {
    data: PlayersResponse;
    canTurnOn: boolean;
    pending: boolean;
    onTurnOn: () => void;
    onRetry: () => void;
    retrying: boolean;
}) {
    let body: ReactNode;
    switch (data.reason) {
        case 'offline':
            body = <p>The server is {data.state}. Players show up here once it is running.</p>;
            break;
        case 'disabled':
            body = (
                <>
                    <p>
                        The REST API is off. Palworld Tools uses it to list players and to kick, ban and announce. Turning it on needs a
                        server restart.
                    </p>
                    {canTurnOn ? (
                        <div>
                            <Button size={'small'} isLoading={pending} onClick={onTurnOn}>
                                Turn on the REST API
                            </Button>
                        </div>
                    ) : (
                        <p className={'pal:text-xs'}>Ask someone with access to the Palworld settings to set RESTAPIEnabled to True.</p>
                    )}
                </>
            );
            break;
        case 'not_allocated':
            body = (
                <p>
                    The REST API listens on port {data.port}, but that is not one of this server&apos;s ports, so the panel cannot reach it.
                    Add port {data.port} to the server on the Network page. Do not open it to the internet: it is meant for the panel only.
                </p>
            );
            break;
        case 'no_password':
            body = (
                <p>
                    The REST API logs in with the admin password, and none is set. Set one in the Palworld settings and restart the server.
                </p>
            );
            break;
        case 'unreachable':
            body = (
                <>
                    <p>
                        The panel could not connect to the REST API{data.port ? ` on port ${data.port}` : ''}. The server may still be
                        starting, the port may be blocked by a firewall, or the REST API was turned on after the server started and needs a
                        restart.
                    </p>
                    <div>
                        <Button size={'small'} isSecondary isLoading={retrying} onClick={onRetry}>
                            Try again
                        </Button>
                    </div>
                </>
            );
            break;
        default:
            body = (
                <>
                    <p>{data.message ?? 'The REST API did not answer.'}</p>
                    <div>
                        <Button size={'small'} isSecondary isLoading={retrying} onClick={onRetry}>
                            Try again
                        </Button>
                    </div>
                </>
            );
    }

    return <div className={'pal:flex pal:flex-col pal:gap-3 pal:text-sm pal:text-muted-foreground'}>{body}</div>;
}
