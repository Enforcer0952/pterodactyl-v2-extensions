import { useEffect, useState, type ReactNode } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
    Alert,
    Button,
    Checkbox,
    Dialog,
    PanelLink,
    Select,
    ServerContentBlock,
    SocketEvent,
    Spinner,
    Switch,
    TitledGreyBox,
    httpErrorToHuman,
    toast,
    useCurrentServerRequired,
    useSendServerPower,
    useServerWebsocketEvent,
} from '@pterodactyl/sdk';
import {
    applySwitch,
    getOverview,
    getPlan,
    getVersions,
    keys,
    rollback,
    setJava,
    type Change,
    type Overview,
    type Plan,
    type SoftwareId,
} from '../api';
import { JAVA_TEXT, formatBytes, formatWhen, javaName, keepSelection, sourceLabel, versionOptions } from '../format';

const TITLE = 'Version';

export default function VersionScreen() {
    const server = useCurrentServerRequired();
    const identifier = server.attributes.identifier;
    const queryClient = useQueryClient();
    const overview = useQuery({ queryKey: keys.overview(identifier), queryFn: () => getOverview(identifier), retry: false });

    // The power state follows the console; the rest of the page is read when asked for.
    const [live, setLive] = useState<string | null>(null);
    useEffect(() => setLive(null), [overview.data]);
    useServerWebsocketEvent(SocketEvent.STATUS, (status) => {
        setLive(status);
        if (status === 'offline') void queryClient.invalidateQueries({ queryKey: keys.overview(identifier) });
    });

    const [result, setResult] = useState<string[] | null>(null);
    const accept = (change: Change) => {
        queryClient.setQueryData(keys.overview(identifier), change.overview);
        setResult(change.notes);
    };

    if (overview.isLoading) {
        return (
            <ServerContentBlock title={TITLE}>
                <Spinner centered />
            </ServerContentBlock>
        );
    }

    if (overview.error || !overview.data) {
        return (
            <ServerContentBlock title={TITLE}>
                <Alert type={'danger'}>{httpErrorToHuman(overview.error)}</Alert>
            </ServerContentBlock>
        );
    }

    const data = overview.data;
    const state = live ?? data.state;

    return (
        <ServerContentBlock title={TITLE}>
            <div className={'mcver:flex mcver:flex-col mcver:gap-4'}>
                {state !== 'offline' && <StopNotice state={state} canStop={data.can.stop} uuid={server.attributes.uuid} />}

                {result !== null && (
                    <Alert type={'success'} title={'Done'}>
                        <p>The new jar is in place. Start the server to use it.</p>
                        {result.map((note) => (
                            <p key={note}>{note}</p>
                        ))}
                    </Alert>
                )}

                <CurrentCard data={data} />
                <SwitchCard identifier={identifier} data={data} state={state} onDone={accept} />
                <JavaCard identifier={identifier} data={data} />
                <CopiesCard identifier={identifier} data={data} state={state} onDone={accept} />
            </div>
        </ServerContentBlock>
    );
}

function StopNotice({ state, canStop, uuid }: { state: string; canStop: boolean; uuid: string }) {
    const power = useSendServerPower(uuid);
    const stopping = state === 'stopping';

    return (
        <Alert type={'warning'} title={'Stop the server first'}>
            <div className={'mcver:flex mcver:flex-wrap mcver:items-center mcver:justify-between mcver:gap-3'}>
                <p>The jar can only be replaced while the server is stopped. You can still look around and plan a change.</p>
                {canStop && (
                    <Button
                        size={'small'}
                        color={'red'}
                        isLoading={power.isPending}
                        disabled={stopping}
                        onClick={() =>
                            power.mutateAsync({ signal: 'stop' }).catch((error: unknown) => toast.error(httpErrorToHuman(error)))
                        }
                    >
                        {stopping ? 'Stopping' : 'Stop'}
                    </Button>
                )}
            </div>
        </Alert>
    );
}

function Row({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className={'mcver:flex mcver:flex-wrap mcver:items-baseline mcver:gap-x-3 mcver:gap-y-1'}>
            <dt className={'mcver:w-40 mcver:shrink-0 mcver:text-sm mcver:text-muted-foreground'}>{label}</dt>
            <dd className={'mcver:min-w-0 mcver:break-words mcver:text-sm'}>{children}</dd>
        </div>
    );
}

function Hint({ children }: { children: ReactNode }) {
    return <span className={'mcver:text-xs mcver:text-muted-foreground'}>{children}</span>;
}

