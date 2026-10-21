import { useEffect, useMemo, useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
    Alert,
    Dialog,
    Select,
    SocketEvent,
    Spinner,
    httpErrorToHuman,
    toast,
    useCurrentServerRequired,
    usePanelNavigate,
    useServerWebsocketEvent,
} from '@pterodactyl/sdk';
import { Copy } from 'lucide-react';
import { Actions } from './Actions';
import { Details } from './Details';
import { EnderGrid, InventoryGrid, ItemDetail, OtherSlots, findItem, type Selection } from './Items';
import { editPlayer, getPlayer, getPlayers, keys, type EditRequest, type EditResult, type KnownPlayer, type PlayerView } from './api';
import { slotName } from './format';

interface Pending {
    request: EditRequest;
    title: string;
    body: string;
    confirm: string;
}

/** A player's saved data. Edits are offered only when the server is stopped. */
export default function PlayerDataView({ player }: { player: string }) {
    const server = useCurrentServerRequired();
    const identifier = server.attributes.identifier;
    const queryClient = useQueryClient();
    const [selection, setSelection] = useState<Selection | null>(null);
    const [pending, setPending] = useState<Pending | null>(null);

    const view = useQuery({ queryKey: keys.player(identifier, player), queryFn: () => getPlayer(identifier, player), retry: false });
    const data = view.data;

    const refresh = useRef<number>(undefined);
    useServerWebsocketEvent(SocketEvent.STATUS, (status) => {
        queryClient.setQueryData<PlayerView>(keys.player(identifier, player), (current) =>
            current ? { ...current, state: status, can_edit: current.can_manage && status === 'offline' } : current,
        );
        // The server saves its players as it stops: read the file again once it has.
        window.clearTimeout(refresh.current);
        refresh.current = window.setTimeout(() => void queryClient.invalidateQueries({ queryKey: keys.player(identifier, player) }), 2000);
    });
    useEffect(() => () => window.clearTimeout(refresh.current), []);

    const edit = useMutation({
        mutationFn: (input: { request: EditRequest; done: string }) => editPlayer(identifier, player, data?.hash ?? '', input.request),
        onSuccess: (result: EditResult, input) => {
            queryClient.setQueryData<PlayerView>(keys.player(identifier, player), result);
            setPending(null);
            if (!result.changed) {
                toast.success('Nothing to change.');
                return;
            }
            toast.success(result.backup ? `${input.done} The old file was kept as ${result.backup}.` : input.done);
        },
        onError: (error) => {
            toast.error(httpErrorToHuman(error));
            setPending(null);
            // A changed file or a started server: show what is there now.
            void queryClient.invalidateQueries({ queryKey: keys.player(identifier, player) });
        },
    });

    const busy = edit.isPending;
    const selected = useMemo(() => (data ? findItem(data.data, selection) : null), [data, selection]);

    // A selected slot that is empty after an edit is dropped.
    useEffect(() => {
        if (selection && data && !findItem(data.data, selection)) setSelection(null);
    }, [data, selection]);

    if (view.isLoading) return <Spinner centered />;
    if (view.error || !data) return <Alert type={'danger'}>{httpErrorToHuman(view.error)}</Alert>;

    const run = (request: EditRequest, done: string) => edit.mutate({ request, done });
    const name = data.player.name ?? data.player.uuid;

    return (
        <div className={'mcpd:flex mcpd:flex-col mcpd:gap-4'}>
            <Header view={data} server={identifier} />

            {data.read_only && <Alert type={data.can_manage ? 'warning' : 'info'}>{data.read_only}</Alert>}

            <div className={'mcpd:grid mcpd:gap-4 mcpd:xl:grid-cols-3'}>
                <div className={'mcpd:flex mcpd:flex-col mcpd:gap-4 mcpd:xl:col-span-2'}>
                    <InventoryGrid data={data.data} selection={selection} onSelect={setSelection} />
                    <EnderGrid data={data.data} selection={selection} onSelect={setSelection} />
                    <OtherSlots data={data.data} selection={selection} onSelect={setSelection} />
                </div>
                <div className={'mcpd:flex mcpd:flex-col mcpd:gap-4'}>
                    <ItemDetail
                        item={selected}
                        selection={selection}
                        canEdit={data.can_edit}
                        busy={busy}
                        onRemove={(amount) => {
                            if (!selection || !selected) return;
                            const request: EditRequest = {
                                action: 'remove-item',
                                area: selection.area,
                                slot: selection.slot,
                                ...(amount === null ? {} : { amount }),
                            };
                            setPending({
                                request,
                                title: amount === null ? 'Remove this item?' : 'Remove 1 item?',
                                body: `${amount === null ? selected.count : amount} × ${selected.custom_name ?? selected.name} is taken out of ${slotName(selection.area, selection.slot).toLowerCase()} for ${name}.`,
                                confirm: 'Remove',
                            });
                        }}
                    />
                    <Details data={data.data} saved={data.saved} />
                    {data.can_edit && (
                        <Actions
                            key={data.hash}
                            data={data.data}
                            busy={busy}
                            onRun={run}
                            onConfirm={(request, title, body, confirm) => setPending({ request, title, body, confirm })}
                        />
                    )}
                    {data.backups.length > 0 && (
                        <p className={'mcpd:text-xs mcpd:text-muted-foreground'}>
                            Backups in {data.world}/playerdata: {data.backups.join(', ')}
                        </p>
                    )}
                </div>
            </div>

            {pending && (
                <Dialog.Confirm
                    open
                    title={pending.title}
                    confirm={pending.confirm}
                    pending={busy}
                    onClose={() => setPending(null)}
                    onConfirmed={() => run(pending.request, 'Done.')}
                >
                    <p className={'mcpd:text-sm'}>{pending.body}</p>
                    <p className={'mcpd:mt-2 mcpd:text-xs mcpd:text-muted-foreground'}>The old player file is copied aside first.</p>
                </Dialog.Confirm>
            )}
        </div>
    );
}

