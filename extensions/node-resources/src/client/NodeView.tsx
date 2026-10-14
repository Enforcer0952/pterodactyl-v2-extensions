import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Button, Dialog, Spinner, TitledGreyBox, httpErrorToHuman, toast } from '@pterodactyl/sdk';
import {
    RANGE_LABEL,
    RANGE_SECONDS,
    applyLimits,
    getNode,
    getNodeHistory,
    keys,
    refreshNode,
    removeProbe,
    setupProbe,
    type CpuResource,
    type NodeReport,
    type Range,
    type Resource,
    type ServerRow,
} from './api';
import Chart, { type Series } from './Chart';
import { niceMax } from './chart';
import { ago, formatBytes, formatCpu, formatCpuLimit, formatDateTime, formatDays, formatLimit } from './format';
import { Meter, Stat, probeText } from './parts';

const LEVEL_CLASS = {
    warning: 'nr:border-warning/40 nr:bg-warning/10 nr:text-warning',
    danger: 'nr:border-destructive/40 nr:bg-destructive/10 nr:text-destructive',
} as const;

export function Warnings({ report }: { report: NodeReport }) {
    if (report.warnings.length === 0) return null;
    return (
        <ul className={'nr:flex nr:flex-col nr:gap-2'}>
            {report.warnings.map((warning) => (
                <li key={warning.id} className={`nr:rounded-sm nr:border nr:px-3 nr:py-2 nr:text-sm ${LEVEL_CLASS[warning.level]}`}>
                    {warning.text}
                </li>
            ))}
        </ul>
    );
}

function Overallocate({ value }: { value: number }) {
    return <>{value < 0 ? 'off' : `${value}%`}</>;
}

/** RAM or disk: what is promised, what is used and what is left on the host. */
function ResourceCard({ title, kind, data, noProbe }: { title: string; kind: 'memory' | 'disk'; data: Resource; noProbe: boolean }) {
    const known = data.host_total !== null;

    return (
        <TitledGreyBox title={title}>
            <div className={'nr:flex nr:flex-col nr:gap-4'}>
                <div className={'nr:grid nr:grid-cols-3 nr:gap-3'}>
                    <Stat
                        label={'Allocated'}
                        value={formatBytes(data.allocated)}
                        note={data.unlimited > 0 ? `${data.unlimited} unlimited` : `of ${formatBytes(data.panel_limit)} allowed`}
                    />
                    <Stat
                        label={'Really used'}
                        value={known ? formatBytes(data.host_used) : 'Unknown'}
                        note={`servers: ${formatBytes(data.servers_used)}`}
                    />
                    <Stat
                        label={'Free on host'}
                        value={known ? formatBytes(data.host_free) : 'Unknown'}
                        tone={
                            data.host_free !== null && data.host_total && data.host_free / data.host_total < 0.1 ? 'destructive' : undefined
                        }
                        note={known ? `of ${formatBytes(data.host_total)}` : undefined}
                    />
                </div>
                <div className={'nr:flex nr:flex-col nr:gap-2'}>
                    <Meter
                        label={'Promised to servers'}
                        part={data.committed}
                        whole={data.panel_limit}
                        text={`${formatBytes(data.committed)} of ${formatBytes(data.panel_limit)}`}
                    />
                    {known && (
                        <Meter
                            label={'Used on host'}
                            part={data.host_used}
                            whole={data.host_total}
                            text={`${formatBytes(data.host_used)} of ${formatBytes(data.host_total)}`}
                        />
                    )}
                </div>
                <p className={'nr:text-xs nr:text-muted-foreground'}>
                    Node setting: {formatBytes(data.node_size)}, overallocate <Overallocate value={data.overallocate} />. Room left to give
                    out: {formatBytes(data.unallocated)}.
                </p>
                {!known && kind === 'disk' && noProbe && (
                    <p className={'nr:text-xs nr:text-muted-foreground'}>Set up the probe to see how much disk is really free.</p>
                )}
                {data.overcommitted && (
                    <p className={'nr:text-xs nr:text-warning'}>
                        Servers could use up to {formatBytes(data.committed)}, more than the {formatBytes(data.host_total)} this host has.
                    </p>
                )}
            </div>
        </TitledGreyBox>
    );
}

