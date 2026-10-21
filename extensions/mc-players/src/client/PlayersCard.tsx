import { createContext, useContext, useEffect, useRef, useState, type FormEvent, type ReactNode } from 'react';
import { useMutation, useQuery, useQueryClient, type UseQueryResult } from '@tanstack/react-query';
import {
    Alert,
    Button,
    Input,
    SocketEvent,
    Spinner,
    Switch,
    TitledGreyBox,
    httpErrorToHuman,
    toast,
    useServerPermission,
    usePanelNavigate,
    useServerWebsocketEvent,
    type SdkServer,
} from '@pterodactyl/sdk';
import { getLists, getOnline, keys, runAction, type Action, type Lists, type OnlineResponse } from './api';
import { isValidName, parseConsoleLine, replyNotice } from './console';
import { ReasonDialog, banDetail, type Prompt } from './shared';
import { linkBehaviour } from './config';
import { headUrl } from './skins';
import PlayerPopup from './PlayerPopup';

/** Opens a player's profile, as a popup or the full page depending on the extension setting. */
const OpenPlayer = createContext<((name: string) => void) | null>(null);

type Tab = 'online' | 'offline' | 'whitelist' | 'bans' | 'ops';

/** Only Java Edition servers get the card; Bedrock eggs carry the minecraft tag too. */
export default function PlayersSlot({ data: server }: { data: SdkServer }) {
    const tags = (server.attributes.egg_tags ?? []).map((tag) => tag.toLowerCase());
    const features = (server.attributes.egg_features ?? []).map((feature) => feature.toLowerCase());
    const canView = useServerPermission('ext.mc-players.view');
    // Every Java egg has the "eula" feature, so untagged eggs on fresh installs still count.
    const java = tags.includes('minecraft') || features.includes('eula');

    if (!canView || !java || tags.includes('bedrock') || tags.includes('proxy')) {
        return null;
    }

    return <PlayersCard server={server} />;
}

