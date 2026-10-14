import { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Spinner, TitledGreyBox, useServerPermission, type SdkServer } from '@pterodactyl/sdk';
import { RANGE_LABEL, RANGE_SECONDS, getServerHistory, keys, type Range } from './api';
import Chart from './Chart';
import { niceMax } from './chart';
import { ago, formatBytes, formatCpu } from './format';

/** Hidden unless the user holds the history permission. */
export default function HistorySlot({ data: server }: { data: SdkServer }) {
    const allowed = useServerPermission('ext.node-resources.history');
    if (!allowed) return null;

    return <HistoryCard identifier={server.attributes.identifier} />;
}

export function HistoryCard({ identifier }: { identifier: string }) {
    const [open, setOpen] = useState(false);
    const [range, setRange] = useState<Range>('24h');
    const query = useQuery({
        queryKey: keys.serverHistory(identifier, range),
        queryFn: () => getServerHistory(identifier, range),
        enabled: open,
        refetchInterval: open ? 60_000 : false,
    });
    const data = query.data;
    const seconds = RANGE_SECONDS[range];
    const to = useMemo(() => Math.floor(Date.now() / 1000), [data]);
    const from = to - seconds;
    const points = data?.points ?? [];
    const line = (key: 'cpu' | 'mem' | 'disk') => points.map((p) => ({ t: p.t, v: p[key] }));
    const step = data?.step ?? 60;

    return (
        <TitledGreyBox title={'History'}>
            <div className={'nr:flex nr:flex-col nr:gap-3'}>
                <div className={'nr:flex nr:flex-wrap nr:items-center nr:justify-between nr:gap-2'}>
                    <button
                        type={'button'}
                        aria-expanded={open}
                        onClick={() => setOpen(!open)}
                        className={'nr:text-sm nr:text-primary nr:hover:underline'}
                    >
                        {open ? 'Hide history' : 'Show history'}
                    </button>
                    {open && (
                        <div role={'tablist'} className={'nr:flex nr:gap-1'}>
                            {(data?.ranges ?? ['24h', '7d'])
                                .filter((item) => item === '24h' || item === '7d')
                                .map((item) => (
                                    <button
                                        key={item}
                                        role={'tab'}
                                        type={'button'}
                                        aria-selected={item === range}
                                        onClick={() => setRange(item)}
                                        className={`nr:rounded-sm nr:px-2 nr:py-1 nr:text-xs ${item === range ? 'nr:bg-primary nr:text-primary-foreground' : 'nr:bg-muted nr:text-muted-foreground nr:hover:text-foreground'}`}
                                    >
                                        {RANGE_LABEL[item]}
                                    </button>
                                ))}
                        </div>
                    )}
                </div>
                {open &&
                    (query.isLoading ? (
                        <Spinner />
                    ) : query.isError || !data ? (
                        <p className={'nr:text-sm nr:text-destructive'}>Could not load the history.</p>
                    ) : (
                        <>
                            <Chart
                                title={'CPU'}
                                series={[{ id: 'cpu', label: 'CPU', token: '--chart-1', points: line('cpu'), area: true }]}
                                from={from}
                                to={to}
                                max={niceMax(Math.max(100, ...points.map((p) => p.cpu ?? 0)))}
                                format={formatCpu}
                                step={step}
                                note={data.peaks.cpu === null ? undefined : `Peak ${formatCpu(data.peaks.cpu)}`}
                            />
                            <Chart
                                title={'Memory'}
                                series={[{ id: 'mem', label: 'Memory', token: '--chart-2', points: line('mem'), area: true }]}
                                from={from}
                                to={to}
                                max={niceMax(Math.max(data.limits.memory, ...points.map((p) => p.mem ?? 0)))}
                                format={formatBytes}
                                step={step}
                                limit={data.limits.memory > 0 ? { value: data.limits.memory, label: 'Limit' } : null}
                                note={data.peaks.mem === null ? undefined : `Peak ${formatBytes(data.peaks.mem)}`}
                            />
                            <Chart
                                title={'Disk'}
                                series={[{ id: 'disk', label: 'Disk', token: '--chart-3', points: line('disk'), area: true }]}
                                from={from}
                                to={to}
                                max={niceMax(Math.max(data.limits.disk, ...points.map((p) => p.disk ?? 0)))}
                                format={formatBytes}
                                step={step}
                                limit={data.limits.disk > 0 ? { value: data.limits.disk, label: 'Limit' } : null}
                                note={data.peaks.disk === null ? undefined : `Peak ${formatBytes(data.peaks.disk)}`}
                            />
                            <p className={'nr:text-xs nr:text-muted-foreground'}>
                                {data.collector.last_run_at ? `Updated ${ago(data.collector.last_run_at)}.` : 'No samples yet.'}
                            </p>
                        </>
                    ))}
            </div>
        </TitledGreyBox>
    );
}
