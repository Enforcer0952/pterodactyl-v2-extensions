import type { ReactNode } from 'react';
import { levelFor, percentOf } from './format';
import type { ProbeState } from './api';

const BAR: Record<ReturnType<typeof levelFor>, string> = {
    success: 'nr:bg-success',
    warning: 'nr:bg-warning',
    destructive: 'nr:bg-destructive',
    muted: 'nr:bg-muted-foreground/40',
};

/** A thin bar. `part` of `whole`; coloured by the panel's usage thresholds. */
export function Meter({ label, part, whole, text }: { label: string; part: number | null; whole: number | null; text: ReactNode }) {
    const percent = percentOf(part, whole);
    const level = levelFor(percent);

    return (
        <div className={'nr:flex nr:flex-col nr:gap-1'}>
            <div className={'nr:flex nr:justify-between nr:gap-2 nr:text-xs'}>
                <span className={'nr:text-muted-foreground'}>{label}</span>
                <span className={'nr:text-foreground'}>{text}</span>
            </div>
            <div
                role={'progressbar'}
                aria-label={label}
                aria-valuemin={0}
                aria-valuemax={100}
                aria-valuenow={percent === null ? undefined : Math.round(Math.min(100, percent))}
                className={'nr:h-2 nr:w-full nr:overflow-hidden nr:rounded-full nr:bg-muted'}
            >
                <div className={`nr:h-full nr:rounded-full ${BAR[level]}`} style={{ width: `${Math.min(100, percent ?? 0)}%` }} />
            </div>
        </div>
    );
}

/** A number with a caption above and a note below. */
export function Stat({
    label,
    value,
    note,
    tone,
}: {
    label: string;
    value: ReactNode;
    note?: ReactNode;
    tone?: 'warning' | 'destructive';
}) {
    return (
        <div className={'nr:flex nr:min-w-0 nr:flex-col'}>
            <span className={'nr:text-xs nr:text-muted-foreground'}>{label}</span>
            <span
                className={`nr:text-lg nr:font-semibold ${tone === 'destructive' ? 'nr:text-destructive' : tone === 'warning' ? 'nr:text-warning' : 'nr:text-foreground'}`}
            >
                {value}
            </span>
            {note && <span className={'nr:text-xs nr:text-muted-foreground'}>{note}</span>}
        </div>
    );
}

/** What the probe is doing, in a few words. */
export function probeText(state: ProbeState): string {
    switch (state) {
        case 'none':
            return 'No probe';
        case 'installing':
            return 'Probe installing';
        case 'failed':
            return 'Probe install failed';
        case 'suspended':
            return 'Probe suspended';
        case 'waiting':
            return 'Probe starting';
        case 'starting':
            return 'Probe starting';
        case 'ok':
            return 'Probe running';
        case 'stale':
            return 'Probe quiet';
        case 'offline':
            return 'Probe stopped';
        case 'unreachable':
            return 'Node not reachable';
    }
}