function CurrentCard({ data }: { data: Overview }) {
    const { detected, pending, jar } = data;

    return (
        <TitledGreyBox title={'Current'}>
            <div className={'mcver:flex mcver:flex-col mcver:gap-3'}>
                <dl className={'mcver:flex mcver:flex-col mcver:gap-2'}>
                    <Row label={'Software'}>
                        {detected.software ? (
                            <>
                                {detected.label}
                                {detected.build ? ` (build ${detected.build})` : ''}
                            </>
                        ) : (
                            'Not detected'
                        )}
                    </Row>
                    <Row label={'Minecraft version'}>
                        {detected.version ? (
                            <>
                                {detected.version} <Hint>{sourceLabel(detected.source)}</Hint>
                            </>
                        ) : (
                            'Not detected'
                        )}
                    </Row>
                    <Row label={'Server jar'}>
                        {jar.exists ? (
                            <>
                                {jar.name} <Hint>{formatBytes(jar.size)}</Hint>
                            </>
                        ) : (
                            <>
                                {jar.name} <Hint>not found</Hint>
                            </>
                        )}
                    </Row>
                    {data.variables.map((variable) => (
                        <Row key={variable.name} label={variable.name}>
                            {variable.value === '' ? <Hint>empty</Hint> : variable.value} {!variable.editable && <Hint>read-only</Hint>}
                        </Row>
                    ))}
                </dl>

                {pending !== null && (
                    <Alert type={'info'}>
                        {pending.label} {pending.version ?? ''} is in place and has not been started yet. The details above are from the
                        last start.
                    </Alert>
                )}
                {detected.software === null && detected.version === null && (
                    <Alert type={'info'}>
                        Nothing tells what this server runs yet. Start it once and this page can read its version from the log.
                    </Alert>
                )}
                {data.mismatches.map((item) => (
                    <Alert key={item.code} type={item.severity === 'danger' ? 'danger' : 'warning'}>
                        {item.message}
                    </Alert>
                ))}
            </div>
        </TitledGreyBox>
    );
}

function SwitchCard({
    identifier,
    data,
    state,
    onDone,
}: {
    identifier: string;
    data: Overview;
    state: string;
    onDone: (change: Change) => void;
}) {
    const installable = data.software;
    const start = installable.find((item) => item.id === data.detected.software)?.id ?? 'paper';
    const [software, setSoftware] = useState<SoftwareId>(start);
    const [experimental, setExperimental] = useState(false);
    const [version, setVersion] = useState('');
    const [reviewing, setReviewing] = useState(false);

    const versions = useQuery({
        queryKey: keys.versions(identifier, software, experimental),
        queryFn: () => getVersions(identifier, software, experimental),
        staleTime: 5 * 60_000,
        retry: false,
    });

    // A version that the new list does not offer cannot stay chosen.
    useEffect(() => {
        if (versions.data) setVersion((current) => keepSelection(current, versions.data));
    }, [versions.data]);

    const current = data.pending?.version ?? data.detected.version;
    const options = versionOptions(versions.data ?? [], current);

    return (
        <TitledGreyBox title={'Switch software or version'}>
            <div className={'mcver:flex mcver:flex-col mcver:gap-4'}>
                <div className={'mcver:grid mcver:gap-3 mcver:sm:grid-cols-2'}>
                    <label className={'mcver:flex mcver:flex-col mcver:gap-1 mcver:text-xs mcver:text-muted-foreground'}>
                        Software
                        <Select
                            options={installable.map((item) => ({ value: item.id, label: item.label }))}
                            value={software}
                            onChange={(value) => {
                                setSoftware(String(value) as SoftwareId);
                                setVersion('');
                            }}
                        />
                    </label>
                    <label className={'mcver:flex mcver:flex-col mcver:gap-1 mcver:text-xs mcver:text-muted-foreground'}>
                        Minecraft version
                        <Select
                            options={options}
                            value={version === '' ? null : version}
                            placeholder={versions.isLoading ? 'Loading versions' : 'Pick a version'}
                            searchPlaceholder={'Search versions'}
                            disabled={versions.isLoading || !!versions.error}
                            onChange={(value) => setVersion(String(value))}
                        />
                    </label>
                </div>

                <Switch
                    checked={experimental}
                    onChange={setExperimental}
                    label={'Show snapshots and experimental builds'}
                    description={'Snapshots and release candidates can crash and may damage worlds. Off by default.'}
                />

                {versions.error && <Alert type={'danger'}>{httpErrorToHuman(versions.error)}</Alert>}

                <div className={'mcver:flex mcver:flex-wrap mcver:items-center mcver:gap-3'}>
                    <Button disabled={version === '' || !data.can.apply} onClick={() => setReviewing(true)}>
                        Review change
                    </Button>
                    {!data.can.apply ? (
                        <Hint>You need permission to update and create files and to change startup settings.</Hint>
                    ) : (
                        <Hint>
                            Nothing changes until you confirm.{' '}
                            <PanelLink
                                destination={{ to: '/server/$id/backups', params: { id: identifier } }}
                                className={'mcver:underline'}
                            >
                                Take a backup first
                            </PanelLink>
                            .
                        </Hint>
                    )}
                </div>
            </div>

            {reviewing && (
                <ReviewDialog
                    identifier={identifier}
                    software={software}
                    version={version}
                    experimental={experimental}
                    state={state}
                    onClose={() => setReviewing(false)}
                    onDone={(change) => {
                        setReviewing(false);
                        setVersion('');
                        onDone(change);
                    }}
                />
            )}
        </TitledGreyBox>
    );
}

