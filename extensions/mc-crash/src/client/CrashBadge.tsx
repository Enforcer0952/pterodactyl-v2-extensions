import { useQuery } from '@tanstack/react-query';
import { PanelLink, useServerPermission, type SdkServer } from '@pterodactyl/sdk';
import { TriangleAlert } from 'lucide-react';
import { getStatus, keys } from './api';
import { formatWhen, isJavaOrProxy } from './logic';

/** Console slot: a small notice while there is a crash newer than the last visit to the Crashes page. */
export default function CrashBadgeSlot({ data: server }: { data: SdkServer }) {
    const canView = useServerPermission('ext.mc-crash.view');
    const canRead = useServerPermission('file.read-content');

    if (!canView || !canRead || !isJavaOrProxy(server.attributes.egg_tags, server.attributes.egg_features)) {
        return null;
    }

    return <CrashBadge identifier={server.attributes.identifier} />;
}

export function CrashBadge({ identifier }: { identifier: string }) {
    const status = useQuery({
        queryKey: keys.status(identifier),
        queryFn: () => getStatus(identifier),
        staleTime: 60_000,
        refetchInterval: 120_000,
        retry: false,
    });

    const count = status.data?.new ?? 0;
    if (count < 1) {
        return null;
    }

    return (
        <div
            role={'status'}
            className={
                'mccr:flex mccr:flex-wrap mccr:items-center mccr:gap-2 mccr:rounded-md mccr:border mccr:border-destructive/50 mccr:bg-destructive/10 mccr:px-3 mccr:py-2 mccr:text-sm'
            }
        >
            <TriangleAlert className={'mccr:size-4 mccr:text-destructive'} aria-hidden />
            <span className={'mccr:text-foreground'}>
                {count === 1 ? 'The server crashed' : `${count} new crashes`}
                {status.data?.latest && <span className={'mccr:text-muted-foreground'}> · {formatWhen(status.data.latest)}</span>}
            </span>
            <PanelLink
                destination={{ extension: 'mc-crash', screen: 'crashes', params: { id: identifier } }}
                className={'mccr:text-primary mccr:hover:underline'}
            >
                See what happened
            </PanelLink>
        </div>
    );
}
