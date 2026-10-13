import { useMemo, useState, type ReactNode } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Button, Checkbox, Spinner, httpErrorToHuman, toast, useServerPermission } from '@pterodactyl/sdk';
import { Copy, ExternalLink } from 'lucide-react';
import {
    getMcBans,
    getNetworkPlayer,
    getPlayer,
    keys,
    playerKeys,
    runAction,
    runNetworkAction,
    type Action,
    type NetworkAction,
    type NetworkPlayer,
    type PlayerOnServer,
    type PlayerStats,
} from './api';
import { mcbansEnabled, profileLinks } from './config';
import { ReasonDialog, banDetail, type Prompt } from './shared';
import SkinViewer from './SkinViewer';
import { isPremiumUuid, profileUrls, skinUrl } from './skins';

/**
 * Everything about one player: skin, standing on this server, stats, and the same
 * player on every other Minecraft server you can see, with actions on any of them.
 * Used by both the popup and the full page.
 */
export default function PlayerProfile({ server, name, footer }: { server: string; name: string; footer?: ReactNode }) {
    const here = useQuery({
        queryKey: playerKeys.onServer(server, name),
        queryFn: () => getPlayer(server, name),
        staleTime: 15_000,
    });

    const player = here.data;
    const shownName = player?.name ?? name;
    const uuid = player?.uuid ?? null;

    return (
        <div className={'mcp:flex mcp:flex-col mcp:gap-5'}>
            <div className={'mcp:flex mcp:flex-col mcp:gap-4 mcp:sm:flex-row'}>
                <SkinViewer url={here.isLoading ? null : skinUrl(shownName, uuid)} width={160} height={230} />
                <div className={'mcp:flex mcp:min-w-0 mcp:flex-1 mcp:flex-col mcp:gap-3'}>
                    <Identity name={shownName} uuid={uuid} onlineMode={player?.online_mode ?? null} />
                    {here.isLoading ? (
                        <Spinner size={'small'} />
                    ) : here.error ? (
                        <Alert type={'danger'}>{httpErrorToHuman(here.error)}</Alert>
                    ) : player ? (
                        <ThisServer server={server} player={player} name={shownName} />
                    ) : null}
                </div>
            </div>

            {player?.stats && <Stats stats={player.stats} />}
            {player && !player.stats && player.joined && (
                <p className={'mcp:text-xs mcp:text-muted-foreground'}>No stats file found for this player.</p>
            )}

            {mcbansEnabled() && isPremiumUuid(uuid) && <McBansSection uuid={uuid} />}
            <AllServers name={shownName} current={server} />
            {footer}
        </div>
    );
}

function Identity({ name, uuid, onlineMode }: { name: string; uuid: string | null; onlineMode: boolean | null }) {
    const premium = isPremiumUuid(uuid);
    const links = premium || onlineMode !== false ? profileUrls(name, profileLinks()) : [];

    return (
        <div className={'mcp:flex mcp:flex-col mcp:gap-1'}>
            <h2 className={'mcp:truncate mcp:text-xl mcp:font-semibold mcp:text-foreground'}>{name}</h2>
            {uuid && (
                <button
                    type={'button'}
                    className={
                        'mcp:flex mcp:w-fit mcp:items-center mcp:gap-1 mcp:font-mono mcp:text-xs mcp:text-muted-foreground mcp:hover:text-foreground'
                    }
                    title={'Copy UUID'}
                    onClick={() => {
                        navigator.clipboard?.writeText(uuid).then(
                            () => toast.success('UUID copied.'),
                            () => toast.error('Could not copy.'),
                        );
                    }}
                >
                    {uuid}
                    <Copy className={'mcp:size-3'} aria-hidden />
                </button>
            )}
            {uuid && !premium && (
                <p className={'mcp:text-xs mcp:text-muted-foreground'}>
                    Offline-mode UUID: this name is not tied to a Mojang account here.
                </p>
            )}
            {links.length > 0 && (
                <div className={'mcp:flex mcp:flex-wrap mcp:gap-3 mcp:text-sm'}>
                    {links.map((link) => (
                        <a
                            key={link.url}
                            href={link.url}
                            target={'_blank'}
                            rel={'noreferrer noopener'}
                            className={'mcp:flex mcp:items-center mcp:gap-1 mcp:text-primary mcp:hover:underline'}
                        >
                            {link.label}
                            <ExternalLink className={'mcp:size-3'} aria-hidden />
                        </a>
                    ))}
                </div>
            )}
        </div>
    );
}

