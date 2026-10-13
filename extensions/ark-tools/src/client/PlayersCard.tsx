import { useEffect, useRef, useState, type FormEvent, type ReactNode } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
    Alert,
    Button,
    Dialog,
    Input,
    SocketEvent,
    Spinner,
    Switch,
    TitledGreyBox,
    httpErrorToHuman,
    toast,
    useServerPermission,
    useServerWebsocketEvent,
    type SdkServer,
} from '@pterodactyl/sdk';
import {
    STEAM_ID,
    getLists,
    getOnline,
    keys,
    runPlayerAction,
    setWhitelistMode,
    type Lists,
    type OnlineResponse,
    type PlayerAction,
} from './api';

type Tab = 'online' | 'players' | 'whitelist' | 'bans' | 'admins';

interface Confirm {
    action: Extract<PlayerAction, 'kick' | 'ban'>;
    steamId: string;
    name: string;
}

export function isArk(server: SdkServer): boolean {
    return (server.attributes.egg_tags ?? []).some((tag) => tag.toLowerCase() === 'ark');
}

export default function PlayersSlot({ data: server }: { data: SdkServer }) {
    const canView = useServerPermission('ext.ark-tools.view');
    if (!canView || !isArk(server)) return null;

    return <PlayersCard server={server} />;
}

