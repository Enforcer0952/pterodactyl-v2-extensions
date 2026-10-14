import { useEffect, useRef, useState } from 'react';
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
import { getPlayers, isValheimServer, keys, runPlayerAction, type PlayerAction, type PlayersResponse } from './api';
import { presence } from './console';
import { formatDate, needsPermitConfirm } from './format';
import { Empty, Row, Rows } from './shared';

interface Pending {
    action: PlayerAction;
    id: string;
    name: string;
    confirm: boolean;
}

const VERBS: Record<PlayerAction, { done: string; title: string }> = {
    ban: { done: 'banned', title: 'Ban' },
    admin: { done: 'made admin', title: 'Make admin' },
    permit: { done: 'permitted', title: 'Permit' },
};

export default function PlayersSlot({ data: server }: { data: SdkServer }) {
    const canView = useServerPermission('ext.valheim-tools.view');
    if (!canView || !isValheimServer(server)) return null;

    return <PlayersCard server={server} />;
}

export function PlayersCard({ server }: { server: SdkServer }) {
    const identifier = server.attributes.identifier;
    const queryClient = useQueryClient();
    const [pending, setPending] = useState<Pending | null>(null);

    const players = useQuery({
        queryKey: keys.players(identifier),
        queryFn: () => getPlayers(identifier),
        staleTime: 10_000,
        // The server reads the console on each request; while the server runs, keep looking.
        refetchInterval: (query) => (query.state.data?.state === 'running' ? 20_000 : false),
    });

    const refresh = () => void queryClient.invalidateQueries({ queryKey: keys.players(identifier) });

    const act = useMutation({
        mutationFn: (input: Pending) => runPlayerAction(identifier, input.action, input.id, input.confirm),
        onSuccess: (result, input) => {
            toast.success(
                result.added
                    ? `${input.name} is ${VERBS[input.action].done}. Valheim reads the list when the server starts.`
                    : `${input.name} was on that list already.`,
            );
            setPending(null);
        },
        onError: (error, input) => {
            const message = httpErrorToHuman(error);
            if (input.action === 'permit' && !input.confirm && needsPermitConfirm(message)) {
                // Adding the first player turns the permitted list on for everyone else.
                setPending({ ...input, confirm: true });
                return;
            }
            toast.error(message);
            setPending(null);
        },
    });

    // Join and leave lines in the console ask for a fresh list. Several lines cause one refresh.
    const timer = useRef<number>(undefined);
    useServerWebsocketEvent(SocketEvent.CONSOLE_OUTPUT, (line: string) => {
        if (presence(line) === null) return;
        window.clearTimeout(timer.current);
        timer.current = window.setTimeout(refresh, 1500);
    });
    useServerWebsocketEvent(SocketEvent.STATUS, (status) => {
        window.clearTimeout(timer.current);
        if (status === 'running') {
            timer.current = window.setTimeout(refresh, 5000);
        } else {
            queryClient.setQueryData<PlayersResponse>(keys.players(identifier), (current) =>
                current ? { ...current, state: status, players: [] } : current,
            );
        }
    });
    useEffect(() => () => window.clearTimeout(timer.current), []);

    const data = players.data;
    const running = data?.state === 'running';
    const canAct = data?.can_act ?? false;

    const actions = (name: string, id: string | null) =>
        id === null ? (
            <span className={'vh:text-xs vh:text-muted-foreground'}>No Steam ID</span>
        ) : (
            canAct && (
                <>
                    <Button
                        size={'xsmall'}
                        isSecondary
                        disabled={act.isPending}
                        onClick={() => setPending({ action: 'admin', id, name, confirm: false })}
                    >
                        Make admin
                    </Button>
                    <Button
                        size={'xsmall'}
                        isSecondary
                        disabled={act.isPending}
                        onClick={() => setPending({ action: 'permit', id, name, confirm: false })}
                    >
                        Permit
                    </Button>
                    <Button.Danger
                        size={'xsmall'}
                        isSecondary
                        disabled={act.isPending}
                        onClick={() => setPending({ action: 'ban', id, name, confirm: false })}
                    >
                        Ban
                    </Button.Danger>
                </>
            )
        );

    return (
        <TitledGreyBox title={data && running ? `Players (${data.players.length})` : 'Players'}>
            <div className={'vh:flex vh:flex-col vh:gap-3'}>
                {players.isLoading ? (
                    <Spinner centered size={'small'} />
                ) : players.error || !data ? (
                    <Alert type={'danger'}>{httpErrorToHuman(players.error)}</Alert>
                ) : (
                    <>
                        {running && (
                            <div className={'vh:flex vh:justify-end'}>
                                <Button.Text size={'xsmall'} isLoading={players.isFetching} onClick={refresh}>
                                    Refresh
                                </Button.Text>
                            </div>
                        )}
                        {!running ? (
                            <Empty>The server is {data.state}. Players show up here once it is running.</Empty>
                        ) : data.players.length === 0 ? (
                            <Empty>Nobody is online.</Empty>
                        ) : (
                            <Rows>
                                {data.players.map((player, index) => (
                                    <Row
                                        key={`${player.name}:${index}`}
                                        name={player.name}
                                        detail={player.list_id ?? player.id ?? undefined}
                                    >
                                        {actions(player.name, player.list_id)}
                                    </Row>
                                ))}
                            </Rows>
                        )}
                        {data.recent.length > 0 && (
                            <div className={'vh:flex vh:flex-col vh:gap-1'}>
                                <h4 className={'vh:text-xs vh:font-medium vh:uppercase vh:text-muted-foreground'}>Seen recently</h4>
                                <Rows>
                                    {data.recent.map((player) => (
                                        <Row
                                            key={player.id ?? player.name}
                                            name={player.name}
                                            detail={`Last seen ${formatDate(player.last_seen)}`}
                                        >
                                            {actions(player.name, player.list_id)}
                                        </Row>
                                    ))}
                                </Rows>
                            </div>
                        )}
                        <p className={'vh:text-xs vh:text-muted-foreground'}>
                            Valheim has no player list command, so this is read from the console while this page is open. Someone who joined
                            while nobody was looking may be missing. The buttons edit the access lists; the server reads them when it
                            starts.
                        </p>
                    </>
                )}
            </div>

            {pending && (
                <Dialog.Confirm
                    open
                    title={`${VERBS[pending.action].title} ${pending.name}?`}
                    confirm={VERBS[pending.action].title}
                    pending={act.isPending}
                    onClose={() => setPending(null)}
                    onConfirmed={() => act.mutate(pending)}
                >
                    <p className={'vh:text-sm'}>
                        {pending.action === 'ban' &&
                            `${pending.name} is added to the banned list and cannot join once the server has read it. Restart the server to apply.`}
                        {pending.action === 'admin' &&
                            `${pending.name} gets admin rights (kick, ban and the in-game admin commands) after the server restarts.`}
                        {pending.action === 'permit' && pending.confirm
                            ? 'The permitted list is empty, so everyone may join now. Once a player is on it, only the players on it can join.'
                            : pending.action === 'permit' && `${pending.name} is added to the permitted list.`}
                    </p>
                </Dialog.Confirm>
            )}
        </TitledGreyBox>
    );
}