export function PlayersCard({ server }: { server: SdkServer }) {
    const identifier = server.attributes.identifier;
    const queryClient = useQueryClient();
    const [tab, setTab] = useState<Tab>('online');
    const [prompt, setPrompt] = useState<Prompt | null>(null);
    const [profile, setProfile] = useState<string | null>(null);
    const navigate = usePanelNavigate();
    const openPlayer = (name: string) => {
        if (linkBehaviour() === 'page') {
            void navigate({ extension: 'mc-players', screen: 'player', params: { id: identifier, player: name } });
        } else {
            setProfile(name);
        }
    };

    const can = {
        kick: useServerPermission('ext.mc-players.kick'),
        ban: useServerPermission('ext.mc-players.ban'),
        whitelist: useServerPermission('ext.mc-players.whitelist'),
        op: useServerPermission('ext.mc-players.op'),
    };

    const online = useQuery({
        queryKey: keys.online(identifier),
        queryFn: () => getOnline(identifier),
        staleTime: 30_000,
        refetchOnWindowFocus: false,
    });
    const lists = useQuery({
        queryKey: keys.lists(identifier),
        queryFn: () => getLists(identifier),
        staleTime: 30_000,
        refetchOnWindowFocus: false,
    });

    const action = useMutation({
        mutationFn: (input: { action: Action; target?: string; reason?: string }) =>
            runAction(identifier, input.action, input.target, input.reason),
        onSuccess: (result, input) => {
            queryClient.setQueryData(keys.lists(identifier), result);
            if (input.action === 'kick' || input.action === 'ban') {
                updateOnline((players) => players.filter((name) => name.toLowerCase() !== input.target?.toLowerCase()));
            }
            const notice = replyNotice(result.reply, result.state, successMessage(input.action, input.target, result.state));
            toast[notice.type](notice.message);
            setPrompt(null);
        },
        onError: (error) => toast.error(httpErrorToHuman(error)),
    });

    const run = (input: { action: Action; target?: string; reason?: string }) => action.mutate(input);

    function updateOnline(change: (players: string[]) => string[]) {
        queryClient.setQueryData<OnlineResponse>(keys.online(identifier), (current) => {
            if (!current?.online) return current;
            const players = change(current.online.players);
            return { ...current, online: { ...current.online, players, count: players.length } };
        });
    }

    // Keep the card live from the console instead of polling the server.
    const listsRefresh = useRef<number>(undefined);
    useServerWebsocketEvent(SocketEvent.CONSOLE_OUTPUT, (line) => {
        const change = parseConsoleLine(line);
        if (!change) return;

        if (change.type === 'join') {
            updateOnline((players) =>
                players.some((name) => name.toLowerCase() === change.name.toLowerCase())
                    ? players
                    : [...players, change.name].sort((a, b) => a.localeCompare(b)),
            );
        } else if (change.type === 'leave') {
            updateOnline((players) => players.filter((name) => name.toLowerCase() !== change.name.toLowerCase()));
        } else {
            window.clearTimeout(listsRefresh.current);
            listsRefresh.current = window.setTimeout(() => queryClient.invalidateQueries({ queryKey: keys.lists(identifier) }), 800);
        }
    });

    const onlineRefresh = useRef<number>(undefined);
    useServerWebsocketEvent(SocketEvent.STATUS, (status) => {
        window.clearTimeout(onlineRefresh.current);
        if (status === 'running') {
            // Give the server a moment after it reports running before asking who is on.
            onlineRefresh.current = window.setTimeout(() => queryClient.invalidateQueries({ queryKey: keys.online(identifier) }), 3000);
        } else {
            queryClient.setQueryData<OnlineResponse>(keys.online(identifier), { state: status, online: null });
        }
        queryClient.setQueryData<Lists>(keys.lists(identifier), (current) => (current ? { ...current, state: status } : current));
    });

    useEffect(
        () => () => {
            window.clearTimeout(listsRefresh.current);
            window.clearTimeout(onlineRefresh.current);
        },
        [],
    );

    const state = online.data?.state ?? lists.data?.state;
    const running = state === 'running';
    // While starting or stopping the backend refuses changes, so the buttons are off too.
    const busy = action.isPending || state === 'starting' || state === 'stopping';
    const opNames = new Set((lists.data?.ops ?? []).map((op) => op.name.toLowerCase()));
    const uuids = new Map((lists.data?.known ?? []).map((player) => [player.name.toLowerCase(), player.uuid] as const));
    const count = online.data?.online;
    const onlineNames = new Set((online.data?.online?.players ?? []).map((name) => name.toLowerCase()));
    const offlinePlayers = (lists.data?.known ?? []).filter((player) => !onlineNames.has(player.name.toLowerCase()));
    const offlineCount = lists.data ? offlinePlayers.length : null;

    const tabs: { id: Tab; label: string }[] = [
        { id: 'online', label: count ? `Online (${count.count}/${count.max})` : 'Online' },
        { id: 'offline', label: `Offline${offlineCount !== null ? ` (${offlineCount})` : ''}` },
        { id: 'whitelist', label: `Whitelist${lists.data ? ` (${lists.data.whitelist.length})` : ''}` },
        { id: 'bans', label: `Bans${lists.data ? ` (${lists.data.bans.length + lists.data.ip_bans.length})` : ''}` },
        { id: 'ops', label: `Operators${lists.data ? ` (${lists.data.ops.length})` : ''}` },
    ];

    return (
        <OpenPlayer.Provider value={openPlayer}>
            <TitledGreyBox title={'Players'}>
                <div className={'mcp:flex mcp:flex-col mcp:gap-3'}>
                    <div className={'mcp:flex mcp:flex-wrap mcp:items-center mcp:gap-2'} role={'tablist'}>
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
                                className={'mcp:ml-auto'}
                                isLoading={online.isFetching}
                                onClick={() => queryClient.invalidateQueries({ queryKey: keys.online(identifier) })}
                            >
                                Refresh
                            </Button.Text>
                        )}
                    </div>

                    {state === 'offline' && tab !== 'online' && (
                        <p className={'mcp:text-xs mcp:text-muted-foreground'}>
                            The server is stopped. Changes are written to its files and apply on the next start.
                        </p>
                    )}
                    {(state === 'starting' || state === 'stopping') && (
                        <p className={'mcp:text-xs mcp:text-muted-foreground'}>
                            The server is {state}. Changes are possible once it is running or stopped.
                        </p>
                    )}
                    {tab !== 'online' && lists.data && Object.keys(lists.data.problems ?? {}).length > 0 && (
                        <Alert type={'warning'}>
                            {Object.values(lists.data.problems).map((problem) => (
                                <p key={problem}>{problem}</p>
                            ))}
                        </Alert>
                    )}

                    {tab === 'online' && (
                        <OnlineTab
                            query={online}
                            running={running}
                            state={state}
                            opNames={opNames}
                            uuids={uuids}
                            can={can}
                            pending={busy}
                            onKick={(name) => setPrompt({ action: 'kick', name })}
                            onBan={(name) => setPrompt({ action: 'ban', name })}
                            onOp={(name, op) => run({ action: op ? 'deop' : 'op', target: name })}
                            whitelisted={new Set((lists.data?.whitelist ?? []).map((player) => player.name.toLowerCase()))}
                            onWhitelist={(name, listed) => run({ action: listed ? 'whitelist-remove' : 'whitelist-add', target: name })}
                        />
                    )}

                    {tab !== 'online' &&
                        (lists.isLoading ? (
                            <Spinner centered size={'small'} />
                        ) : lists.error || !lists.data ? (
                            <Alert type={'danger'}>{httpErrorToHuman(lists.error)}</Alert>
                        ) : tab === 'offline' ? (
                            <OfflineTab
                                players={offlinePlayers}
                                lists={lists.data}
                                can={can}
                                pending={busy}
                                run={run}
                                onBan={(name) => setPrompt({ action: 'ban', name })}
                            />
                        ) : tab === 'whitelist' ? (
                            <WhitelistTab lists={lists.data} canEdit={can.whitelist} pending={busy} run={run} />
                        ) : tab === 'bans' ? (
                            <BansTab lists={lists.data} canEdit={can.ban} pending={busy} run={run} />
                        ) : (
                            <OpsTab lists={lists.data} canEdit={can.op} pending={busy} run={run} />
                        ))}
                </div>

                {prompt && (
                    <ReasonDialog
                        prompt={prompt}
                        pending={busy}
                        onClose={() => setPrompt(null)}
                        onConfirm={(reason) => run({ action: prompt.action, target: prompt.name, reason })}
                    />
                )}
                {profile && <PlayerPopup server={identifier} name={profile} onClose={() => setProfile(null)} />}
            </TitledGreyBox>
        </OpenPlayer.Provider>
    );
}

