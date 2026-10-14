import { useEffect, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { ServerContentBlock, useCurrentServerRequired } from '@pterodactyl/sdk';
import { keys, markViewed } from '../api';
import LogsPanel from '../LogsPanel';
import ReportsPanel from '../ReportsPanel';

type Tab = 'reports' | 'logs';

const TABS: { id: Tab; label: string }[] = [
    { id: 'reports', label: 'Crash reports' },
    { id: 'logs', label: 'Log errors' },
];

export default function CrashesScreen() {
    const server = useCurrentServerRequired();
    const identifier = server.attributes.identifier;
    const queryClient = useQueryClient();
    const [tab, setTab] = useState<Tab>('reports');

    // Opening the page counts as looking: the badge on the console page starts from now.
    useEffect(() => {
        markViewed(identifier)
            .then(() =>
                queryClient.setQueryData(keys.status(identifier), (current: object | undefined) =>
                    current ? { ...current, new: 0 } : current,
                ),
            )
            .catch(() => undefined);
    }, [identifier, queryClient]);

    return (
        <ServerContentBlock title={'Crashes'}>
            <div className={'mccr:mx-auto mccr:flex mccr:max-w-4xl mccr:flex-col mccr:gap-4'}>
                <p className={'mccr:text-sm mccr:text-muted-foreground'}>
                    Each crash report and repeating error gets one line: what broke, which plugin or mod did it, and how to fix it.
                </p>
                <div className={'mccr:flex mccr:flex-wrap mccr:items-center mccr:gap-2'} role={'tablist'}>
                    {TABS.map((item) => (
                        <button
                            key={item.id}
                            type={'button'}
                            role={'tab'}
                            aria-selected={tab === item.id}
                            onClick={() => setTab(item.id)}
                            className={`mccr:rounded-md mccr:px-3 mccr:py-1.5 mccr:text-sm ${
                                tab === item.id
                                    ? 'mccr:bg-muted mccr:font-medium mccr:text-foreground'
                                    : 'mccr:text-muted-foreground mccr:hover:text-foreground'
                            }`}
                        >
                            {item.label}
                        </button>
                    ))}
                </div>
                {tab === 'reports' ? <ReportsPanel identifier={identifier} /> : <LogsPanel identifier={identifier} />}
            </div>
        </ServerContentBlock>
    );
}