export function PlayersCard({ server }: { server: SdkServer }) {
    const identifier = server.attributes.identifier;
    const queryClient = useQueryClient();
    const [tab, setTab] = useState<Tab>('online');
    const [confirm, setConfirm] = useState<Confirm | null>(null);

    const can = {
        kick: useServerPermission('ext.ark-tools.kick'),
        ban: useServerPermission('ext.ark-tools.ban'),
        whitelist: useServerPermission('ext.ark-tools.whitelist'),
        admin: useServerPermission('ext.ark-tools.admin'),
        startup: useServerPermission('startup.update'),
    };

    const online = useQuery({ queryKey: keys.online(identifier), queryFn: () => getOnline(identifier), staleTime: 30_000 });
    const lists = useQuery({ queryKey: keys.lists(identifier), queryFn: () => getLists(identifier), staleTime: 30_000 });

    const action = useMutation({
        mutationFn: (input: { action: PlayerAction; steamId: string }) => runPlayerAction(identifier, input.action, input.steamId),
        onSuccess: (result, input) => {
            queryClient.setQueryData(keys.lists(identifier), result);
            if (input.action === 'kick' || input.action === 'ban') {
                queryClient.setQueryData<OnlineResponse>(keys.online(identifier), (current) =>
                    current?.online ? { ...current, online: current.online.filter((player) => player.steam_id !== input.steamId) } : current,
                );
            }
            toast.success(successMessage(input.action, nameFor(input.steamId), result.state));
            setConfirm(null);
        },
        onError: (error) => toast.error(httpErrorToHuman(error)),
    });

    const whitelistMode = useMutation({
        mutationFn: (enabled: boolean) => setWhitelistMode(identifier, enabled),
        onSuccess: (result) => {
            queryClient.setQueryData(keys.lists(identifier), result);
            toast.success(`Whitelist ${result.whitelist_enabled ? 'on' : 'off'}. Restart the server to apply it.`);
        },
        onError: (error) => toast.error(httpErrorToHuman(error)),
    });

    const run = (input: { action: PlayerAction; steamId: string }) => action.mutate(input);

    // ARK's RCON console has no join or leave messages, so the list is refreshed when the
    // server comes up and whenever someone runs ListPlayers by hand.
    const refreshTimer = useRef<number>(undefined);
    useServerWebsocketEvent(SocketEvent.STATUS, (status) => {
        window.clearTimeout(refreshTimer.current);
        if (status === 'running') {
            refreshTimer.current = window.setTimeout(() => queryClient.invalidateQueries({ queryKey: keys.online(identifier) }), 5000);
        } else {
            queryClient.setQueryData<OnlineResponse>(keys.online(identifier), { state: status, online: null });
        }
        queryClient.setQueryData<Lists>(keys.lists(identifier), (current) => (current ? { ...current, state: status } : current));
    });
    useEffect(() => () => window.clearTimeout(refreshTimer.current), []);

    const names = new Map<string, string>();
    for (const player of lists.data?.known ?? []) names.set(player.steam_id, player.name);
    for (const player of online.data?.online ?? []) names.set(player.steam_id, player.name);
    const nameFor = (steamId: string) => names.get(steamId) ?? steamId;

    const state = online.data?.state ?? lists.data?.state;
    const running = state === 'running';
    const onlineIds = new Set((online.data?.online ?? []).map((player) => player.steam_id));
    const sets = {
        whitelist: new Set(lists.data?.whitelist ?? []),
        bans: new Set(lists.data?.bans ?? []),
        admins: new Set(lists.data?.admins ?? []),
    };

    const count = (value: number | undefined) => (value === undefined ? '' : ` (${value})`);
    const tabs: { id: Tab; label: string }[] = [
        { id: 'online', label: `Online${count(online.data?.online?.length)}` },
        { id: 'players', label: `Players${count(lists.data?.known.length)}` },
        { id: 'whitelist', label: `Whitelist${count(lists.data?.whitelist.length)}` },
        { id: 'bans', label: `Bans${count(lists.data?.bans.length)}` },
        { id: 'admins', label: `Admins${count(lists.data?.admins.length)}` },
    ];

    const actions = (steamId: string, name: string, options: { kick?: boolean } = {}) => (
        <PlayerActions
            steamId={steamId}
            can={can}
            pending={action.isPending}
            whitelisted={sets.whitelist.has(steamId)}
            banned={sets.bans.has(steamId)}
            admin={sets.admins.has(steamId)}
            showKick={options.kick === true}
            run={run}
            onConfirm={(kind) => setConfirm({ action: kind, steamId, name })}
        />
    );

    return (
        <TitledGreyBox title={'Players'}>
            <div className={'ark:flex ark:flex-col ark:gap-3'}>
                <div className={'ark:flex ark:flex-wrap ark:items-center ark:gap-2'} role={'tablist'}>
                    {tabs.map((item) => (
                        <Button
                            key={item.id}
                            size={'xsmall'}
                            isSecondary={tab !== item.id}
                            role={'tab'}
                            aria-selected={tab === item.id}
                            onClick={() => setTab(item.id)}
                        >
                            {item.label}
                        </Button>
                    ))}
                    {tab === 'online' && running && (
                        <Button.Text
                            size={'xsmall'}
                            className={'ark:ml-auto'}
                            isLoading={online.isFetching}
                            onClick={() => queryClient.invalidateQueries({ queryKey: keys.online(identifier) })}
                        >
                            Refresh
                        </Button.Text>
                    )}
                </div>

                {state && state !== 'running' && tab !== 'online' && tab !== 'players' && (
                    <p className={'ark:text-xs ark:text-muted-foreground'}>
                        The server is {state}. Changes are written to its files and apply on the next start.
                    </p>
                )}

                {tab === 'online' ? (
                    online.isLoading ? (
                        <Spinner centered size={'small'} />
                    ) : online.error ? (
                        <Alert type={'danger'}>{httpErrorToHuman(online.error)}</Alert>
                    ) : !running ? (
                        <Empty>The server is {state ?? 'offline'}. Players show up here once it is running.</Empty>
                    ) : !online.data?.online ? (
                        <Empty>Could not read the player list from the console. Press Refresh to try again.</Empty>
                    ) : online.data.online.length === 0 ? (
                        <Empty>Nobody is online.</Empty>
                    ) : (
                        <Rows>
                            {online.data.online.map((player) => (
                                <Row key={player.steam_id} name={player.name || player.steam_id} detail={player.steam_id} badge={badges(player.steam_id, sets)}>
                                    {actions(player.steam_id, player.name, { kick: true })}
                                </Row>
                            ))}
                        </Rows>
                    )
                ) : lists.isLoading ? (
                    <Spinner centered size={'small'} />
                ) : lists.error || !lists.data ? (
                    <Alert type={'danger'}>{httpErrorToHuman(lists.error)}</Alert>
                ) : tab === 'players' ? (
                    <KnownTab lists={lists.data} onlineIds={onlineIds} sets={sets} actions={actions} />
                ) : (
                    <IdListTab
                        kind={tab}
                        lists={lists.data}
                        nameFor={nameFor}
                        canEdit={tab === 'bans' ? can.ban : tab === 'admins' ? can.admin : can.whitelist}
                        pending={action.isPending}
                        run={run}
                        onBan={(steamId) => setConfirm({ action: 'ban', steamId, name: nameFor(steamId) })}
                        whitelistSwitch={
                            tab === 'whitelist' && lists.data.whitelist_enabled !== null ? (
                                <Switch
                                    checked={lists.data.whitelist_enabled}
                                    disabled={!can.whitelist || !can.startup || !lists.data.whitelist_editable || whitelistMode.isPending}
                                    onChange={(checked) => whitelistMode.mutate(checked)}
                                    label={'Whitelist enabled'}
                                    description={'Only listed players can join. Adds -exclusivejoin to the launch options; applies after a restart.'}
                                />
                            ) : null
                        }
                    />
                )}
            </div>

            {confirm && (
                <Dialog.Confirm
                    open
                    title={`${confirm.action === 'ban' ? 'Ban' : 'Kick'} ${confirm.name || confirm.steamId}?`}
                    confirm={confirm.action === 'ban' ? 'Ban' : 'Kick'}
                    pending={action.isPending}
                    onClose={() => setConfirm(null)}
                    onConfirmed={() => run({ action: confirm.action, steamId: confirm.steamId })}
                >
                    <p className={'ark:text-sm'}>
                        {confirm.action === 'ban'
                            ? 'They are disconnected and cannot join again until unbanned.'
                            : 'They are disconnected but can join again.'}
                    </p>
                </Dialog.Confirm>
            )}
        </TitledGreyBox>
    );
}

