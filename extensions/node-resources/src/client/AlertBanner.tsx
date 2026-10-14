import { useQuery } from '@tanstack/react-query';
import { PanelLink } from '@pterodactyl/sdk';
import { getAlerts, keys } from './api';

/** Shown above the admin overview and node list when a node needs attention. */
export default function AlertBanner() {
    const query = useQuery({ queryKey: keys.alerts, queryFn: getAlerts, refetchInterval: 120_000, retry: false });
    const warnings = query.data?.warnings ?? [];
    if (warnings.length === 0) return null;

    return (
        <section aria-label={'Node resource warnings'} className={'nr:mb-4 nr:flex nr:flex-col nr:gap-2'}>
            {warnings.map((warning) => (
                <div
                    key={`${warning.node.id}-${warning.id}`}
                    role={'alert'}
                    className={`nr:flex nr:flex-wrap nr:items-center nr:justify-between nr:gap-2 nr:rounded-sm nr:border nr:px-3 nr:py-2 nr:text-sm ${
                        warning.level === 'danger'
                            ? 'nr:border-destructive/40 nr:bg-destructive/10 nr:text-destructive'
                            : 'nr:border-warning/40 nr:bg-warning/10 nr:text-warning'
                    }`}
                >
                    <span>
                        <strong>{warning.node.name}:</strong> {warning.text}
                    </span>
                    <PanelLink
                        destination={{ extension: 'node-resources', screen: 'node', params: { id: String(warning.node.id) } }}
                        className={'nr:underline'}
                    >
                        Open
                    </PanelLink>
                </div>
            ))}
        </section>
    );
}
