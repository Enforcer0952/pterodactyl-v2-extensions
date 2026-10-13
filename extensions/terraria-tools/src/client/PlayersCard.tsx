import { useEffect, useRef, useState, type ReactNode } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
    Alert,
    Button,
    Dialog,
    SocketEvent,
    Spinner,
    TitledGreyBox,
    httpErrorToHuman,
    toast,
    useServerPermission,
    useServerWebsocketEvent,
    type SdkServer,
} from '@pterodactyl/sdk';
import { getBans, getOnline, isTerrariaServer, keys, removeBan, runPlayerAction, type BansResponse, type OnlineResponse } from './api';
import { consolePresence } from './format';

type Tab = 'online' | 'bans';

interface Confirm {
    action: 'kick' | 'ban';
    name: string;
}

export default function PlayersSlot({ data: server }: { data: SdkServer }) {
    const canView = useServerPermission('ext.terraria-tools.view');
    const canConsole = useServerPermission('control.console');
    if (!canView || !isTerrariaServer(server)) return null;

    return <PlayersCard server={server} canConsole={canConsole} />;
}

export function PlayersCard({ server, canConsole = true }: { server: SdkServer; canConsole?: boolean }) {
    const identifier = server.attributes.identifier;
    const queryClient = useQueryClient();
    const [tab, setTab] = useState<Tab>('online');
    const [confirm, setConfirm] = useState<Confirm | null>(null);

    const can = {
        kick: useServerPermission('ext.terraria-tools.kick'),
        ban: useServerPermission('ext.terraria-tools.ban'),
        files: useServerPermission('file.read-content'),
    };
    const showBans = can.ban && can.files;

    // The next fetch skips the short cache: set by Refresh and by join and leave lines.
    const force = useRef(false);
    const online = useQuery({
        queryKey: keys.online(identifier),
        queryFn: () => {
            const refresh = force.current;
            force.current = false;
            return getOnline(identifier, refresh);
        },
        staleTime: 30_000,
        enabled: canConsole,
    });
    const bans = useQuery({
        queryKey: keys.bans(identifier),
        queryFn: () => getBans(identifier),
        staleTime: 30_000,
        enabled: showBans && tab === 'bans',
    });

    const refresh = () => {
        force.current = true;
        void queryClient.invalidateQueries({ queryKey: keys.online(identifier) });
    };

    const action = useMutation({
        mutationFn: (input: Confirm) => runPlayerAction(identifier, input.action, input.name),
        onSuccess: (_, input) => {
            queryClient.setQueryData<OnlineResponse>(keys.online(identifier), (current) =>
                current?.players ? { ...current, players: current.players.filter((player) => player.name !== input.name) } : current,
            );
            if (input.action === 'ban') void queryClient.invalidateQueries({ queryKey: keys.bans(identifier) });
            toast.success(input.action === 'ban' ? `Banned ${input.name}.` : `Kicked ${input.name}.`);
            setConfirm(null);
        },
        onError: (error) => {
            toast.error(httpErrorToHuman(error));
            setConfirm(null);
            refresh();
        },
    });

    const unban = useMutation({
        mutationFn: (id: string) => removeBan(identifier, id),
        onSuccess: (result) => {
            queryClient.setQueryData(keys.bans(identifier), result);
            toast.success('Ban removed.');
        },
        onError: (error) => toast.error(httpErrorToHuman(error)),
    });

    // The console prints a line when someone joins or leaves, so the list follows
    // without polling. Several lines in a row cause one refresh.
    const timer = useRef<number>(undefined);
    const later = (ms: number) => {
        window.clearTimeout(timer.current);
        timer.current = window.setTimeout(refresh, ms);
    };
    useServerWebsocketEvent(SocketEvent.CONSOLE_OUTPUT, (line: string) => {
        if (consolePresence(line) !== null && canConsole) later(1500);
    });
    useServerWebsocketEvent(SocketEvent.STATUS, (status) => {
        window.clearTimeout(timer.current);
        if (status === 'running') {
            later(5000);
        } else {
            queryClient.setQueryData<OnlineResponse>(keys.online(identifier), { state: status, players: null });
        }
        queryClient.setQueryData<BansResponse>(keys.bans(identifier), (current) => (current ? { ...current, state: status } : current));
    });
    useEffect(() => () => window.clearTimeout(timer.current), []);

    const state = online.data?.state ?? bans.data?.state;
    const running = state === 'running';
    const count = online.data?.players ? ` (${online.data.players.length})` : '';
    const banCount = bans.data?.exists ? ` (${new Set(bans.data.entries.map((entry) => entry.id)).size})` : '';

    return (
        <TitledGreyBox title={'Players'}>
            <div className={'terra:flex terra:flex-col terra:gap-3'}>
                <div className={'terra:flex terra:flex-wrap terra:items-center terra:gap-2'} role={'tablist'}>
                    <Button
                        size={'xsmall'}
                        isSecondary={tab !== 'online'}
                        role={'tab'}
                        aria-selected={tab === 'online'}
                        onClick={() => setTab('online')}
                    >
                        {`Online${count}`}
                    </Button>
                    {showBans && (
                        <Button
                            size={'xsmall'}
                            isSecondary={tab !== 'bans'}
                            role={'tab'}
                            aria-selected={tab === 'bans'}
                            onClick={() => setTab('bans')}
                        >
                            {`Bans${banCount}`}
                        </Button>
                    )}
                    {tab === 'online' && running && canConsole && (
                        <Button.Text size={'xsmall'} className={'terra:ml-auto'} isLoading={online.isFetching} onClick={refresh}>
                            Refresh
                        </Button.Text>
                    )}
                </div>

                {tab === 'online' ? (
                    !canConsole ? (
                        <Empty>You need the console permission to see who is online.</Empty>
                    ) : online.isLoading ? (
                        <Spinner centered size={'small'} />
                    ) : online.error ? (
                        <Alert type={'danger'}>{httpErrorToHuman(online.error)}</Alert>
                    ) : !running ? (
                        <Empty>The server is {state ?? 'offline'}. Players show up here once it is running.</Empty>
                    ) : !online.data?.players ? (
                        <Empty>Could not read the player list from the console. Press Refresh to try again.</Empty>
                    ) : online.data.players.length === 0 ? (
                        <Empty>Nobody is online.</Empty>
                    ) : (
                        <Rows>
                            {online.data.players.map((player, index) => (
                                <Row key={`${player.name}:${index}`} name={player.name}>
                                    {can.kick && (
                                        <Button
                                            size={'xsmall'}
                                            isSecondary
                                            disabled={action.isPending}
                                            onClick={() => action.mutate({ action: 'kick', name: player.name })}
                                        >
                                            Kick
                                        </Button>
                                    )}
                                    {can.ban && (
                                        <Button.Danger
                                            size={'xsmall'}
                                            isSecondary
                                            disabled={action.isPending}
                                            onClick={() => setConfirm({ action: 'ban', name: player.name })}
                                        >
                                            Ban
                                        </Button.Danger>
                                    )}
                                </Row>
                            ))}
                        </Rows>
                    )
                ) : bans.isLoading ? (
                    <Spinner centered size={'small'} />
                ) : bans.error || !bans.data ? (
                    <Alert type={'danger'}>{httpErrorToHuman(bans.error)}</Alert>
                ) : (
                    <BanTab data={bans.data} pending={unban.isPending} onUnban={(id) => unban.mutate(id)} />
                )}
            </div>

            {confirm && (
                <Dialog.Confirm
                    open
                    title={`Ban ${confirm.name}?`}
                    confirm={'Ban'}
                    pending={action.isPending}
                    onClose={() => setConfirm(null)}
                    onConfirmed={() => action.mutate(confirm)}
                >
                    <p className={'terra:text-sm'}>
                        They are disconnected and cannot join again until the ban is removed from the ban list.
                    </p>
                </Dialog.Confirm>
            )}
        </TitledGreyBox>
    );
}