type Sets = { whitelist: Set<string>; bans: Set<string>; admins: Set<string> };

function badges(steamId: string, sets: Sets): string | undefined {
    return (
        [sets.admins.has(steamId) && 'Admin', sets.whitelist.has(steamId) && 'Whitelisted', sets.bans.has(steamId) && 'Banned']
            .filter(Boolean)
            .join(' · ') || undefined
    );
}

interface PlayerActionsProps {
    steamId: string;
    can: { kick: boolean; ban: boolean; whitelist: boolean; admin: boolean };
    pending: boolean;
    whitelisted: boolean;
    banned: boolean;
    admin: boolean;
    showKick: boolean;
    run: (input: { action: PlayerAction; steamId: string }) => void;
    onConfirm: (action: 'kick' | 'ban') => void;
}

function PlayerActions({ steamId, can, pending, whitelisted, banned, admin, showKick, run, onConfirm }: PlayerActionsProps) {
    return (
        <>
            {can.whitelist && (
                <Button.Text
                    size={'xsmall'}
                    disabled={pending}
                    onClick={() => run({ action: whitelisted ? 'whitelist-remove' : 'whitelist-add', steamId })}
                >
                    {whitelisted ? 'Unwhitelist' : 'Whitelist'}
                </Button.Text>
            )}
            {can.admin && (
                <Button.Text size={'xsmall'} disabled={pending} onClick={() => run({ action: admin ? 'admin-remove' : 'admin-add', steamId })}>
                    {admin ? 'Remove admin' : 'Make admin'}
                </Button.Text>
            )}
            {showKick && can.kick && (
                <Button size={'xsmall'} isSecondary disabled={pending} onClick={() => onConfirm('kick')}>
                    Kick
                </Button>
            )}
            {can.ban &&
                (banned ? (
                    <Button size={'xsmall'} isSecondary disabled={pending} onClick={() => run({ action: 'unban', steamId })}>
                        Unban
                    </Button>
                ) : (
                    <Button.Danger size={'xsmall'} isSecondary disabled={pending} onClick={() => onConfirm('ban')}>
                        Ban
                    </Button.Danger>
                ))}
        </>
    );
}