function Header({ view, server }: { view: PlayerView; server: string }) {
    const navigate = usePanelNavigate();
    const players = useQuery({ queryKey: keys.players(server), queryFn: () => getPlayers(server), staleTime: 60_000 });
    const current = view.player.name ?? view.player.uuid;

    const options = useMemo(() => {
        const list: KnownPlayer[] = players.data ?? [];
        const entries = list.map((entry) => ({ value: entry.name ?? entry.uuid, label: entry.name ?? entry.uuid }));
        return entries.some((entry) => entry.value === current) ? entries : [{ value: current, label: current }, ...entries];
    }, [players.data, current]);

    return (
        <div className={'mcpd:flex mcpd:flex-col mcpd:gap-3 mcpd:sm:flex-row mcpd:sm:items-start mcpd:sm:justify-between'}>
            <div className={'mcpd:flex mcpd:min-w-0 mcpd:flex-col mcpd:gap-1'}>
                <h2 className={'mcpd:truncate mcpd:text-xl mcpd:font-semibold mcpd:text-foreground'}>{current}</h2>
                <button
                    type={'button'}
                    title={'Copy UUID'}
                    className={
                        'mcpd:flex mcpd:w-fit mcpd:items-center mcpd:gap-1 mcpd:font-mono mcpd:text-xs mcpd:text-muted-foreground mcpd:hover:text-foreground'
                    }
                    onClick={() => {
                        navigator.clipboard?.writeText(view.player.uuid).then(
                            () => toast.success('UUID copied.'),
                            () => toast.error('Could not copy.'),
                        );
                    }}
                >
                    {view.player.uuid}
                    <Copy className={'mcpd:size-3'} aria-hidden />
                </button>
                <p className={'mcpd:text-xs mcpd:text-muted-foreground'}>
                    Server {view.state === 'offline' ? 'stopped' : view.state}
                    {view.state === 'offline' ? '' : ' · showing the last saved copy'}
                </p>
            </div>
            {options.length > 1 && (
                <div className={'mcpd:w-full mcpd:sm:w-64'}>
                    <Select
                        options={options}
                        value={current}
                        searchPlaceholder={'Search players'}
                        emptyMessage={'No players found.'}
                        onChange={(value) => {
                            if (String(value) !== current) {
                                void navigate({
                                    extension: 'mc-playerdata',
                                    screen: 'player-data',
                                    params: { id: server, player: String(value) },
                                });
                            }
                        }}
                    />
                </div>
            )}
        </div>
    );
}
