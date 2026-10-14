import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Alert, Button, Input, PageContentBlock, PanelLink, Select, Spinner, Table, httpErrorToHuman } from '@pterodactyl/sdk';
import { getAdminServers, keys, type AdminServer } from '../api';
import { describeEvent } from '../format';

const STATUS = [
    { value: 'all', label: 'All guarded servers' },
    { value: 'tripped', label: 'Stopped by Crash Guard' },
];

export default function AdminScreen() {
    const [page, setPage] = useState(1);
    const [status, setStatus] = useState('all');
    const [search, setSearch] = useState('');
    const list = useQuery({
        queryKey: keys.admin(page, status, search),
        queryFn: () => getAdminServers(page, status, search),
        refetchInterval: 30_000,
        placeholderData: (previous) => previous,
    });

    const meta = list.data?.meta;
    const pages = meta ? Math.max(1, Math.ceil(meta.total / meta.per_page)) : 1;

    return (
        <PageContentBlock title={'Crash Guard'}>
            <div className={'cg:flex cg:flex-col cg:gap-4'}>
                <div>
                    <h1 className={'cg:text-xl cg:font-semibold cg:text-foreground'}>Crash Guard</h1>
                    {meta && (
                        <p className={'cg:text-sm cg:text-muted-foreground'}>
                            {meta.guarded} guarded server{meta.guarded === 1 ? '' : 's'}, {meta.tripped} stopped by Crash Guard.{' '}
                            {meta.default_enabled
                                ? 'Every server is guarded unless it is switched off.'
                                : 'Servers are guarded when someone turns it on for them.'}
                        </p>
                    )}
                </div>

                <div className={'cg:flex cg:flex-wrap cg:items-center cg:gap-2'}>
                    <div className={'cg:w-64'}>
                        <Select
                            options={STATUS}
                            value={status}
                            onChange={(value) => {
                                setStatus(String(value));
                                setPage(1);
                            }}
                        />
                    </div>
                    <div className={'cg:min-w-48 cg:flex-1'}>
                        <Input
                            value={search}
                            maxLength={100}
                            placeholder={'Search by name or id'}
                            aria-label={'Search servers'}
                            onChange={(event) => {
                                setSearch(event.target.value);
                                setPage(1);
                            }}
                        />
                    </div>
                </div>

                {list.isError && <Alert type={'danger'}>{httpErrorToHuman(list.error)}</Alert>}
                {list.isLoading && (
                    <div className={'cg:flex cg:justify-center cg:py-8'}>
                        <Spinner size={'large'} />
                    </div>
                )}
                {list.data && (
                    <Table
                        rows={list.data.data}
                        keyOf={(row) => row.id}
                        isFetching={list.isFetching}
                        emptyState={'No guarded servers match.'}
                        columns={[
                            { id: 'server', label: 'Server', cell: (row) => <ServerCell row={row} /> },
                            { id: 'state', label: 'State', cell: (row) => <StateCell row={row} /> },
                            { id: 'limit', label: 'Limit', cell: (row) => `${row.max_crashes} in ${row.window_minutes} min` },
                            {
                                id: 'crashes',
                                label: 'Crashes (24 h)',
                                cell: (row) => (
                                    <span className={row.crashes_24h > 0 ? 'cg:text-warning' : 'cg:text-muted-foreground'}>
                                        {row.crashes_24h}
                                    </span>
                                ),
                            },
                            { id: 'recent', label: 'Recent', cell: (row) => <RecentCell row={row} /> },
                        ]}
                    />
                )}

                {meta && pages > 1 && (
                    <div className={'cg:flex cg:items-center cg:justify-between'}>
                        <Button size={'small'} isSecondary disabled={page <= 1} onClick={() => setPage(page - 1)}>
                            Previous
                        </Button>
                        <span className={'cg:text-sm cg:text-muted-foreground'}>
                            Page {page} of {pages}
                        </span>
                        <Button size={'small'} isSecondary disabled={page >= pages} onClick={() => setPage(page + 1)}>
                            Next
                        </Button>
                    </div>
                )}
            </div>
        </PageContentBlock>
    );
}

function ServerCell({ row }: { row: AdminServer }) {
    return (
        <div className={'cg:flex cg:flex-col'}>
            <PanelLink
                destination={{ extension: 'crash-guard', screen: 'guard', params: { id: row.id } }}
                className={'cg:text-primary cg:hover:underline'}
            >
                {row.name}
            </PanelLink>
            <span className={'cg:text-xs cg:text-muted-foreground'}>{row.node}</span>
        </div>
    );
}

function StateCell({ row }: { row: AdminServer }) {
    if (row.status === 'tripped') {
        return (
            <div className={'cg:flex cg:flex-col'}>
                <span className={'cg:text-sm cg:font-medium cg:text-destructive'}>Stopped</span>
                {row.tripped_at && (
                    <span className={'cg:text-xs cg:text-muted-foreground'}>
                        {row.trip_crashes} crashes in {row.trip_minutes} min, {new Date(row.tripped_at).toLocaleString()}
                    </span>
                )}
            </div>
        );
    }

    return (
        <div className={'cg:flex cg:flex-col'}>
            <span className={'cg:text-sm cg:text-success'}>Watching</span>
            <span className={'cg:text-xs cg:text-muted-foreground'}>
                {row.last_state ? `Seen ${row.last_state}` : 'Not read yet'}
                {row.last_seen_at ? `, ${new Date(row.last_seen_at).toLocaleTimeString()}` : ''}
            </span>
        </div>
    );
}

function RecentCell({ row }: { row: AdminServer }) {
    if (row.recent.length === 0) {
        return (
            <span className={'cg:text-xs cg:text-muted-foreground'}>
                {row.last_crash_at ? `Crash ${new Date(row.last_crash_at).toLocaleString()}` : 'Nothing'}
            </span>
        );
    }

    return (
        <ul className={'cg:flex cg:flex-col cg:gap-0.5'}>
            {row.recent.map((event) => (
                <li key={`${event.kind}-${event.occurred_at}`} className={'cg:text-xs cg:text-muted-foreground'}>
                    {
                        describeEvent({
                            id: 0,
                            kind: event.kind,
                            status: 'confirmed',
                            via: null,
                            uptime_before: null,
                            note: event.note,
                            occurred_at: event.occurred_at,
                        }).title
                    }
                    , {new Date(event.occurred_at).toLocaleString()}
                </li>
            ))}
        </ul>
    );
}
