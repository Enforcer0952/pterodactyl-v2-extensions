import { useState } from 'react';
import { Button, Input, Select, TitledGreyBox } from '@pterodactyl/sdk';
import { Wrench } from 'lucide-react';
import type { EditRequest, PlayerSummary } from './api';
import { formatCoord, parseCoordinate } from './format';

const MODES = [
    { value: 0, label: 'Survival' },
    { value: 1, label: 'Creative' },
    { value: 2, label: 'Adventure' },
    { value: 3, label: 'Spectator' },
];

const DIMENSIONS = [
    { value: 'minecraft:overworld', label: 'Overworld' },
    { value: 'minecraft:the_nether', label: 'The Nether' },
    { value: 'minecraft:the_end', label: 'The End' },
];

const LIMIT = 30_000_000;

/** Checks a teleport the way the server will, so a typo is caught before the request. */
export function teleportRequest(x: string, y: string, z: string, dimension: string): EditRequest | string {
    const nx = parseCoordinate(x);
    const ny = parseCoordinate(y);
    const nz = parseCoordinate(z);
    if (nx === null || ny === null || nz === null) return 'Enter X, Y and Z as numbers.';
    if (Math.abs(nx) > LIMIT || Math.abs(nz) > LIMIT) return 'X and Z must be between -30000000 and 30000000.';
    if (ny < -2048 || ny > 4064) return 'Y must be between -2048 and 4064.';
    return { action: 'teleport', x: nx, y: ny, z: nz, dimension };
}

export function Actions({
    data,
    busy,
    onRun,
    onConfirm,
}: {
    data: PlayerSummary;
    busy: boolean;
    /** Runs an edit at once. */
    onRun: (request: EditRequest, done: string) => void;
    /** Asks first, for edits that delete things. */
    onConfirm: (request: EditRequest, title: string, body: string, confirm: string) => void;
}) {
    const [mode, setMode] = useState<number>(data.game_mode?.id ?? 0);
    const [x, setX] = useState(data.position ? formatCoord(data.position.x) : '0');
    const [y, setY] = useState(data.position ? formatCoord(data.position.y) : '64');
    const [z, setZ] = useState(data.position ? formatCoord(data.position.z) : '0');
    const [dimension, setDimension] = useState(data.dimension?.id ?? 'minecraft:overworld');
    const [problem, setProblem] = useState<string | null>(null);

    const dimensions = DIMENSIONS.some((option) => option.value === dimension)
        ? DIMENSIONS
        : [...DIMENSIONS, { value: dimension, label: data.dimension?.name ?? dimension }];

    const teleport = () => {
        const request = teleportRequest(x, y, z, dimension);
        if (typeof request === 'string') {
            setProblem(request);
            return;
        }
        setProblem(null);
        onRun(request, 'Player moved.');
    };

    return (
        <TitledGreyBox icon={Wrench} title={'Edit'}>
            <div className={'mcpd:flex mcpd:flex-col mcpd:gap-4'}>
                <p className={'mcpd:text-xs mcpd:text-muted-foreground'}>
                    The player file is copied aside before every change, and the three newest copies are kept.
                </p>

                <section className={'mcpd:flex mcpd:flex-col mcpd:gap-1.5'}>
                    <h3 className={'mcpd:text-sm mcpd:font-semibold mcpd:text-foreground'}>Clear</h3>
                    <div className={'mcpd:flex mcpd:flex-wrap mcpd:gap-1.5'}>
                        <Button.Danger
                            size={'xsmall'}
                            isSecondary
                            disabled={busy}
                            onClick={() =>
                                onConfirm(
                                    { action: 'clear-inventory' },
                                    'Clear the inventory?',
                                    'Everything this player carries is removed: inventory, hotbar, armor and off hand.',
                                    'Clear inventory',
                                )
                            }
                        >
                            Clear inventory
                        </Button.Danger>
                        <Button.Danger
                            size={'xsmall'}
                            isSecondary
                            disabled={busy}
                            onClick={() =>
                                onConfirm(
                                    { action: 'clear-ender' },
                                    'Clear the ender chest?',
                                    "Everything in this player's ender chest is removed.",
                                    'Clear ender chest',
                                )
                            }
                        >
                            Clear ender chest
                        </Button.Danger>
                        <Button.Danger
                            size={'xsmall'}
                            isSecondary
                            disabled={busy || data.effects.length === 0}
                            onClick={() =>
                                onConfirm(
                                    { action: 'clear-effects' },
                                    'Clear all effects?',
                                    'Every active effect is removed from this player.',
                                    'Clear effects',
                                )
                            }
                        >
                            Clear effects
                        </Button.Danger>
                    </div>
                </section>

                <section className={'mcpd:flex mcpd:flex-col mcpd:gap-1.5'}>
                    <h3 className={'mcpd:text-sm mcpd:font-semibold mcpd:text-foreground'}>Game mode</h3>
                    <div className={'mcpd:flex mcpd:flex-wrap mcpd:items-center mcpd:gap-2'}>
                        <div className={'mcpd:w-44'}>
                            <Select options={MODES} value={mode} disabled={busy} onChange={(value) => setMode(Number(value))} />
                        </div>
                        <Button
                            size={'xsmall'}
                            isSecondary
                            disabled={busy || mode === data.game_mode?.id}
                            onClick={() => onRun({ action: 'game-mode', mode }, 'Game mode changed.')}
                        >
                            Set game mode
                        </Button>
                    </div>
                </section>

                <section className={'mcpd:flex mcpd:flex-col mcpd:gap-1.5'}>
                    <h3 className={'mcpd:text-sm mcpd:font-semibold mcpd:text-foreground'}>Move</h3>
                    <p className={'mcpd:text-xs mcpd:text-muted-foreground'}>
                        The player also stops riding anything they were on and loses any fall in progress.
                    </p>
                    <div className={'mcpd:flex mcpd:flex-wrap mcpd:items-end mcpd:gap-2'}>
                        {[
                            ['X', x, setX],
                            ['Y', y, setY],
                            ['Z', z, setZ],
                        ].map(([label, value, set]) => (
                            <label
                                key={label as string}
                                className={'mcpd:flex mcpd:w-24 mcpd:flex-col mcpd:gap-1 mcpd:text-xs mcpd:text-muted-foreground'}
                            >
                                {label as string}
                                <Input
                                    value={value as string}
                                    inputMode={'decimal'}
                                    disabled={busy}
                                    $hasError={problem !== null}
                                    onChange={(event) => (set as (value: string) => void)(event.target.value)}
                                />
                            </label>
                        ))}
                        <div className={'mcpd:flex mcpd:w-40 mcpd:flex-col mcpd:gap-1 mcpd:text-xs mcpd:text-muted-foreground'}>
                            Dimension
                            <Select
                                options={dimensions}
                                value={dimension}
                                disabled={busy}
                                onChange={(value) => setDimension(String(value))}
                            />
                        </div>
                    </div>
                    {problem && <p className={'mcpd:text-xs mcpd:text-destructive'}>{problem}</p>}
                    <div className={'mcpd:flex mcpd:flex-wrap mcpd:gap-1.5'}>
                        <Button size={'xsmall'} isSecondary disabled={busy} onClick={teleport}>
                            Move to these coordinates
                        </Button>
                        <Button
                            size={'xsmall'}
                            isSecondary
                            disabled={busy}
                            onClick={() => onRun({ action: 'teleport-spawn' }, 'Player moved to the world spawn.')}
                        >
                            Move to world spawn
                        </Button>
                    </div>
                </section>
            </div>
        </TitledGreyBox>
    );
}