interface OnlineTabProps {
    query: UseQueryResult<OnlineResponse>;
    /** Lowercase name to UUID, from the server's usercache, for skin lookups. */
    uuids: Map<string, string>;
    running: boolean;
    state: string | undefined;
    opNames: Set<string>;
    can: { kick: boolean; ban: boolean; op: boolean; whitelist: boolean };
    pending: boolean;
    onKick: (name: string) => void;
    onBan: (name: string) => void;
    onOp: (name: string, isOp: boolean) => void;
    whitelisted: Set<string>;
    onWhitelist: (name: string, listed: boolean) => void;
}

function OnlineTab({ query, running, state, opNames, uuids, can, pending, onKick, onBan, onOp, whitelisted, onWhitelist }: OnlineTabProps) {
    if (query.isLoading) return <Spinner centered size={'small'} />;
    if (query.error) return <Alert type={'danger'}>{httpErrorToHuman(query.error)}</Alert>;
    if (!running) return <Empty>The server is {state ?? 'offline'}. Players show up here once it is running.</Empty>;
    if (!query.data?.online) {
        return <Empty>Could not read the player list from the console. Press Refresh to try again.</Empty>;
    }
    if (query.data.online.players.length === 0) return <Empty>Nobody is online.</Empty>;

    return (
        <Rows>
            {query.data.online.players.map((name) => {
                const isOp = opNames.has(name.toLowerCase());
                return (
                    <Row key={name} name={name} uuid={uuids.get(name.toLowerCase())} badge={isOp ? 'Operator' : undefined}>
                        {can.whitelist && (
                            <Button.Text
                                size={'xsmall'}
                                disabled={pending}
                                onClick={() => onWhitelist(name, whitelisted.has(name.toLowerCase()))}
                            >
                                {whitelisted.has(name.toLowerCase()) ? 'Unwhitelist' : 'Whitelist'}
                            </Button.Text>
                        )}
                        {can.op && (
                            <Button.Text size={'xsmall'} disabled={pending} onClick={() => onOp(name, isOp)}>
                                {isOp ? 'Deop' : 'Op'}
                            </Button.Text>
                        )}
                        {can.kick && (
                            <Button size={'xsmall'} isSecondary disabled={pending} onClick={() => onKick(name)}>
                                Kick
                            </Button>
                        )}
                        {can.ban && (
                            <Button.Danger size={'xsmall'} isSecondary disabled={pending} onClick={() => onBan(name)}>
                                Ban
                            </Button.Danger>
                        )}
                    </Row>
                );
            })}
        </Rows>
    );
}

