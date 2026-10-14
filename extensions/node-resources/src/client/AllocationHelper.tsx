import { useEffect, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { TitledGreyBox } from '@pterodactyl/sdk';
import { getCapacity, getNodes, getServerCapacity, keys, type NodeReport } from './api';
import { checkFit, formatBytes, type FitProblem } from './format';

/** The number typed into one of the panel's own form fields, or null when empty. */
export function readField(id: string, root: Pick<Document, 'getElementById'> = document): number | null {
    const found = root.getElementById(id);
    const input = found instanceof HTMLInputElement ? found : found?.querySelector('input');
    if (!input || input.value.trim() === '') return null;
    const value = Number(input.value);
    return Number.isFinite(value) ? value : null;
}

/** Follow the memory and disk fields of the panel's form; they live outside this component. */
function useTypedLimits(): { memory: number | null; disk: number | null } {
    const [typed, setTyped] = useState({ memory: readField('memory'), disk: readField('disk') });

    useEffect(() => {
        const read = () => {
            const next = { memory: readField('memory'), disk: readField('disk') };
            setTyped((old) => (old.memory === next.memory && old.disk === next.disk ? old : next));
        };
        read();
        const timer = window.setInterval(read, 500);
        return () => window.clearInterval(timer);
    }, []);

    return typed;
}

function Room({
    report,
    current,
    typed,
}: {
    report: NodeReport;
    current: { memory: number; disk: number };
    typed: { memory: number | null; disk: number | null };
}) {
    const problems: FitProblem[] = [
        ...(typed.memory === null ? [] : checkFit('memory', typed.memory, current.memory, report.ram)),
        ...(typed.disk === null ? [] : checkFit('disk', typed.disk, current.disk, report.disk)),
    ];

    return (
        <div className={'nr:flex nr:flex-col nr:gap-3'}>
            <div className={'nr:grid nr:grid-cols-2 nr:gap-3 nr:text-sm'}>
                <div>
                    <div className={'nr:text-xs nr:text-muted-foreground'}>Memory</div>
                    <div className={'nr:text-foreground'}>{formatBytes(report.ram.unallocated)} left to give out</div>
                    <div className={'nr:text-xs nr:text-muted-foreground'}>
                        {report.ram.host_free === null ? 'Free on host unknown' : `${formatBytes(report.ram.host_free)} free on host`}
                    </div>
                </div>
                <div>
                    <div className={'nr:text-xs nr:text-muted-foreground'}>Disk</div>
                    <div className={'nr:text-foreground'}>{formatBytes(report.disk.unallocated)} left to give out</div>
                    <div className={'nr:text-xs nr:text-muted-foreground'}>
                        {report.disk.host_free === null
                            ? 'Set up probe to see free disk'
                            : `${formatBytes(report.disk.host_free)} free on host`}
                    </div>
                </div>
            </div>
            {problems.length > 0 && (
                <ul
                    role={'alert'}
                    className={
                        'nr:flex nr:flex-col nr:gap-1 nr:rounded-sm nr:border nr:border-warning/40 nr:bg-warning/10 nr:px-3 nr:py-2 nr:text-sm nr:text-warning'
                    }
                >
                    {problems.map((problem) => (
                        <li key={problem.id + problem.text}>{problem.text}</li>
                    ))}
                </ul>
            )}
            {problems.length === 0 && (typed.memory !== null || typed.disk !== null) && (
                <p className={'nr:text-xs nr:text-muted-foreground'}>These values fit on this node.</p>
            )}
        </div>
    );
}

/** The create form: pick the node, the form values are read from the page. */
function CreateHelper() {
    const nodes = useQuery({ queryKey: keys.nodes, queryFn: getNodes });
    const [nodeId, setNodeId] = useState<number | null>(null);
    const typed = useTypedLimits();
    const capacity = useQuery({
        queryKey: nodeId === null ? ['node-resources', 'none'] : keys.capacity(nodeId),
        queryFn: () => getCapacity(nodeId as number),
        enabled: nodeId !== null,
        refetchInterval: 60_000,
    });

    // The panel's node picker is not a plain input; when its label names a node, follow it.
    useEffect(() => {
        const follow = () => {
            const text = document.getElementById('nodeId')?.textContent?.trim();
            const match = nodes.data?.find((node) => node.name === text);
            if (match) setNodeId((old) => (old === match.id ? old : match.id));
        };
        follow();
        const timer = window.setInterval(follow, 700);
        return () => window.clearInterval(timer);
    }, [nodes.data]);

    return (
        <div className={'nr:flex nr:flex-col nr:gap-3'}>
            <label className={'nr:flex nr:flex-col nr:gap-1 nr:text-sm'}>
                <span className={'nr:text-xs nr:text-muted-foreground'}>Check against node</span>
                <select
                    value={nodeId ?? ''}
                    onChange={(event) => setNodeId(event.target.value === '' ? null : Number(event.target.value))}
                    className={'nr:rounded-sm nr:border nr:border-border nr:bg-background nr:px-2 nr:py-1.5 nr:text-foreground'}
                >
                    <option value={''}>Select a node</option>
                    {(nodes.data ?? []).map((node) => (
                        <option key={node.id} value={node.id}>
                            {node.name}
                        </option>
                    ))}
                </select>
            </label>
            {capacity.data ? (
                <Room report={capacity.data} current={{ memory: 0, disk: 0 }} typed={typed} />
            ) : (
                <p className={'nr:text-xs nr:text-muted-foreground'}>Pick a node to see how much room it has.</p>
            )}
        </div>
    );
}

/** The build page of an existing server. */
function BuildHelper({ id }: { id: number }) {
    const typed = useTypedLimits();
    const query = useQuery({ queryKey: keys.serverCapacity(id), queryFn: () => getServerCapacity(id), refetchInterval: 60_000 });
    if (!query.data) return <p className={'nr:text-xs nr:text-muted-foreground'}>Checking the node.</p>;

    return <Room report={query.data.report} current={{ memory: query.data.server.memory, disk: query.data.server.disk }} typed={typed} />;
}

/** Slot component for `panel.servers.create.after` and `panel.servers.detail.build.before`. */
export default function AllocationSlot({ data }: { data: { params: Record<string, string> } }) {
    const id = Number(data.params.id);

    return (
        <TitledGreyBox title={'Room on the node'}>
            {Number.isInteger(id) && id > 0 ? <BuildHelper id={id} /> : <CreateHelper />}
        </TitledGreyBox>
    );
}