function ThisServer({ server, player, name }: { server: string; player: PlayerOnServer; name: string }) {
    const queryClient = useQueryClient();
    const [prompt, setPrompt] = useState<Prompt | null>(null);
    const can = {
        kick: useServerPermission('ext.mc-players.kick'),
        ban: useServerPermission('ext.mc-players.ban'),
        whitelist: useServerPermission('ext.mc-players.whitelist'),
        op: useServerPermission('ext.mc-players.op'),
    };

    const action = useMutation({
        mutationFn: (input: { action: Action; reason?: string }) => runAction(server, input.action, name, input.reason),
        onSuccess: (lists) => {
            queryClient.setQueryData(keys.lists(server), lists);
            void queryClient.invalidateQueries({ queryKey: keys.online(server) });
            void queryClient.invalidateQueries({ queryKey: playerKeys.onServer(server, name) });
            void queryClient.invalidateQueries({ queryKey: playerKeys.network(name) });
            toast.success(lists.reply || 'Done.');
            setPrompt(null);
        },
        onError: (error) => toast.error(httpErrorToHuman(error)),
    });

    if (player.state === 'unreachable') {
        return <Alert type={'warning'}>{player.error ?? 'Could not reach this server.'}</Alert>;
    }

    const pending = action.isPending;
    const run = (input: { action: Action; reason?: string }) => action.mutate(input);

    return (
        <div className={'mcp:flex mcp:flex-col mcp:gap-3'}>
            <Badges player={player} />
            {player.ban && <p className={'mcp:text-sm mcp:text-muted-foreground'}>Banned: {banDetail(player.ban) || 'no reason given'}</p>}
            <p className={'mcp:text-xs mcp:text-muted-foreground'}>{seenText(player)}</p>
            <div className={'mcp:flex mcp:flex-wrap mcp:gap-1.5'}>
                {can.kick && player.online && (
                    <Button size={'xsmall'} isSecondary disabled={pending} onClick={() => setPrompt({ action: 'kick', name })}>
                        Kick
                    </Button>
                )}
                {can.ban &&
                    (player.ban ? (
                        <Button size={'xsmall'} isSecondary disabled={pending} onClick={() => run({ action: 'pardon' })}>
                            Unban
                        </Button>
                    ) : (
                        <Button.Danger size={'xsmall'} isSecondary disabled={pending} onClick={() => setPrompt({ action: 'ban', name })}>
                            Ban
                        </Button.Danger>
                    ))}
                {can.whitelist && (
                    <Button.Text
                        size={'xsmall'}
                        disabled={pending}
                        onClick={() => run({ action: player.whitelisted ? 'whitelist-remove' : 'whitelist-add' })}
                    >
                        {player.whitelisted ? 'Remove from whitelist' : 'Add to whitelist'}
                    </Button.Text>
                )}
                {can.op && (
                    <Button.Text size={'xsmall'} disabled={pending} onClick={() => run({ action: player.op ? 'deop' : 'op' })}>
                        {player.op ? 'Remove operator' : 'Make operator'}
                    </Button.Text>
                )}
            </div>
            {prompt && (
                <ReasonDialog
                    prompt={prompt}
                    pending={pending}
                    onClose={() => setPrompt(null)}
                    onConfirm={(reason) => run({ action: prompt.action, reason })}
                />
            )}
        </div>
    );
}

