import { useQuery } from '@tanstack/react-query';
import { Alert, Spinner, TitledGreyBox, httpErrorToHuman, useServerPermission } from '@pterodactyl/sdk';
import { getReports, keys, type Report } from './api';
import { sharingEnabled } from './config';
import { formatBytes, formatWhen, indexProgress, isBusy } from './logic';
import { CulpritLine, Folded, SeverityBadge, ShareButton } from './shared';

const KIND_LABEL: Record<Report['kind'], string> = { crash: 'Crash report', jvm: 'Java crash', log: 'Start-up failure' };

export default function ReportsPanel({ identifier }: { identifier: string }) {
    const canShare = useServerPermission('ext.mc-crash.share');
    const reports = useQuery({
        queryKey: keys.reports(identifier),
        queryFn: () => getReports(identifier),
        // The server reads a few files at a time; ask again until everything has a verdict.
        refetchInterval: (query) => (isBusy(query.state.data) && query.state.dataUpdateCount < 60 ? 1500 : false),
    });

    if (reports.isLoading) {
        return (
            <TitledGreyBox title={'Crash reports'}>
                <Spinner centered />
            </TitledGreyBox>
        );
    }
    if (reports.error || !reports.data) {
        return (
            <TitledGreyBox title={'Crash reports'}>
                <Alert type={'danger'}>{httpErrorToHuman(reports.error)}</Alert>
            </TitledGreyBox>
        );
    }

    const data = reports.data;
    const sharing = canShare && sharingEnabled() && data.sharing;

    return (
        <div className={'mccr:flex mccr:flex-col mccr:gap-3'}>
            {data.index.pending > 0 && (
                <Alert type={'info'}>Reading plugins and mods to find the cause: {indexProgress(data.index)}.</Alert>
            )}

            {data.reports.length === 0 ? (
                <TitledGreyBox title={'Crash reports'}>
                    <p className={'mccr:text-sm mccr:text-muted-foreground'}>
                        No crash reports found. The server writes one to the crash-reports folder when it crashes, and a hs_err_pid file in
                        the main folder when Java itself crashes. Errors that did not crash the server are on the Log errors tab.
                    </p>
                </TitledGreyBox>
            ) : (
                data.reports.map((report) => (
                    <ReportCard key={report.path} report={report} server={identifier} links={data.links} sharing={sharing} />
                ))
            )}
        </div>
    );
}

function ReportCard({ report, server, links, sharing }: { report: Report; server: string; links: boolean; sharing: boolean }) {
    const verdict = report.verdict;

    return (
        <div className={'mccr:flex mccr:flex-col mccr:gap-3 mccr:rounded-lg mccr:border mccr:border-border mccr:bg-background mccr:p-4'}>
            <div className={'mccr:flex mccr:flex-wrap mccr:items-center mccr:gap-2 mccr:text-xs mccr:text-muted-foreground'}>
                {verdict && <SeverityBadge severity={verdict.severity} />}
                <span>{KIND_LABEL[report.kind]}</span>
                {report.time && <span>· {formatWhen(report.time)}</span>}
                <span className={'mccr:break-all'}>· {report.name}</span>
                <span>· {formatBytes(report.size)}</span>
            </div>

            {report.status === 'pending' && (
                <p className={'mccr:flex mccr:items-center mccr:gap-2 mccr:text-sm mccr:text-muted-foreground'}>
                    <Spinner size={'small'} /> Reading this report...
                </p>
            )}
            {report.status === 'too_large' && (
                <p className={'mccr:text-sm mccr:text-muted-foreground'}>This file is too big to read here. Open it from the Files page.</p>
            )}
            {report.status === 'unreadable' && (
                <p className={'mccr:text-sm mccr:text-muted-foreground'}>
                    This file could not be read just now. It is tried again in a few minutes.
                </p>
            )}

            {verdict && (
                <>
                    <p className={'mccr:text-base mccr:font-medium mccr:text-foreground'}>{verdict.verdict}</p>

                    {verdict.culprit ? (
                        <CulpritLine culprit={verdict.culprit} server={server} links={links} />
                    ) : (
                        <p className={'mccr:text-sm mccr:text-muted-foreground'}>
                            Cause: no plugin or mod could be pointed out from this report.
                        </p>
                    )}

                    {verdict.fix && (
                        <p className={'mccr:text-sm mccr:text-foreground'}>
                            <span className={'mccr:text-muted-foreground'}>Fix </span>
                            {verdict.fix}
                        </p>
                    )}

                    {verdict.also.length > 0 && (
                        <p className={'mccr:text-xs mccr:text-muted-foreground'}>
                            Also in this file: {verdict.also.map((item) => item.title).join(', ')}.
                        </p>
                    )}

                    {(verdict.meta.minecraft || verdict.meta.java) && (
                        <p className={'mccr:text-xs mccr:text-muted-foreground'}>
                            {[
                                verdict.meta.minecraft && `Minecraft ${verdict.meta.minecraft}`,
                                verdict.meta.java && `Java ${verdict.meta.java}`,
                            ]
                                .filter(Boolean)
                                .join(' · ')}
                        </p>
                    )}

                    <Folded
                        summary={'Raw report'}
                        load={{ server, path: report.path }}
                        actions={sharing ? <ShareButton server={server} path={report.path} name={report.name} /> : undefined}
                    />
                </>
            )}
        </div>
    );
}