interface ListTabProps {
    lists: Lists;
    canEdit: boolean;
    pending: boolean;
    run: (input: { action: Action; target?: string; reason?: string }) => void;
}

interface OfflineTabProps {
    players: Lists['known'];
    lists: Lists;
    can: { ban: boolean; op: boolean; whitelist: boolean };
    pending: boolean;
    run: (input: { action: Action; target?: string; reason?: string }) => void;
    onBan: (name: string) => void;
}

/** Everyone who has joined before and is not online now, from the server's usercache.json. */
function OfflineTab({ players, lists, can, pending, run, onBan }: OfflineTabProps) {
    const [filter, setFilter] = useState('');
    const lower = (list: { name: string }[]) => new Set(list.map((player) => player.name.toLowerCase()));
    const ops = lower(lists.ops);
    const whitelisted = lower(lists.whitelist);
    const banned = lower(lists.bans);
    const needle = filter.trim().toLowerCase();
    const shown = needle ? players.filter((player) => player.name.toLowerCase().includes(needle)) : players;

    if (players.length === 0) {
        return <Empty>No other players have joined this server yet.</Empty>;
    }

    return (
        <div className={'mcp:flex mcp:flex-col mcp:gap-3'}>
            {players.length > 8 && (
                <Input
                    value={filter}
                    placeholder={'Search players'}
                    aria-label={'Search players'}
                    onChange={(event) => setFilter(event.target.value)}
                />
            )}
            {shown.length === 0 ? (
                <Empty>No player matches “{filter}”.</Empty>
            ) : (
                <Rows>
                    {shown.map((player) => {
                        const key = player.name.toLowerCase();
                        const badges = [
                            ops.has(key) && 'Operator',
                            whitelisted.has(key) && 'Whitelisted',
                            banned.has(key) && 'Banned',
                        ].filter((badge): badge is string => Boolean(badge));

                        return (
                            <Row
                                key={player.uuid || player.name}
                                name={player.name}
                                uuid={player.uuid}
                                badge={badges.join(' · ') || undefined}
                                detail={player.last_seen ? `Last seen ${new Date(player.last_seen).toLocaleDateString()}` : undefined}
                            >
                                {can.whitelist && (
                                    <Button.Text
                                        size={'xsmall'}
                                        disabled={pending}
                                        onClick={() =>
                                            run({
                                                action: whitelisted.has(key) ? 'whitelist-remove' : 'whitelist-add',
                                                target: player.name,
                                            })
                                        }
                                    >
                                        {whitelisted.has(key) ? 'Unwhitelist' : 'Whitelist'}
                                    </Button.Text>
                                )}
                                {can.op && (
                                    <Button.Text
                                        size={'xsmall'}
                                        disabled={pending}
                                        onClick={() => run({ action: ops.has(key) ? 'deop' : 'op', target: player.name })}
                                    >
                                        {ops.has(key) ? 'Deop' : 'Op'}
                                    </Button.Text>
                                )}
                                {can.ban &&
                                    (banned.has(key) ? (
                                        <Button
                                            size={'xsmall'}
                                            isSecondary
                                            disabled={pending}
                                            onClick={() => run({ action: 'pardon', target: player.name })}
                                        >
                                            Unban
                                        </Button>
                                    ) : (
                                        <Button.Danger size={'xsmall'} isSecondary disabled={pending} onClick={() => onBan(player.name)}>
                                            Ban
                                        </Button.Danger>
                                    ))}
                            </Row>
                        );
                    })}
                </Rows>
            )}
        </div>
    );
}