function ReviewDialog({
    identifier,
    software,
    version,
    experimental,
    state,
    onClose,
    onDone,
}: {
    identifier: string;
    software: SoftwareId;
    version: string;
    experimental: boolean;
    state: string;
    onClose: () => void;
    onDone: (change: Change) => void;
}) {
    const plan = useQuery({
        queryKey: keys.plan(identifier, software, version, experimental),
        queryFn: () => getPlan(identifier, software, version, experimental),
        retry: false,
        staleTime: 0,
        gcTime: 0,
    });
    const [understood, setUnderstood] = useState(false);
    const [hasBackup, setHasBackup] = useState(false);
    const [withJava, setWithJava] = useState(true);

    const apply = useMutation({
        mutationFn: () =>
            applySwitch(identifier, {
                software,
                version,
                experimental,
                confirmed: understood,
                setJava: withJava,
                backupConfirmed: hasBackup,
            }),
        onSuccess: (change) => {
            toast.success(`Switched to ${plan.data?.target.label ?? software} ${version}.`);
            onDone(change);
        },
        onError: (error) => toast.error(httpErrorToHuman(error)),
    });

    const data = plan.data;
    const stopped = state === 'offline';
    const blocked = !data || (data.needs_confirmation && !understood) || (data.needs_backup && !hasBackup) || !stopped;

    return (
        <Dialog
            open
            title={data ? `Switch to ${data.target.label} ${data.target.version}?` : 'Review change'}
            onClose={apply.isPending ? () => undefined : onClose}
        >
            <div className={'mcver:flex mcver:flex-col mcver:gap-3'}>
                {plan.isLoading && <Spinner centered />}
                {plan.error && <Alert type={'danger'}>{httpErrorToHuman(plan.error)}</Alert>}
                {data && (
                    <PlanBody
                        plan={data}
                        withJava={withJava}
                        onWithJava={setWithJava}
                        understood={understood}
                        onUnderstood={setUnderstood}
                        hasBackup={hasBackup}
                        onHasBackup={setHasBackup}
                        identifier={identifier}
                    />
                )}
                {!stopped && <Alert type={'warning'}>Stop the server first.</Alert>}
            </div>
            <Dialog.Footer>
                <Button.Text type={'button'} disabled={apply.isPending} onClick={onClose}>
                    Cancel
                </Button.Text>
                <Button
                    type={'button'}
                    color={data?.needs_confirmation ? 'red' : 'primary'}
                    disabled={blocked}
                    isLoading={apply.isPending}
                    onClick={() => apply.mutate()}
                >
                    Switch
                </Button>
            </Dialog.Footer>
        </Dialog>
    );
}