function CpuCard({ cpu }: { cpu: CpuResource }) {
    return (
        <TitledGreyBox title={'CPU'}>
            <div className={'nr:flex nr:flex-col nr:gap-4'}>
                <div className={'nr:grid nr:grid-cols-3 nr:gap-3'}>
                    <Stat
                        label={'Allocated'}
                        value={formatCpu(cpu.allocated)}
                        note={
                            cpu.unlimited > 0
                                ? `${cpu.unlimited} unlimited`
                                : cpu.capacity !== null
                                  ? `of ${formatCpu(cpu.capacity)}`
                                  : undefined
                        }
                    />
                    <Stat label={'Really used'} value={formatCpu(cpu.servers_used)} note={'servers, right now'} />
                    <Stat
                        label={'Load on host'}
                        value={cpu.load1 === null ? 'Unknown' : String(cpu.load1)}
                        note={cpu.threads !== null ? `${cpu.threads} threads` : undefined}
                    />
                </div>
                {cpu.capacity !== null && (
                    <Meter
                        label={'Promised to servers'}
                        part={cpu.committed}
                        whole={cpu.capacity}
                        text={`${formatCpu(cpu.committed)} of ${formatCpu(cpu.capacity)}`}
                    />
                )}
            </div>
        </TitledGreyBox>
    );
}

function ProbeBox({ report, busy, onSetup, onRemove }: { report: NodeReport; busy: boolean; onSetup: () => void; onRemove: () => void }) {
    const { probe } = report;
    const [confirm, setConfirm] = useState(false);
    const canSetup = probe.state === 'none' || probe.state === 'failed';

    return (
        <TitledGreyBox title={'Probe'}>
            <div className={'nr:flex nr:flex-col nr:gap-3'}>
                <p className={'nr:text-sm nr:text-foreground'}>
                    {probeText(probe.state)}
                    {probe.server && <span className={'nr:text-muted-foreground'}> ({probe.server.name})</span>}
                    {probe.last_at && <span className={'nr:text-muted-foreground'}>, last report {ago(probe.last_at)}</span>}
                </p>
                {canSetup && (
                    <p className={'nr:text-xs nr:text-muted-foreground'}>
                        The probe is a tiny server (64 MB RAM, 50 MB disk) that reads this host&apos;s real free memory and disk. Without it
                        the panel only sees what the servers use.
                    </p>
                )}
                <div className={'nr:flex nr:flex-wrap nr:gap-2'}>
                    {canSetup && (
                        <Button size={'small'} disabled={busy} onClick={onSetup}>
                            {probe.state === 'failed' ? 'Try again' : 'Set up probe'}
                        </Button>
                    )}
                    {probe.state !== 'none' && (
                        <Button size={'small'} isSecondary disabled={busy} onClick={() => setConfirm(true)}>
                            Remove probe
                        </Button>
                    )}
                </div>
                {confirm && (
                    <Dialog.Confirm
                        open
                        onClose={() => setConfirm(false)}
                        title={'Remove the probe?'}
                        confirm={'Remove'}
                        onConfirmed={() => {
                            setConfirm(false);
                            onRemove();
                        }}
                    >
                        The probe server is deleted. Free host memory and disk will show as unknown until you set it up again.
                    </Dialog.Confirm>
                )}
            </div>
        </TitledGreyBox>
    );
}

function Usage({ usage, format }: { usage: ServerRow['memory']; format: (n: number) => string }) {
    return (
        <div className={'nr:flex nr:flex-col'}>
            <span>{usage.now === null ? '-' : format(usage.now)}</span>
            <span className={'nr:text-xs nr:text-muted-foreground'}>
                peak {usage.peak === null ? '-' : format(usage.peak)} / limit {usage.limit === 0 ? 'unlimited' : format(usage.limit)}
            </span>
        </div>
    );
}

