import { useQuery } from '@tanstack/react-query';
import { Alert, PanelLink, ServerContentBlock, Spinner, TitledGreyBox, httpErrorToHuman } from '@pterodactyl/sdk';
import { getOverview, keys, type NodeReport } from '../api';
import { ago, formatBytes, formatDateTime } from '../format';
import { Meter, probeText } from '../parts';
import { Warnings } from '../NodeView';

function NodeCard({ report }: { report: NodeReport }) {
    const { node } = report;
    return (
        <TitledGreyBox title={node.name}>
            <div className={'nr:flex nr:flex-col nr:gap-3'}>
                <Warnings report={report} />
                <Meter
                    label={'Memory on host'}
                    part={report.ram.host_used}
                    whole={report.ram.host_total}
                    text={
                        report.ram.host_total === null
                            ? 'Unknown'
                            : `${formatBytes(report.ram.host_used)} of ${formatBytes(report.ram.host_total)}`
                    }
                />
                <Meter
                    label={'Disk on host'}
                    part={report.disk.host_used}
                    whole={report.disk.host_total}
                    text={
                        report.disk.host_total === null
                            ? 'Set up probe to see free disk'
                            : `${formatBytes(report.disk.host_used)} of ${formatBytes(report.disk.host_total)}`
                    }
                />
                <Meter
                    label={'Memory promised'}
                    part={report.ram.committed}
                    whole={report.ram.panel_limit}
                    text={`${formatBytes(report.ram.committed)} of ${formatBytes(report.ram.panel_limit)}`}
                />
                <div className={'nr:flex nr:flex-wrap nr:items-center nr:justify-between nr:gap-2 nr:text-xs nr:text-muted-foreground'}>
                    <span>{probeText(report.probe.state)}</span>
                    <PanelLink
                        destination={{ extension: 'node-resources', screen: 'node', params: { id: String(node.id) } }}
                        className={'nr:text-primary nr:hover:underline'}
                    >
                        Details
                    </PanelLink>
                </div>
            </div>
        </TitledGreyBox>
    );
}

/** Every node on one page, with the warnings first. */
export default function OverviewScreen() {
    const query = useQuery({ queryKey: keys.overview, queryFn: getOverview, refetchInterval: 60_000 });

    return (
        <ServerContentBlock title={'Node resources'}>
            {query.isLoading ? (
                <Spinner />
            ) : query.isError || !query.data ? (
                <Alert type={'danger'}>{httpErrorToHuman(query.error)}</Alert>
            ) : (
                <div className={'nr:flex nr:flex-col nr:gap-4'}>
                    <p className={'nr:text-xs nr:text-muted-foreground'}>
                        {query.data.collector.last_run_at
                            ? `Collector last ran at ${formatDateTime(query.data.collector.last_run_at)} (${ago(query.data.collector.last_run_at)}). `
                            : 'The collector has not run yet. It needs the panel scheduler (cron) to be running. '}
                        {query.data.collector.nodes_failed > 0 && `${query.data.collector.nodes_failed} node(s) could not be reached.`}
                    </p>
                    {query.data.collector.stale && (
                        <Alert type={'warning'}>The collector has not run for a while. Check that the panel scheduler is running.</Alert>
                    )}
                    {query.data.nodes.length === 0 ? (
                        <p className={'nr:text-sm nr:text-muted-foreground'}>There are no nodes yet.</p>
                    ) : (
                        <div className={'nr:grid nr:gap-4 nr:md:grid-cols-2 nr:xl:grid-cols-3'}>
                            {query.data.nodes.map((report) => (
                                <NodeCard key={report.node.id} report={report} />
                            ))}
                        </div>
                    )}
                </div>
            )}
        </ServerContentBlock>
    );
}