function KnownTab({
    lists,
    onlineIds,
    sets,
    actions,
}: {
    lists: Lists;
    onlineIds: Set<string>;
    sets: Sets;
    actions: (steamId: string, name: string) => ReactNode;
}) {
    const [filter, setFilter] = useState('');
    const needle = filter.trim().toLowerCase();
    const shown = needle
        ? lists.known.filter((player) => player.name.toLowerCase().includes(needle) || player.steam_id.includes(needle))
        : lists.known;

    if (lists.known.length === 0) {
        return <Empty>No players seen yet. Players are remembered here once they show up in the online list.</Empty>;
    }

    return (
        <div className={'ark:flex ark:flex-col ark:gap-3'}>
            {lists.known.length > 8 && (
                <Input value={filter} placeholder={'Search players'} aria-label={'Search players'} onChange={(event) => setFilter(event.target.value)} />
            )}
            {shown.length === 0 ? (
                <Empty>No player matches “{filter}”.</Empty>
            ) : (
                <Rows>
                    {shown.map((player) => (
                        <Row
                            key={player.steam_id}
                            name={player.name}
                            badge={[onlineIds.has(player.steam_id) && 'Online', badges(player.steam_id, sets)].filter(Boolean).join(' · ') || undefined}
                            detail={`${player.steam_id} · last seen ${new Date(player.last_seen).toLocaleDateString()}`}
                        >
                            {actions(player.steam_id, player.name)}
                        </Row>
                    ))}
                </Rows>
            )}
        </div>
    );
}

const LIST_TEXT = {
    whitelist: { add: 'whitelist-add', remove: 'whitelist-remove', empty: 'Nobody is whitelisted.', button: 'Add', removeLabel: 'Remove' },
    bans: { add: 'ban', remove: 'unban', empty: 'Nobody is banned.', button: 'Ban', removeLabel: 'Unban' },
    admins: {
        add: 'admin-add',
        remove: 'admin-remove',
        empty: 'No admins. Admins listed here can use admin commands in game without the admin password (applies after a restart).',
        button: 'Add',
        removeLabel: 'Remove',
    },
} as const;

function IdListTab({
    kind,
    lists,
    nameFor,
    canEdit,
    pending,
    run,
    onBan,
    whitelistSwitch,
}: {
    kind: 'whitelist' | 'bans' | 'admins';
    lists: Lists;
    nameFor: (steamId: string) => string;
    canEdit: boolean;
    pending: boolean;
    run: (input: { action: PlayerAction; steamId: string }) => void;
    onBan: (steamId: string) => void;
    whitelistSwitch: ReactNode;
}) {
    const text = LIST_TEXT[kind];
    const ids = lists[kind];

    return (
        <div className={'ark:flex ark:flex-col ark:gap-3'}>
            {whitelistSwitch}
            {canEdit && (
                <AddForm
                    button={text.button}
                    pending={pending}
                    known={lists.known}
                    onSubmit={(steamId) => (kind === 'bans' ? onBan(steamId) : run({ action: text.add, steamId }))}
                />
            )}
            {ids.length === 0 ? (
                <Empty>{text.empty}</Empty>
            ) : (
                <Rows>
                    {ids.map((steamId) => (
                        <Row key={steamId} name={nameFor(steamId)} detail={nameFor(steamId) === steamId ? undefined : steamId}>
                            {canEdit && (
                                <Button.Text size={'xsmall'} disabled={pending} onClick={() => run({ action: text.remove, steamId })}>
                                    {text.removeLabel}
                                </Button.Text>
                            )}
                        </Row>
                    ))}
                </Rows>
            )}
        </div>
    );
}