function PlanBody({
    plan,
    withJava,
    onWithJava,
    understood,
    onUnderstood,
    hasBackup,
    onHasBackup,
    identifier,
}: {
    plan: Plan;
    withJava: boolean;
    onWithJava: (value: boolean) => void;
    understood: boolean;
    onUnderstood: (value: boolean) => void;
    hasBackup: boolean;
    onHasBackup: (value: boolean) => void;
    identifier: string;
}) {
    const { target } = plan;

    return (
        <>
            <ol className={'mcver:list-decimal mcver:space-y-1 mcver:pl-5 mcver:text-sm'}>
                {plan.keeps_copy && <li>{plan.jar} is kept as a copy next to it, so you can go back.</li>}
                <li>
                    {target.label} {target.version}
                    {target.build
                        ? ` (${target.channel === 'STABLE' || target.channel === '' ? 'build' : `${target.channel.toLowerCase()} build`} ${target.build})`
                        : ''}{' '}
                    is downloaded to {plan.jar}
                    {target.size ? ` (${formatBytes(target.size)})` : ''}.
                </li>
                {plan.variables.map((variable) => (
                    <li key={variable.name}>
                        {variable.name} changes from {variable.from === '' ? 'empty' : variable.from} to {variable.to}.
                    </li>
                ))}
                {plan.java.change && withJava && plan.java.recommended && <li>Java changes to {javaName(plan.java.recommended)}.</li>}
            </ol>

            <p className={'mcver:text-xs mcver:text-muted-foreground'}>{plan.verification.note}</p>

            {plan.warnings.map((warning) => (
                <Alert key={warning.code} type={warning.severity === 'info' ? 'info' : warning.severity}>
                    {warning.message}
                </Alert>
            ))}

            {plan.java.change && (
                <label className={'mcver:flex mcver:items-center mcver:gap-2 mcver:text-sm'}>
                    <Checkbox checked={withJava} onChange={onWithJava} aria-label={'Also set the Java version'} />
                    Also switch to {javaName(plan.java.recommended)}
                </label>
            )}
            {plan.java.recommended === null && (
                <Alert type={'warning'}>
                    This server&apos;s egg offers no Java version that fits {target.version}. Ask an administrator to add one.
                </Alert>
            )}

            <p className={'mcver:text-sm'}>
                A backup is the only way to undo changes to worlds and plugin data.{' '}
                <PanelLink destination={{ to: '/server/$id/backups', params: { id: identifier } }} className={'mcver:underline'}>
                    Open Backups
                </PanelLink>
            </p>

            {plan.needs_backup && (
                <label className={'mcver:flex mcver:items-start mcver:gap-2 mcver:text-sm'}>
                    <Checkbox checked={hasBackup} onChange={onHasBackup} aria-label={'I have a backup of my world'} />I have a backup of my
                    world.
                </label>
            )}

            {plan.needs_confirmation && (
                <label className={'mcver:flex mcver:items-start mcver:gap-2 mcver:text-sm'}>
                    <Checkbox checked={understood} onChange={onUnderstood} aria-label={'I understand the risks'} />I understand the risks
                    above and want to continue.
                </label>
            )}
        </>
    );
}

function JavaCard({ identifier, data }: { identifier: string; data: Overview }) {
    const queryClient = useQueryClient();
    const { java } = data;
    const recommended = java.recommended;

    const change = useMutation({
        mutationFn: (image: string) => setJava(identifier, image),
        onSuccess: (next) => {
            queryClient.setQueryData(keys.overview(identifier), next);
            toast.success('Java version changed. It applies the next time the server starts.');
        },
        onError: (error) => toast.error(httpErrorToHuman(error)),
    });

    const current = java.images.find((item) => item.image === java.image);
    const status = JAVA_TEXT[java.status];
    const canSwitch = data.can.java && !java.locked && recommended !== null && recommended.image !== java.image;

    return (
        <TitledGreyBox title={'Java'}>
            <div className={'mcver:flex mcver:flex-col mcver:gap-3'}>
                <dl className={'mcver:flex mcver:flex-col mcver:gap-2'}>
                    <Row label={'Docker image'}>
                        {current ? javaName(current) : java.java ? `Java ${java.java}` : 'Unknown'} <Hint>{java.image}</Hint>
                    </Row>
                    <Row label={`Recommended${java.for_version ? ` for ${java.for_version}` : ''}`}>
                        {recommended
                            ? javaName(recommended)
                            : java.for_version
                              ? 'No matching image in this egg'
                              : 'Needs a detected version'}
                    </Row>
                </dl>

                {java.for_version !== null && (
                    <Alert type={status.tone}>{status.text(java.java, recommended?.java ?? null, java.for_version)}</Alert>
                )}
                {java.locked && (
                    <Alert type={'info'}>An administrator set this server&apos;s Docker image by hand, so it cannot be changed here.</Alert>
                )}

                <div className={'mcver:flex mcver:flex-wrap mcver:items-center mcver:gap-3'}>
                    {canSwitch && (
                        <Button size={'small'} isLoading={change.isPending} onClick={() => recommended && change.mutate(recommended.image)}>
                            Switch to {javaName(recommended)}
                        </Button>
                    )}
                    {!data.can.java && <Hint>You need the permission to change the Docker image.</Hint>}
                    {canSwitch && <Hint>Takes effect the next time the server starts.</Hint>}
                </div>
            </div>
        </TitledGreyBox>
    );
}