function Badges({ player }: { player: PlayerOnServer }) {
    const badges: { label: string; tone: 'good' | 'bad' | 'muted' }[] = [];
    if (player.state !== 'running') badges.push({ label: `Server ${player.state}`, tone: 'muted' });
    else if (player.online === true) badges.push({ label: 'Online', tone: 'good' });
    else if (player.online === false) badges.push({ label: 'Offline', tone: 'muted' });
    if (player.ban) badges.push({ label: 'Banned', tone: 'bad' });
    if (player.whitelisted) badges.push({ label: 'Whitelisted', tone: 'good' });
    else if (player.whitelist_enabled) badges.push({ label: 'Not whitelisted', tone: 'bad' });
    if (player.op) badges.push({ label: `Operator (level ${player.op.level})`, tone: 'muted' });
    if (!player.joined) badges.push({ label: 'Never joined', tone: 'muted' });

    return (
        <div className={'mcp:flex mcp:flex-wrap mcp:gap-1.5'}>
            {badges.map((badge) => (
                <Badge key={badge.label} tone={badge.tone}>
                    {badge.label}
                </Badge>
            ))}
        </div>
    );
}

function Badge({ tone, children }: { tone: 'good' | 'bad' | 'muted'; children: ReactNode }) {
    const colours = {
        good: 'mcp:bg-green-500/15 mcp:text-green-600 mcp:dark:text-green-400',
        bad: 'mcp:bg-red-500/15 mcp:text-red-600 mcp:dark:text-red-400',
        muted: 'mcp:bg-muted mcp:text-muted-foreground',
    };
    return <span className={`mcp:rounded mcp:px-1.5 mcp:py-0.5 mcp:text-xs mcp:font-medium ${colours[tone]}`}>{children}</span>;
}

export function seenText(player: PlayerOnServer): string {
    if (!player.joined) return 'Has not joined this server.';
    if (player.online) return 'Playing now.';
    if (!player.last_seen) return 'Has joined before.';
    const date = new Date(player.last_seen.replace(' ', 'T').replace(/ ([+-]\d{2})(\d{2})$/, '$1:$2'));
    return Number.isNaN(date.getTime()) ? 'Has joined before.' : `Last seen ${date.toLocaleString()}.`;
}

export function formatPlaytime(seconds: number): string {
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    if (hours >= 24) return `${Math.floor(hours / 24)}d ${hours % 24}h`;
    return hours > 0 ? `${hours}h ${minutes}m` : `${minutes}m`;
}

function Stats({ stats }: { stats: PlayerStats }) {
    const tiles: [string, string][] = [
        ['Playtime', formatPlaytime(stats.play_seconds)],
        ['Deaths', stats.deaths.toLocaleString()],
        ['Mob kills', stats.mob_kills.toLocaleString()],
        ['Player kills', stats.player_kills.toLocaleString()],
        ['Distance', stats.distance_m >= 1000 ? `${(stats.distance_m / 1000).toFixed(1)} km` : `${stats.distance_m} m`],
        ['Blocks mined', stats.blocks_mined.toLocaleString()],
        ['Jumps', stats.jumps.toLocaleString()],
    ];

    return (
        <section className={'mcp:flex mcp:flex-col mcp:gap-2'}>
            <h3 className={'mcp:text-sm mcp:font-semibold mcp:text-foreground'}>Stats on this server</h3>
            <dl className={'mcp:grid mcp:grid-cols-2 mcp:gap-2 mcp:sm:grid-cols-4'}>
                {tiles.map(([label, value]) => (
                    <div key={label} className={'mcp:rounded-md mcp:bg-muted mcp:px-3 mcp:py-2'}>
                        <dt className={'mcp:text-xs mcp:text-muted-foreground'}>{label}</dt>
                        <dd className={'mcp:text-sm mcp:font-medium mcp:text-foreground'}>{value}</dd>
                    </div>
                ))}
            </dl>
        </section>
    );
}

