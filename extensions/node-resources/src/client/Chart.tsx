import { useState } from 'react';
import { axisTime } from './format';
import { buildPaths, nearestIndex, type Point } from './chart';

export interface Series {
    id: string;
    label: string;
    /** A panel colour token, e.g. `--chart-1`. */
    token: string;
    points: Point[];
    area?: boolean;
}

interface Props {
    title: string;
    series: Series[];
    /** Start and end of the time axis, in seconds. */
    from: number;
    to: number;
    /** The top of the vertical axis. */
    max: number;
    format: (value: number) => string;
    /** A line across the chart, such as the size of the disk. */
    limit?: { value: number; label: string } | null;
    /** Seconds between points; a hole wider than twice this breaks the line. */
    step: number;
    note?: string;
    empty?: string;
}

const WIDTH = 600;
const HEIGHT = 120;

/** A small line/area chart drawn in SVG with the panel's colours. */
export default function Chart({ title, series, from, to, max, format, limit, step, note, empty = 'No data in this range yet.' }: Props) {
    const [hover, setHover] = useState<number | null>(null);
    const hasData = series.some((item) => item.points.some((point) => point.v !== null));
    const drawn = series.map((item) => ({ item, paths: buildPaths(item.points, from, to, max, WIDTH, HEIGHT, Math.max(step * 2, 600)) }));
    const primary = series[0];
    const latest = primary ? [...primary.points].reverse().find((point) => point.v !== null) : undefined;

    const at = hover === null ? null : Math.min(1, Math.max(0, hover));
    const nearest = (points: Point[]) => (at === null ? -1 : nearestIndex(points, at, from, to));
    const hoverTime = at === null ? null : from + at * (to - from);

    return (
        <div className={'nr:flex nr:flex-col nr:gap-1'}>
            <div className={'nr:flex nr:flex-wrap nr:items-baseline nr:justify-between nr:gap-2'}>
                <span className={'nr:text-sm nr:font-medium nr:text-foreground'}>{title}</span>
                <span className={'nr:flex nr:flex-wrap nr:items-center nr:gap-3 nr:text-xs nr:text-muted-foreground'}>
                    {series.map((item) => (
                        <span key={item.id} className={'nr:inline-flex nr:items-center nr:gap-1'}>
                            <span
                                aria-hidden
                                className={'nr:inline-block nr:h-2 nr:w-2 nr:rounded-full'}
                                style={{ background: `var(${item.token})` }}
                            />
                            {item.label}
                        </span>
                    ))}
                    {latest && latest.v !== null && <span className={'nr:text-foreground'}>{format(latest.v)}</span>}
                </span>
            </div>

            {!hasData ? (
                <p
                    className={
                        'nr:flex nr:h-28 nr:items-center nr:justify-center nr:rounded-sm nr:bg-muted/40 nr:text-xs nr:text-muted-foreground'
                    }
                >
                    {empty}
                </p>
            ) : (
                <div className={'nr:flex nr:gap-2'}>
                    <div
                        className={
                            'nr:flex nr:w-14 nr:shrink-0 nr:flex-col nr:justify-between nr:py-0.5 nr:text-right nr:text-[10px] nr:text-muted-foreground'
                        }
                    >
                        <span>{format(max)}</span>
                        <span>{format(0)}</span>
                    </div>
                    <div className={'nr:flex nr:min-w-0 nr:flex-1 nr:flex-col'}>
                        <div
                            className={'nr:relative'}
                            onMouseMove={(event) => {
                                const box = event.currentTarget.getBoundingClientRect();
                                setHover(box.width > 0 ? (event.clientX - box.left) / box.width : null);
                            }}
                            onMouseLeave={() => setHover(null)}
                        >
                            <svg
                                role={'img'}
                                aria-label={`${title}, ${series.map((item) => item.label).join(' and ')}`}
                                viewBox={`0 0 ${WIDTH} ${HEIGHT}`}
                                preserveAspectRatio={'none'}
                                className={'nr:block nr:h-28 nr:w-full nr:overflow-visible'}
                            >
                                {[0.25, 0.5, 0.75, 1].map((fraction) => (
                                    <line
                                        key={fraction}
                                        x1={0}
                                        x2={WIDTH}
                                        y1={HEIGHT * fraction}
                                        y2={HEIGHT * fraction}
                                        style={{ stroke: 'var(--border)', vectorEffect: 'non-scaling-stroke' }}
                                        strokeWidth={1}
                                        strokeDasharray={fraction === 1 ? undefined : '2 4'}
                                    />
                                ))}
                                {limit && limit.value > 0 && limit.value <= max && (
                                    <line
                                        x1={0}
                                        x2={WIDTH}
                                        y1={HEIGHT - (limit.value / max) * HEIGHT}
                                        y2={HEIGHT - (limit.value / max) * HEIGHT}
                                        style={{ stroke: 'var(--destructive)', vectorEffect: 'non-scaling-stroke' }}
                                        strokeWidth={1}
                                        strokeDasharray={'6 4'}
                                    />
                                )}
                                {drawn.map(({ item, paths }) => (
                                    <g key={item.id}>
                                        {item.area && paths.area && (
                                            <path d={paths.area} style={{ fill: `var(${item.token})`, opacity: 0.15 }} />
                                        )}
                                        {paths.line && (
                                            <path
                                                d={paths.line}
                                                fill={'none'}
                                                strokeWidth={2}
                                                strokeLinejoin={'round'}
                                                style={{ stroke: `var(${item.token})`, vectorEffect: 'non-scaling-stroke' }}
                                            />
                                        )}
                                    </g>
                                ))}
                            </svg>
                            {at !== null && hoverTime !== null && (
                                <>
                                    <div
                                        aria-hidden
                                        className={'nr:pointer-events-none nr:absolute nr:inset-y-0 nr:w-px nr:bg-muted-foreground/60'}
                                        style={{ left: `${at * 100}%` }}
                                    />
                                    <div
                                        role={'tooltip'}
                                        className={
                                            'nr:pointer-events-none nr:absolute nr:top-0 nr:z-10 nr:rounded-sm nr:border nr:border-border nr:bg-popover nr:px-2 nr:py-1 nr:text-xs nr:text-foreground nr:shadow-md'
                                        }
                                        style={at > 0.6 ? { right: `${(1 - at) * 100}%` } : { left: `${at * 100}%` }}
                                    >
                                        <div className={'nr:text-muted-foreground'}>
                                            {new Date(hoverTime * 1000).toLocaleString([], { dateStyle: 'short', timeStyle: 'short' })}
                                        </div>
                                        {series.map((item) => {
                                            const point = item.points[nearest(item.points)];
                                            return (
                                                <div key={item.id} className={'nr:flex nr:justify-between nr:gap-3'}>
                                                    <span style={{ color: `var(${item.token})` }}>{item.label}</span>
                                                    <span>{point && point.v !== null ? format(point.v) : '-'}</span>
                                                </div>
                                            );
                                        })}
                                    </div>
                                </>
                            )}
                        </div>
                        <div className={'nr:flex nr:justify-between nr:text-[10px] nr:text-muted-foreground'}>
                            <span>{axisTime(from, to - from)}</span>
                            <span>{axisTime(to, to - from)}</span>
                        </div>
                    </div>
                </div>
            )}
            {note && <p className={'nr:text-xs nr:text-muted-foreground'}>{note}</p>}
        </div>
    );
}
