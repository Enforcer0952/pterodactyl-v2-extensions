import { useQuery } from '@tanstack/react-query';
import { Alert, Spinner, TitledGreyBox, httpErrorToHuman, useServerPermission } from '@pterodactyl/sdk';
import { getLogs, keys, type LogGroup, type LogSource } from './api';
import { sharingEnabled } from './config';
import { formatBytes, formatWhen, indexProgress, isBusy, timesText } from './logic';
import { CulpritLine, Folded, SeverityBadge, ShareButton } from './shared';

export default function LogsPanel({ identifier }: { identifier: string }) {
    const canShare = useServerPermission('ext.mc-crash.share');
    const logs = useQuery({
        queryKey: keys.logs(identifier),
        queryFn: () => getLogs(identifier),
        refetchInterval: (query) => (isBusy(query.state.data) && query.state.dataUpdateCount < 60 ? 1500 : false),
    });

    if (logs.isLoading) {
        return (
            <TitledGreyBox title={'Log errors'}>
                <Spinner centered />
            </TitledGreyBox>
        );
    }
    if (logs.error || !logs.data) {
        return (
            <TitledGreyBox title={'Log errors'}>
                <Alert type={'danger'}>{httpErrorToHuman(logs.error)}</Alert>
            </TitledGreyBox>
        );
    }

    const data = logs.data;
    const sharing = canShare && sharingEnabled() && data.sharing;
    const problems = data.groups.filter((group) => !group.noise);
    const noise = data.groups.filter((group) => group.noise);
    const scanned = data.sources.filter((source) => source.status === 'scanned' || source.status === 'console').length;

    return (
        <div className={'mccr:flex mccr:flex-col mccr:gap-3'}>
            {data.index.pending > 0 && (
                <Alert type={'info'}>Reading plugins and mods to find the cause: {indexProgress(data.index)}.</Alert>
            )}
            {data.pending > 0 && (
                <Alert type={'info'}>
                    Scanning logs: {scanned} of {data.sources.length} done.
                </Alert>
            )}

            {data.sources.length === 0 ? (
                <TitledGreyBox title={'Log errors'}>
                    <p className={'mccr:text-sm mccr:text-muted-foreground'}>
                        There is no logs folder yet. The server creates it on the first start.
                    </p>
                </TitledGreyBox>
            ) : (
                <>
                    {problems.length === 0 && data.pending === 0 && (
                        <TitledGreyBox title={'Log errors'}>
                            <p className={'mccr:text-sm mccr:text-muted-foreground'}>
                                No warnings or errors found in the logs that were scanned.
                            </p>
                        </TitledGreyBox>
                    )}
                    {problems.map((group) => (
                        <GroupCard key={group.id} group={group} server={identifier} links={data.links} />
                    ))}
                    {noise.length > 0 && (
                        <details className={'mccr:rounded-lg mccr:border mccr:border-border mccr:bg-background'}>
                            <summary className={'mccr:cursor-pointer mccr:px-4 mccr:py-3 mccr:text-sm mccr:text-muted-foreground'}>
                                Harmless noise ({noise.length} {noise.length === 1 ? 'kind' : 'kinds'},{' '}
                                {noise.reduce((sum, group) => sum + group.count, 0).toLocaleString('en')} lines)
                            </summary>
                            <div className={'mccr:flex mccr:flex-col mccr:gap-2 mccr:border-t mccr:border-border mccr:p-4'}>
                                {noise.map((group) => (
                                    <GroupCard key={group.id} group={group} server={identifier} links={false} />
                                ))}
                            </div>
                        </details>
                    )}
                    <SourcesList sources={data.sources} server={identifier} sharing={sharing} />
                </>
            )}
        </div>
    );
}

function GroupCard({ group, server, links }: { group: LogGroup; server: string; links: boolean }) {
    return (
        <div className={'mccr:flex mccr:flex-col mccr:gap-3 mccr:rounded-lg mccr:border mccr:border-border mccr:bg-background mccr:p-4'}>
            <div className={'mccr:flex mccr:flex-wrap mccr:items-center mccr:gap-2 mccr:text-xs mccr:text-muted-foreground'}>
                <SeverityBadge severity={group.severity} />
                <span>{timesText(group.count)}</span>
                {group.first && <span>· first {formatWhen(group.first)}</span>}
                {group.last && group.last !== group.first && <span>· last {formatWhen(group.last)}</span>}
                {group.stat && <span>· {group.stat}</span>}
            </div>

            <p className={'mccr:break-words mccr:text-base mccr:font-medium mccr:text-foreground'}>{group.title}</p>

            {group.culprit && <CulpritLine culprit={group.culprit} server={server} links={links} />}

            {group.fix && (
                <p className={'mccr:text-sm mccr:text-foreground'}>
                    <span className={'mccr:text-muted-foreground'}>Fix </span>
                    {group.fix}
                </p>
            )}

            <Folded summary={'Raw trace'} text={group.sample} />
        </div>
    );
}

function SourcesList({ sources, server, sharing }: { sources: LogSource[]; server: string; sharing: boolean }) {
    return (
        <details className={'mccr:rounded-lg mccr:border mccr:border-border mccr:bg-background'}>
            <summary className={'mccr:cursor-pointer mccr:px-4 mccr:py-3 mccr:text-sm mccr:text-muted-foreground'}>
                Scanned files ({sources.length})
            </summary>
            <ul className={'mccr:flex mccr:flex-col mccr:divide-y mccr:divide-border mccr:border-t mccr:border-border'}>
                {sources.map((source) => (
                    <li
                        key={source.path}
                        className={'mccr:flex mccr:flex-wrap mccr:items-center mccr:justify-between mccr:gap-3 mccr:px-4 mccr:py-2'}
                    >
                        <div className={'mccr:flex mccr:min-w-0 mccr:flex-col mccr:gap-0.5'}>
                            <p className={'mccr:break-all mccr:text-sm mccr:font-medium mccr:text-foreground'}>{source.name}</p>
                            <p className={'mccr:text-xs mccr:text-muted-foreground'}>
                                {formatBytes(source.size)}
                                {source.modified && ` · ${formatWhen(source.modified)}`}
                                {source.status === 'scanned' && ` · ${source.entries.toLocaleString('en')} warnings and errors`}
                                {source.status === 'pending' && ' · waiting'}
                                {source.note && ` · ${source.note}`}
                            </p>
                        </div>
                        {sharing && source.status === 'scanned' && <ShareButton server={server} path={source.path} name={source.name} />}
                    </li>
                ))}
            </ul>
        </details>
    );
}