function CopiesCard({
    identifier,
    data,
    state,
    onDone,
}: {
    identifier: string;
    data: Overview;
    state: string;
    onDone: (change: Change) => void;
}) {
    const [confirming, setConfirming] = useState(false);
    const [withJava, setWithJava] = useState(true);
    const newest = data.copies[0];

    const restore = useMutation({
        mutationFn: () => rollback(identifier, withJava),
        onSuccess: (change) => {
            toast.success('The previous jar is back in place.');
            setConfirming(false);
            onDone(change);
        },
        onError: (error) => toast.error(httpErrorToHuman(error)),
    });

    return (
        <TitledGreyBox title={'Previous version'}>
            <div className={'mcver:flex mcver:flex-col mcver:gap-3'}>
                {data.copies.length === 0 ? (
                    <p className={'mcver:text-sm mcver:text-muted-foreground'}>
                        No earlier jar yet. A copy is kept each time you switch, and the two newest are stored.
                    </p>
                ) : (
                    <ul
                        className={
                            'mcver:flex mcver:flex-col mcver:divide-y mcver:divide-border mcver:rounded-md mcver:border mcver:border-border'
                        }
                    >
                        {data.copies.map((copy, index) => (
                            <li
                                key={copy.name}
                                className={
                                    'mcver:flex mcver:flex-wrap mcver:items-center mcver:justify-between mcver:gap-2 mcver:px-3 mcver:py-2 mcver:text-sm'
                                }
                            >
                                <div className={'mcver:min-w-0'}>
                                    <p className={'mcver:break-all'}>{copy.name}</p>
                                    <Hint>
                                        {[
                                            copy.software && copy.version
                                                ? `${data.software.find((item) => item.id === copy.software)?.label ?? copy.software} ${copy.version}`
                                                : null,
                                            formatWhen(copy.created),
                                            formatBytes(copy.size),
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </Hint>
                                </div>
                                {index === 0 && data.can.rollback && (
                                    <Button size={'xsmall'} isSecondary disabled={state !== 'offline'} onClick={() => setConfirming(true)}>
                                        Restore
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
                {newest && state !== 'offline' && <Hint>Stop the server to restore a copy.</Hint>}
            </div>

            {confirming && newest && (
                <Dialog open title={'Restore the previous jar?'} onClose={restore.isPending ? () => undefined : () => setConfirming(false)}>
                    <div className={'mcver:flex mcver:flex-col mcver:gap-3 mcver:text-sm'}>
                        <p>
                            {newest.name} becomes {data.jar.name} again
                            {restoredLabel(data, newest) ? `, which is ${restoredLabel(data, newest)}` : ''}. The jar in use now is kept as
                            a copy, so you can switch back.
                        </p>
                        <p>The startup variables this page changed are set back to their old values.</p>
                        <Alert type={'warning'}>
                            Worlds saved by a newer version may not open in an older one. Restore a backup of the world if the version is
                            older.
                        </Alert>
                        <label className={'mcver:flex mcver:items-center mcver:gap-2'}>
                            <Checkbox checked={withJava} onChange={setWithJava} aria-label={'Also restore the Java version'} />
                            Also restore the Java version it used
                        </label>
                    </div>
                    <Dialog.Footer>
                        <Button.Text type={'button'} disabled={restore.isPending} onClick={() => setConfirming(false)}>
                            Cancel
                        </Button.Text>
                        <Button.Danger
                            type={'button'}
                            isLoading={restore.isPending}
                            disabled={state !== 'offline'}
                            onClick={() => restore.mutate()}
                        >
                            Restore
                        </Button.Danger>
                    </Dialog.Footer>
                </Dialog>
            )}
        </TitledGreyBox>
    );
}

/** "Paper 1.21.4" for a copy whose note says what it was, an empty string when it does not. */
function restoredLabel(data: Overview, copy: Overview['copies'][number]): string {
    const software = data.software.find((item) => item.id === copy.software)?.label ?? copy.software ?? '';
    return [software, copy.version ?? ''].filter(Boolean).join(' ');
}
