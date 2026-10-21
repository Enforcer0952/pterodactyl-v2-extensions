import type { ReactNode } from 'react';
import { TitledGreyBox } from '@pterodactyl/sdk';
import { User } from 'lucide-react';
import type { PlayerSummary, PlayerView } from './api';
import { formatCoord, formatDate, formatDuration, formatNumber, percent, roman } from './format';

function Row({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className={'mcpd:flex mcpd:flex-col mcpd:gap-0.5 mcpd:py-1.5 mcpd:sm:flex-row mcpd:sm:gap-3'}>
            <dt className={'mcpd:w-32 mcpd:shrink-0 mcpd:text-xs mcpd:text-muted-foreground'}>{label}</dt>
            <dd className={'mcpd:min-w-0 mcpd:flex-1 mcpd:text-sm mcpd:text-foreground'}>{children}</dd>
        </div>
    );
}

function place(p: { x: number; y: number; z: number }): string {
    return `${formatCoord(p.x)}, ${formatCoord(p.y)}, ${formatCoord(p.z)}`;
}

export function Details({ data, saved }: { data: PlayerSummary; saved: PlayerView['saved'] }) {
    return (
        <TitledGreyBox icon={User} title={'Details'}>
            <dl className={'mcpd:divide-y mcpd:divide-border'}>
                <Row label={'Position'}>
                    {data.position ? (
                        <>
                            {place(data.position)}
                            {data.dimension && <span className={'mcpd:text-muted-foreground'}> in {data.dimension.name}</span>}
                        </>
                    ) : (
                        'Unknown'
                    )}
                </Row>
                <Row label={'Spawn point'}>
                    {data.spawn ? (
                        <>
                            {place(data.spawn)}
                            <span className={'mcpd:text-muted-foreground'}> in {data.spawn.dimension.name}</span>
                        </>
                    ) : (
                        <span className={'mcpd:text-muted-foreground'}>Not set (the world spawn is used)</span>
                    )}
                </Row>
                <Row label={'Experience'}>
                    {data.xp.level === null ? (
                        'Unknown'
                    ) : (
                        <>
                            Level {data.xp.level}
                            <span className={'mcpd:text-muted-foreground'}>
                                {' '}
                                · {percent(data.xp.progress)} to the next
                                {data.xp.total !== null ? ` · ${data.xp.total.toLocaleString()} points in total` : ''}
                            </span>
                        </>
                    )}
                </Row>
                <Row label={'Health'}>
                    {data.health === null ? 'Unknown' : `${formatNumber(data.health)} of ${formatNumber(data.max_health ?? 20)}`}
                </Row>
                <Row label={'Food'}>
                    {data.food.level === null ? (
                        'Unknown'
                    ) : (
                        <>
                            {data.food.level} of 20
                            {data.food.saturation !== null && (
                                <span className={'mcpd:text-muted-foreground'}> · saturation {formatNumber(data.food.saturation)}</span>
                            )}
                        </>
                    )}
                </Row>
                <Row label={'Game mode'}>{data.game_mode?.name ?? 'Unknown'}</Row>
                <Row label={'Effects'}>
                    {data.effects.length === 0 ? (
                        <span className={'mcpd:text-muted-foreground'}>None</span>
                    ) : (
                        <ul className={'mcpd:flex mcpd:flex-col mcpd:gap-0.5'}>
                            {data.effects.map((effect, index) => (
                                <li key={`${effect.id}-${index}`}>
                                    {effect.name} {roman(effect.level)}
                                    <span className={'mcpd:text-muted-foreground'}>
                                        {' '}
                                        ·{' '}
                                        {effect.infinite || effect.seconds === null ? 'infinite' : `${formatDuration(effect.seconds)} left`}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </Row>
                <Row label={'Last saved'}>{formatDate(saved)}</Row>
                {data.data_version !== null && <Row label={'Data version'}>{data.data_version}</Row>}
            </dl>
        </TitledGreyBox>
    );
}