function BanTab({ data, pending, onUnban }: { data: BansResponse; pending: boolean; onUnban: (id: string) => void }) {
    const running = data.state !== 'offline';

    if (!data.exists) {
        return <Empty>There is no ban list yet. The server creates {data.path} when it bans someone.</Empty>;
    }

    // The same address can be listed more than once; one row each.
    const seen = new Set<string>();
    const entries = data.entries.filter((entry) => (seen.has(entry.id) ? false : (seen.add(entry.id), true)));

    return (
        <div className={'terra:flex terra:flex-col terra:gap-2'}>
            {running && data.editable && (
                <p className={'terra:text-xs terra:text-muted-foreground'}>
                    Stop the server to remove a ban. The file is edited and the old version is kept as a .bak.
                </p>
            )}
            {entries.length === 0 ? (
                <Empty>Nobody is banned.</Empty>
            ) : (
                <Rows>
                    {entries.map((entry) => (
                        <Row key={entry.id} name={entry.name ?? 'Unknown name'} detail={entry.id}>
                            {data.editable && (
                                <Button size={'xsmall'} isSecondary disabled={pending || running} onClick={() => onUnban(entry.id)}>
                                    Unban
                                </Button>
                            )}
                        </Row>
                    ))}
                </Rows>
            )}
        </div>
    );
}

function Rows({ children }: { children: ReactNode }) {
    return (
        <ul className={'terra:flex terra:max-h-80 terra:flex-col terra:divide-y terra:divide-border terra:overflow-y-auto'}>{children}</ul>
    );
}

function Row({ name, detail, children }: { name: string; detail?: string; children?: ReactNode }) {
    return (
        <li className={'terra:flex terra:items-center terra:gap-3 terra:py-2'}>
            <div className={'terra:min-w-0 terra:flex-1'}>
                <span className={'terra:block terra:truncate terra:text-sm terra:font-medium terra:text-foreground'}>{name}</span>
                {detail && <p className={'terra:truncate terra:font-mono terra:text-xs terra:text-muted-foreground'}>{detail}</p>}
            </div>
            <div className={'terra:flex terra:shrink-0 terra:flex-wrap terra:items-center terra:justify-end terra:gap-1.5'}>{children}</div>
        </li>
    );
}

export function Empty({ children }: { children: ReactNode }) {
    return <p className={'terra:py-4 terra:text-center terra:text-sm terra:text-muted-foreground'}>{children}</p>;
}