function WhitelistTab({ lists, canEdit, pending, run }: ListTabProps) {
    return (
        <div className={'mcp:flex mcp:flex-col mcp:gap-3'}>
            {lists.whitelist_enabled !== null && (
                <Switch
                    checked={lists.whitelist_enabled}
                    disabled={!canEdit || pending}
                    onChange={(checked) => run({ action: checked ? 'whitelist-on' : 'whitelist-off' })}
                    label={'Whitelist enabled'}
                    description={'Only players on the list can join.'}
                />
            )}
            {canEdit && (
                <AddForm
                    placeholder={'Username'}
                    button={'Add'}
                    pending={pending}
                    onSubmit={(name) => run({ action: 'whitelist-add', target: name })}
                />
            )}
            {lists.whitelist.length === 0 ? (
                <Empty>Nobody is whitelisted.</Empty>
            ) : (
                <Rows>
                    {sortByName(lists.whitelist).map((player) => (
                        <Row key={player.uuid || player.name} name={player.name} uuid={player.uuid}>
                            {canEdit && (
                                <Button.Text
                                    size={'xsmall'}
                                    disabled={pending}
                                    onClick={() => run({ action: 'whitelist-remove', target: player.name })}
                                >
                                    Remove
                                </Button.Text>
                            )}
                        </Row>
                    ))}
                </Rows>
            )}
        </div>
    );
}

function BansTab({ lists, canEdit, pending, run }: ListTabProps) {
    const [reason, setReason] = useState('');

    return (
        <div className={'mcp:flex mcp:flex-col mcp:gap-3'}>
            {canEdit && (
                <AddForm
                    placeholder={'Username or IP address'}
                    button={'Ban'}
                    pending={pending}
                    danger
                    allowIp
                    extra={
                        <Input
                            value={reason}
                            placeholder={'Reason (optional)'}
                            aria-label={'Ban reason'}
                            maxLength={120}
                            onChange={(event) => setReason(event.target.value)}
                        />
                    }
                    onSubmit={(target) => {
                        const isIp = !isValidName(target);
                        run({ action: isIp ? 'ban-ip' : 'ban', target, reason });
                        setReason('');
                    }}
                />
            )}
            {lists.bans.length === 0 && lists.ip_bans.length === 0 ? (
                <Empty>Nobody is banned.</Empty>
            ) : (
                <Rows>
                    {sortByName(lists.bans).map((ban) => (
                        <Row key={ban.uuid || ban.name} name={ban.name} uuid={ban.uuid} detail={banDetail(ban)}>
                            {canEdit && (
                                <Button.Text size={'xsmall'} disabled={pending} onClick={() => run({ action: 'pardon', target: ban.name })}>
                                    Unban
                                </Button.Text>
                            )}
                        </Row>
                    ))}
                    {lists.ip_bans.map((ban) => (
                        <Row key={ban.ip} name={ban.ip} avatar={false} badge={'IP'} detail={banDetail(ban)}>
                            {canEdit && (
                                <Button.Text
                                    size={'xsmall'}
                                    disabled={pending}
                                    onClick={() => run({ action: 'pardon-ip', target: ban.ip })}
                                >
                                    Unban
                                </Button.Text>
                            )}
                        </Row>
                    ))}
                </Rows>
            )}
        </div>
    );
}

function OpsTab({ lists, canEdit, pending, run }: ListTabProps) {
    return (
        <div className={'mcp:flex mcp:flex-col mcp:gap-3'}>
            {canEdit && (
                <AddForm
                    placeholder={'Username'}
                    button={'Op'}
                    pending={pending}
                    onSubmit={(name) => run({ action: 'op', target: name })}
                />
            )}
            {lists.ops.length === 0 ? (
                <Empty>There are no operators.</Empty>
            ) : (
                <Rows>
                    {sortByName(lists.ops).map((op) => (
                        <Row key={op.uuid || op.name} name={op.name} uuid={op.uuid} badge={`Level ${op.level}`}>
                            {canEdit && (
                                <Button.Text size={'xsmall'} disabled={pending} onClick={() => run({ action: 'deop', target: op.name })}>
                                    Deop
                                </Button.Text>
                            )}
                        </Row>
                    ))}
                </Rows>
            )}
        </div>
    );
}

interface AddFormProps {
    placeholder: string;
    button: string;
    pending: boolean;
    danger?: boolean;
    allowIp?: boolean;
    extra?: ReactNode;
    onSubmit: (value: string) => void;
}