function ServersTable({
    rows,
    applying,
    onApply,
}: {
    rows: ServerRow[];
    applying: boolean;
    onApply: (row: ServerRow, fields: ('memory' | 'disk')[]) => void;
}) {
    if (rows.length === 0) return <p className={'nr:text-sm nr:text-muted-foreground'}>No servers on this node.</p>;

    return (
        <div className={'nr:overflow-x-auto'}>
            <table className={'nr:w-full nr:text-left nr:text-sm'}>
                <thead>
                    <tr className={'nr:border-b nr:border-border nr:text-xs nr:text-muted-foreground'}>
                        <th className={'nr:py-2 nr:pr-3 nr:font-medium'}>Server</th>
                        <th className={'nr:py-2 nr:pr-3 nr:font-medium'}>Memory</th>
                        <th className={'nr:py-2 nr:pr-3 nr:font-medium'}>Disk</th>
                        <th className={'nr:py-2 nr:pr-3 nr:font-medium'}>CPU</th>
                        <th className={'nr:py-2 nr:font-medium'}>Suggested limits</th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row) => (
                        <tr key={row.id} className={'nr:border-b nr:border-border/50 nr:align-top'}>
                            <td className={'nr:py-2 nr:pr-3'}>
                                <div className={'nr:text-foreground'}>{row.name}</div>
                                <div className={'nr:text-xs nr:text-muted-foreground'}>
                                    {row.is_probe ? 'Probe' : (row.state ?? row.status ?? '')}
                                </div>
                            </td>
                            <td className={'nr:py-2 nr:pr-3'}>
                                <Usage usage={row.memory} format={formatBytes} />
                            </td>
                            <td className={'nr:py-2 nr:pr-3'}>
                                <Usage usage={row.disk} format={formatBytes} />
                            </td>
                            <td className={'nr:py-2 nr:pr-3'}>
                                <Usage usage={row.cpu} format={(n) => (n === row.cpu.limit ? formatCpuLimit(n) : formatCpu(n))} />
                            </td>
                            <td className={'nr:py-2'}>
                                {row.suggestion ? (
                                    <div className={'nr:flex nr:flex-col nr:gap-1'}>
                                        {row.suggestion.memory !== null && <span>Memory {formatBytes(row.suggestion.memory)}</span>}
                                        {row.suggestion.disk !== null && <span>Disk {formatBytes(row.suggestion.disk)}</span>}
                                        <Button
                                            size={'xsmall'}
                                            isSecondary
                                            disabled={applying}
                                            onClick={() =>
                                                onApply(row, [
                                                    ...(row.suggestion?.memory !== null ? (['memory'] as const) : []),
                                                    ...(row.suggestion?.disk !== null ? (['disk'] as const) : []),
                                                ])
                                            }
                                        >
                                            Apply
                                        </Button>
                                    </div>
                                ) : (
                                    <span className={'nr:text-xs nr:text-muted-foreground'}>
                                        {row.memory.limit === 0 || row.disk.limit === 0 ? 'Needs 24 h of data' : '-'}
                                    </span>
                                )}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function Graphs({ nodeId, report }: { nodeId: number; report: NodeReport }) {
    const [range, setRange] = useState<Range>('24h');
    const history = useQuery({
        queryKey: keys.nodeHistory(nodeId, range),
        queryFn: () => getNodeHistory(nodeId, range),
        refetchInterval: 60_000,
    });
    const data = history.data;
    const seconds = RANGE_SECONDS[range];
    const to = useMemo(() => Math.floor(Date.now() / 1000), [data]);
    const from = to - seconds;

    const points = data?.points ?? [];
    const ramMax = niceMax(Math.max(data?.mem_total ?? 0, ...points.map((p) => p.mem ?? 0), report.ram.panel_limit > 0 ? 0 : 0));
    const diskMax = niceMax(Math.max(data?.disk_total ?? 0, ...points.map((p) => p.disk ?? 0)));
    const loadMax = niceMax(Math.max(data?.cpus ?? 0, ...points.map((p) => p.load ?? 0)));

    const line = (key: 'mem' | 'disk' | 'load' | 'sv_mem' | 'sv_disk') => points.map((p) => ({ t: p.t, v: p[key] }));
    const ram: Series[] = [
        { id: 'host', label: 'Host used', token: '--chart-1', points: line('mem'), area: true },
        { id: 'servers', label: 'Servers', token: '--chart-2', points: line('sv_mem') },
    ];
    const disk: Series[] = [
        { id: 'host', label: 'Host used', token: '--chart-1', points: line('disk'), area: true },
        { id: 'servers', label: 'Servers', token: '--chart-2', points: line('sv_disk') },
    ];

    return (
        <TitledGreyBox title={'History'}>
            <div className={'nr:flex nr:flex-col nr:gap-4'}>
                <div role={'tablist'} className={'nr:flex nr:flex-wrap nr:gap-1'}>
                    {(data?.ranges ?? ['24h', '7d']).map((item) => (
                        <button
                            key={item}
                            role={'tab'}
                            aria-selected={item === range}
                            type={'button'}
                            onClick={() => setRange(item)}
                            className={`nr:rounded-sm nr:px-2 nr:py-1 nr:text-xs ${item === range ? 'nr:bg-primary nr:text-primary-foreground' : 'nr:bg-muted nr:text-muted-foreground nr:hover:text-foreground'}`}
                        >
                            {RANGE_LABEL[item]}
                        </button>
                    ))}
                </div>
                {history.isLoading ? (
                    <Spinner />
                ) : (
                    <>
                        <Chart
                            title={'Memory'}
                            series={ram}
                            from={from}
                            to={to}
                            max={ramMax}
                            format={formatBytes}
                            step={data?.step ?? 60}
                            limit={data?.mem_total ? { value: data.mem_total, label: 'Host memory' } : null}
                        />
                        <Chart
                            title={'Disk'}
                            series={disk}
                            from={from}
                            to={to}
                            max={diskMax}
                            format={formatBytes}
                            step={data?.step ?? 60}
                            limit={data?.disk_total ? { value: data.disk_total, label: 'Disk size' } : null}
                            note={
                                report.forecast
                                    ? report.forecast.days === null
                                        ? 'Disk use is flat or shrinking.'
                                        : `At the current pace the disk is full in ${formatDays(report.forecast.days)} (about ${formatBytes(report.forecast.growth_per_day)} per day).`
                                    : 'The forecast needs at least 24 hours of data.'
                            }
                        />
                        <Chart
                            title={'Load'}
                            series={[{ id: 'load', label: 'Load (1 min)', token: '--chart-3', points: line('load'), area: true }]}
                            from={from}
                            to={to}
                            max={loadMax}
                            format={(n) => String(Number(n.toFixed(2)))}
                            step={data?.step ?? 60}
                            limit={data?.cpus ? { value: data.cpus, label: 'Threads' } : null}
                        />
                    </>
                )}
            </div>
        </TitledGreyBox>
    );
}

/** The whole resources tab of one node. */
export default function NodeView({ nodeId }: { nodeId: number }) {
    const queryClient = useQueryClient();
    const query = useQuery({ queryKey: keys.node(nodeId), queryFn: () => getNode(nodeId), refetchInterval: 60_000 });
    const [pending, setPending] = useState<{ row: ServerRow; fields: ('memory' | 'disk')[] } | null>(null);

    const store = (report: NodeReport) => {
        queryClient.setQueryData(keys.node(nodeId), report);
        void queryClient.invalidateQueries({ queryKey: ['node-resources'] });
    };
    const fail = (error: unknown) => toast.error(httpErrorToHuman(error));

    const setup = useMutation({
        mutationFn: () => setupProbe(nodeId),
        onSuccess: (report) => {
            store(report);
            toast.success('Probe is being installed. Numbers appear in a few minutes.');
        },
        onError: fail,
    });
    const remove = useMutation({
        mutationFn: () => removeProbe(nodeId),
        onSuccess: (report) => {
            store(report);
            toast.success('Probe removed.');
        },
        onError: fail,
    });
    const refresh = useMutation({
        mutationFn: () => refreshNode(nodeId),
        onSuccess: (report) => {
            store(report);
            toast.success('Numbers refreshed.');
        },
        onError: fail,
    });
    const apply = useMutation({
        mutationFn: (input: { row: ServerRow; fields: ('memory' | 'disk')[] }) => applyLimits(input.row.admin_id, input.fields),
        onSuccess: () => {
            setPending(null);
            toast.success('Limits updated.');
            void queryClient.invalidateQueries({ queryKey: ['node-resources'] });
        },
        onError: fail,
    });

    if (query.isLoading) return <Spinner />;
    if (query.isError || !query.data) return <Alert type={'danger'}>{httpErrorToHuman(query.error)}</Alert>;

    const report = query.data;
    const busy = setup.isPending || remove.isPending;
    const collector = report.collector;

    return (
        <div className={'nr:flex nr:flex-col nr:gap-4'}>
            <Warnings report={report} />
            {report.reachable === false && (
                <Alert type={'warning'}>The panel could not reach this node at the last check. The numbers below may be old.</Alert>
            )}

            <div className={'nr:flex nr:flex-wrap nr:items-center nr:justify-between nr:gap-2 nr:text-xs nr:text-muted-foreground'}>
                <span>
                    {collector?.last_run_at ? (
                        <>
                            Collector last ran at {formatDateTime(collector.last_run_at)} ({ago(collector.last_run_at)}).
                            {collector.stale && (
                                <span className={'nr:text-warning'}>
                                    {' '}
                                    It has not run for a while. Check that the panel scheduler is running.
                                </span>
                            )}
                        </>
                    ) : (
                        'The collector has not run yet. It needs the panel scheduler (cron) to be running.'
                    )}
                </span>
                <Button size={'xsmall'} isSecondary disabled={refresh.isPending} onClick={() => refresh.mutate()}>
                    Refresh now
                </Button>
            </div>

            <div className={'nr:grid nr:gap-4 nr:lg:grid-cols-2'}>
                <ResourceCard title={'Memory'} kind={'memory'} data={report.ram} noProbe={report.probe.state === 'none'} />
                <ResourceCard title={'Disk'} kind={'disk'} data={report.disk} noProbe={report.probe.state === 'none'} />
                <CpuCard cpu={report.cpu} />
                <ProbeBox report={report} busy={busy} onSetup={() => setup.mutate()} onRemove={() => remove.mutate()} />
            </div>

            <Graphs nodeId={nodeId} report={report} />

            <TitledGreyBox title={'Servers'}>
                <ServersTable
                    rows={report.servers ?? []}
                    applying={apply.isPending}
                    onApply={(row, fields) => setPending({ row, fields })}
                />
            </TitledGreyBox>

            {pending && (
                <Dialog.Confirm
                    open
                    pending={apply.isPending}
                    onClose={() => setPending(null)}
                    title={'Apply suggested limits?'}
                    confirm={'Apply'}
                    onConfirmed={() => apply.mutate(pending)}
                >
                    <>
                        {pending.row.name} gets{' '}
                        {pending.fields
                            .map(
                                (field) => `${field === 'memory' ? 'memory' : 'disk'} ${formatLimit(pending.row.suggestion?.[field] ?? 0)}`,
                            )
                            .join(' and ')}
                        . The server may need a restart for memory to take effect.
                    </>
                </Dialog.Confirm>
            )}
        </div>
    );
}