/** Takes a SteamID64, a Steam profile link with one in it, or the name of a player seen before. */
function AddForm({
    button,
    pending,
    known,
    onSubmit,
}: {
    button: string;
    pending: boolean;
    known: Lists['known'];
    onSubmit: (steamId: string) => void;
}) {
    const [value, setValue] = useState('');
    const [error, setError] = useState<string | null>(null);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const steamId = resolveSteamId(value, known);
        if (!steamId) {
            setError('Enter a SteamID64 (17 digits, starts with 7656119), a profile link with one, or the name of a player seen on this server.');
            return;
        }
        setError(null);
        onSubmit(steamId);
        setValue('');
    };

    return (
        <form onSubmit={submit} className={'ark:flex ark:flex-col ark:gap-1'}>
            <div className={'ark:flex ark:gap-2'}>
                <Input
                    value={value}
                    placeholder={'SteamID64 or player name'}
                    aria-label={'SteamID64 or player name'}
                    onChange={(event) => setValue(event.target.value)}
                />
                <Button size={'small'} type={'submit'} disabled={pending || value.trim() === ''}>
                    {button}
                </Button>
            </div>
            {error && <p className={'ark:text-xs ark:text-destructive'}>{error}</p>}
        </form>
    );
}

export function resolveSteamId(input: string, known: Lists['known']): string | null {
    const text = input.trim();
    const id = /7656119\d{10}/.exec(text)?.[0];
    if (id && STEAM_ID.test(id)) return id;

    const lower = text.toLowerCase();
    const matches = known.filter((player) => player.name.toLowerCase() === lower);
    return matches.length === 1 ? matches[0].steam_id : null;
}

function Rows({ children }: { children: ReactNode }) {
    return <ul className={'ark:flex ark:max-h-80 ark:flex-col ark:divide-y ark:divide-border ark:overflow-y-auto'}>{children}</ul>;
}

function Row({ name, badge, detail, children }: { name: string; badge?: string; detail?: string; children?: ReactNode }) {
    return (
        <li className={'ark:flex ark:items-center ark:gap-3 ark:py-2'}>
            <div className={'ark:min-w-0 ark:flex-1'}>
                <div className={'ark:flex ark:items-center ark:gap-2'}>
                    <span className={'ark:truncate ark:text-sm ark:font-medium ark:text-foreground'}>{name}</span>
                    {badge && (
                        <span className={'ark:rounded ark:bg-muted ark:px-1.5 ark:py-0.5 ark:text-[11px] ark:text-muted-foreground'}>{badge}</span>
                    )}
                </div>
                {detail && <p className={'ark:truncate ark:font-mono ark:text-xs ark:text-muted-foreground'}>{detail}</p>}
            </div>
            <div className={'ark:flex ark:shrink-0 ark:flex-wrap ark:items-center ark:justify-end ark:gap-1.5'}>{children}</div>
        </li>
    );
}

export function Empty({ children }: { children: ReactNode }) {
    return <p className={'ark:py-4 ark:text-center ark:text-sm ark:text-muted-foreground'}>{children}</p>;
}

function successMessage(action: PlayerAction, name: string, state: string): string {
    const later = state === 'running' ? '' : ' It applies when the server starts.';
    const messages: Record<PlayerAction, string> = {
        kick: `Kicked ${name}.`,
        ban: `Banned ${name}.`,
        unban: `Unbanned ${name}.`,
        'whitelist-add': `Added ${name} to the whitelist.`,
        'whitelist-remove': `Removed ${name} from the whitelist.`,
        'admin-add': `${name} is now an admin. Applies after a restart.`,
        'admin-remove': `${name} is no longer an admin. Applies after a restart.`,
    };

    return messages[action] + (action === 'kick' || action.startsWith('admin') ? '' : later);
}