function AddForm({ placeholder, button, pending, danger, allowIp, extra, onSubmit }: AddFormProps) {
    const [value, setValue] = useState('');
    const trimmed = value.trim();
    const valid = isValidName(trimmed) || (allowIp === true && /^[0-9a-f:.]+$/i.test(trimmed) && /[.:]/.test(trimmed));

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (!valid) return;
        onSubmit(trimmed);
        setValue('');
    };

    const ActionButton = danger ? Button.Danger : Button;

    return (
        <form onSubmit={submit} className={'mcp:flex mcp:flex-wrap mcp:gap-2'}>
            <div className={'mcp:min-w-40 mcp:flex-1'}>
                <Input
                    value={value}
                    placeholder={placeholder}
                    aria-label={placeholder}
                    maxLength={64}
                    onChange={(event) => setValue(event.target.value)}
                />
            </div>
            {extra && <div className={'mcp:min-w-40 mcp:flex-1'}>{extra}</div>}
            <ActionButton type={'submit'} size={'small'} disabled={!valid || pending}>
                {button}
            </ActionButton>
        </form>
    );
}

function Rows({ children }: { children: ReactNode }) {
    return <ul className={'mcp:flex mcp:max-h-80 mcp:flex-col mcp:divide-y mcp:divide-border mcp:overflow-y-auto'}>{children}</ul>;
}

function Row({
    name,
    uuid,
    badge,
    detail,
    avatar = true,
    children,
}: {
    name: string;
    uuid?: string | null;
    badge?: string;
    detail?: string;
    avatar?: boolean;
    children?: ReactNode;
}) {
    const open = useContext(OpenPlayer);
    const head = avatar ? headUrl(name, uuid, 24) : null;
    const clickable = avatar && open !== null;

    return (
        <li className={'mcp:flex mcp:items-center mcp:gap-3 mcp:py-2'}>
            {avatar && head ? (
                <img
                    src={head}
                    alt={''}
                    width={24}
                    height={24}
                    loading={'lazy'}
                    className={'mcp:size-6 mcp:shrink-0 mcp:rounded-sm mcp:bg-muted'}
                />
            ) : (
                <span aria-hidden className={'mcp:size-6 mcp:shrink-0'} />
            )}
            <div className={'mcp:min-w-0 mcp:flex-1'}>
                <div className={'mcp:flex mcp:items-center mcp:gap-2'}>
                    {clickable ? (
                        <button
                            type={'button'}
                            className={'mcp:truncate mcp:text-left mcp:text-sm mcp:font-medium mcp:text-foreground mcp:hover:underline'}
                            title={`Show ${name}`}
                            onClick={() => open(name)}
                        >
                            {name}
                        </button>
                    ) : (
                        <span className={'mcp:truncate mcp:text-sm mcp:font-medium mcp:text-foreground'}>{name}</span>
                    )}
                    {badge && (
                        <span className={'mcp:rounded mcp:bg-muted mcp:px-1.5 mcp:py-0.5 mcp:text-[11px] mcp:text-muted-foreground'}>
                            {badge}
                        </span>
                    )}
                </div>
                {detail && <p className={'mcp:truncate mcp:text-xs mcp:text-muted-foreground'}>{detail}</p>}
            </div>
            <div className={'mcp:flex mcp:shrink-0 mcp:items-center mcp:gap-1.5'}>{children}</div>
        </li>
    );
}

function Empty({ children }: { children: ReactNode }) {
    return <p className={'mcp:py-4 mcp:text-center mcp:text-sm mcp:text-muted-foreground'}>{children}</p>;
}

function sortByName<T extends { name: string }>(rows: T[]): T[] {
    return [...rows].sort((a, b) => a.name.localeCompare(b.name, undefined, { sensitivity: 'base' }));
}

function successMessage(action: Action, target: string | undefined, state: string): string {
    const queued = state === 'running' ? '' : ' It applies when the server starts.';
    const messages: Record<Action, string> = {
        kick: `Kicked ${target}.`,
        ban: `Banned ${target}.`,
        pardon: `Unbanned ${target}.`,
        'ban-ip': `Banned ${target}.`,
        'pardon-ip': `Unbanned ${target}.`,
        'whitelist-add': `Added ${target} to the whitelist.`,
        'whitelist-remove': `Removed ${target} from the whitelist.`,
        'whitelist-on': 'Whitelist turned on.',
        'whitelist-off': 'Whitelist turned off.',
        op: `${target} is now an operator.`,
        deop: `${target} is no longer an operator.`,
    };

    return messages[action] + (action === 'kick' ? '' : queued);
}