const BULK: { action: NetworkAction; label: string; needs: Action; danger?: boolean }[] = [
    { action: 'ban', label: 'Ban', needs: 'ban', danger: true },
    { action: 'pardon', label: 'Unban', needs: 'pardon' },
    { action: 'kick', label: 'Kick', needs: 'kick' },
    { action: 'whitelist-add', label: 'Whitelist', needs: 'whitelist-add' },
    { action: 'whitelist-remove', label: 'Unwhitelist', needs: 'whitelist-remove' },
    { action: 'op', label: 'Op', needs: 'op' },
    { action: 'deop', label: 'Deop', needs: 'deop' },
];

/** The same player on every Minecraft server you can see players on. */
function AllServers({ name, current }: { name: string; current: string }) {
    const queryClient = useQueryClient();
    const network = useQuery({ queryKey: playerKeys.network(name), queryFn: () => getNetworkPlayer(name), staleTime: 30_000 });
    const [selected, setSelected] = useState<Set<string> | null>(null);
    const [prompt, setPrompt] = useState<{ action: 'kick' | 'ban'; servers: string[] } | null>(null);

    const rows = useMemo(() => network.data?.servers ?? [], [network.data]);
    const reachable = rows.filter((row) => row.state !== 'unreachable').map((row) => row.server.uuid);
    const chosen = selected ?? new Set(reachable);

    const bulk = useMutation({
        mutationFn: (input: { action: NetworkAction; servers: string[]; reason?: string }) =>
            runNetworkAction(input.action, name, input.servers, input.reason),
        onSuccess: (results) => {
            const failed = results.filter((result) => !result.ok);
            if (failed.length === 0) toast.success(`Done on ${results.length} server${results.length === 1 ? '' : 's'}.`);
            else toast.error(failed.map((result) => `${result.server.name}: ${result.message}`).join('\n'));
            setPrompt(null);
            for (const result of results) {
                void queryClient.invalidateQueries({ queryKey: keys.lists(result.server.id) });
                void queryClient.invalidateQueries({ queryKey: playerKeys.onServer(result.server.id, name) });
            }
            void queryClient.invalidateQueries({ queryKey: playerKeys.network(name) });
        },
        onError: (error) => toast.error(httpErrorToHuman(error)),
    });

    const start = (action: NetworkAction, servers: string[]) => {
        if (servers.length === 0) return;
        if (action === 'kick' || action === 'ban') setPrompt({ action, servers });
        else bulk.mutate({ action, servers });
    };

    const allowedOn = (action: Action, data: NetworkPlayer['servers']) =>
        data.filter((row) => chosen.has(row.server.uuid) && row.can?.[action]).map((row) => row.server.uuid);

    return (
        <section className={'mcp:flex mcp:flex-col mcp:gap-2'}>
            <h3 className={'mcp:text-sm mcp:font-semibold mcp:text-foreground'}>All servers</h3>
            {network.isLoading ? (
                <Spinner size={'small'} />
            ) : network.error ? (
                <Alert type={'danger'}>{httpErrorToHuman(network.error)}</Alert>
            ) : rows.length === 0 ? (
                <p className={'mcp:text-sm mcp:text-muted-foreground'}>No Minecraft servers to show.</p>
            ) : (
                <>
                    <ul className={'mcp:flex mcp:flex-col mcp:divide-y mcp:divide-border mcp:rounded-md mcp:border mcp:border-border'}>
                        {rows.map((row) => (
                            <li key={row.server.uuid} className={'mcp:flex mcp:items-center mcp:gap-3 mcp:px-3 mcp:py-2'}>
                                <Checkbox
                                    checked={chosen.has(row.server.uuid)}
                                    disabled={row.state === 'unreachable'}
                                    aria-label={`Select ${row.server.name}`}
                                    onChange={(checked: boolean) => {
                                        const next = new Set(chosen);
                                        if (checked) next.add(row.server.uuid);
                                        else next.delete(row.server.uuid);
                                        setSelected(next);
                                    }}
                                />
                                <div className={'mcp:min-w-0 mcp:flex-1'}>
                                    <p className={'mcp:truncate mcp:text-sm mcp:font-medium mcp:text-foreground'}>
                                        {row.server.name}
                                        {row.server.id === current || row.server.uuid === current ? (
                                            <span className={'mcp:ml-1.5 mcp:text-xs mcp:font-normal mcp:text-muted-foreground'}>
                                                (this server)
                                            </span>
                                        ) : null}
                                    </p>
                                    {row.state === 'unreachable' ? (
                                        <p className={'mcp:text-xs mcp:text-muted-foreground'}>{row.error ?? 'Unreachable.'}</p>
                                    ) : (
                                        <p className={'mcp:truncate mcp:text-xs mcp:text-muted-foreground'}>{networkSummary(row)}</p>
                                    )}
                                </div>
                                {row.state !== 'unreachable' && (
                                    <div className={'mcp:flex mcp:shrink-0 mcp:gap-1'}>
                                        {row.can?.ban &&
                                            (row.ban ? (
                                                <Button.Text
                                                    size={'xsmall'}
                                                    disabled={bulk.isPending}
                                                    onClick={() => start('pardon', [row.server.uuid])}
                                                >
                                                    Unban
                                                </Button.Text>
                                            ) : (
                                                <Button.Text
                                                    size={'xsmall'}
                                                    disabled={bulk.isPending}
                                                    onClick={() => start('ban', [row.server.uuid])}
                                                >
                                                    Ban
                                                </Button.Text>
                                            ))}
                                        {row.can?.['whitelist-add'] && (
                                            <Button.Text
                                                size={'xsmall'}
                                                disabled={bulk.isPending}
                                                onClick={() =>
                                                    start(row.whitelisted ? 'whitelist-remove' : 'whitelist-add', [row.server.uuid])
                                                }
                                            >
                                                {row.whitelisted ? 'Unwhitelist' : 'Whitelist'}
                                            </Button.Text>
                                        )}
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>
                    <div className={'mcp:flex mcp:flex-wrap mcp:items-center mcp:gap-1.5'}>
                        <span className={'mcp:mr-1 mcp:text-xs mcp:text-muted-foreground'}>On {chosen.size} selected:</span>
                        {BULK.map((entry) => {
                            const servers = allowedOn(entry.needs, rows);
                            if (servers.length === 0) return null;
                            const Component = entry.danger ? Button.Danger : Button;
                            return (
                                <Component
                                    key={entry.action}
                                    size={'xsmall'}
                                    isSecondary
                                    disabled={bulk.isPending}
                                    onClick={() => start(entry.action, servers)}
                                >
                                    {entry.label}
                                </Component>
                            );
                        })}
                    </div>
                </>
            )}
            {prompt && (
                <ReasonDialog
                    prompt={{ action: prompt.action, name }}
                    pending={bulk.isPending}
                    onClose={() => setPrompt(null)}
                    onConfirm={(reason) => bulk.mutate({ action: prompt.action, servers: prompt.servers, reason })}
                />
            )}
        </section>
    );
}

export function networkSummary(row: PlayerOnServer): string {
    const parts: string[] = [];
    if (row.state !== 'running') parts.push(`Server ${row.state}`);
    else if (row.online === true) parts.push('Online');
    if (row.ban) parts.push(row.ban.reason ? `Banned: ${row.ban.reason}` : 'Banned');
    if (row.whitelisted) parts.push('Whitelisted');
    if (row.op) parts.push(`Operator ${row.op.level}`);
    if (!row.joined) parts.push('Never joined');
    else if (row.online !== true && row.last_seen) parts.push(seenText(row).replace(/\.$/, ''));
    return parts.join(' · ') || 'Joined before';
}

/** Global ban history from MCBans. Only shown for real accounts. */
function McBansSection({ uuid }: { uuid: string }) {
    const record = useQuery({
        queryKey: ['mc-players', 'mcbans', uuid.replace(/-/g, '').toLowerCase()],
        queryFn: () => getMcBans(uuid),
        staleTime: 30 * 60_000,
        retry: false,
    });

    return (
        <section className={'mcp:flex mcp:flex-col mcp:gap-2'}>
            <div className={'mcp:flex mcp:items-center mcp:justify-between mcp:gap-2'}>
                <h3 className={'mcp:text-sm mcp:font-semibold mcp:text-foreground'}>MCBans</h3>
                {record.data && (
                    <a
                        href={record.data.url}
                        target={'_blank'}
                        rel={'noreferrer noopener'}
                        className={'mcp:flex mcp:items-center mcp:gap-1 mcp:text-xs mcp:text-primary mcp:hover:underline'}
                    >
                        mcbans.com
                        <ExternalLink className={'mcp:size-3'} aria-hidden />
                    </a>
                )}
            </div>
            {record.isLoading ? (
                <Spinner size={'small'} />
            ) : record.error ? (
                <p className={'mcp:text-sm mcp:text-muted-foreground'}>{httpErrorToHuman(record.error)}</p>
            ) : !record.data?.known ? (
                <p className={'mcp:text-sm mcp:text-muted-foreground'}>No MCBans record: never banned on a server that uses MCBans.</p>
            ) : (
                <>
                    <div className={'mcp:flex mcp:flex-wrap mcp:gap-1.5'}>
                        {record.data.reputation !== null && (
                            <Badge tone={record.data.reputation >= 10 ? 'good' : record.data.reputation >= 5 ? 'muted' : 'bad'}>
                                Reputation {record.data.reputation}/10
                            </Badge>
                        )}
                        <Badge tone={record.data.bans_total > 0 ? 'bad' : 'good'}>
                            {record.data.bans_total === 0
                                ? 'No bans'
                                : `${record.data.bans_total} ban${record.data.bans_total === 1 ? '' : 's'}`}
                        </Badge>
                    </div>
                    {record.data.bans.length > 0 && (
                        <ul className={'mcp:flex mcp:flex-col mcp:divide-y mcp:divide-border mcp:rounded-md mcp:border mcp:border-border'}>
                            {record.data.bans.map((ban, index) => (
                                <li key={ban.id ?? index} className={'mcp:flex mcp:flex-col mcp:gap-0.5 mcp:px-3 mcp:py-2'}>
                                    <div className={'mcp:flex mcp:items-center mcp:gap-2'}>
                                        <Badge tone={ban.type === 'global' ? 'bad' : 'muted'}>
                                            {ban.type === 'global' ? 'Global' : 'Local'}
                                        </Badge>
                                        <span className={'mcp:truncate mcp:text-sm mcp:text-foreground'}>
                                            {ban.reason ?? 'No reason given'}
                                        </span>
                                    </div>
                                    <p className={'mcp:truncate mcp:text-xs mcp:text-muted-foreground'}>
                                        {[
                                            ban.server,
                                            ban.admin ? `by ${ban.admin}` : null,
                                            mcbansDate(ban.date),
                                            ban.permanent ? 'permanent' : null,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    )}
                    {record.data.bans_total > record.data.bans.length && (
                        <p className={'mcp:text-xs mcp:text-muted-foreground'}>
                            Showing the latest {record.data.bans.length}; the rest are on mcbans.com.
                        </p>
                    )}
                </>
            )}
        </section>
    );
}

function mcbansDate(value: string | null): string | null {
    if (!value) return null;
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? null : date.toLocaleDateString();
}
