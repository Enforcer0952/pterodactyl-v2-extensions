import { Component, useEffect, useState, type ReactNode } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import {
    Alert,
    Input,
    PanelLink,
    ServerContentBlock,
    Spinner,
    Switch,
    Button,
    TitledGreyBox,
    httpErrorToHuman,
    toast,
    useCurrentServerRequired,
    useServerPermission,
} from '@pterodactyl/sdk';
import { LIMITS, keys, saveGuard, type Guard, type GuardEvent } from '../api';
import { TrippedNotice, useGuard } from '../GuardBanner';
import { clampInt, describeEvent, isMinecraft } from '../format';

export default function GuardScreen() {
    const server = useCurrentServerRequired();
    const identifier = server.attributes.identifier;
    const guard = useGuard(identifier);
    const minecraft = isMinecraft(server.attributes.egg_tags, server.attributes.egg_features);

    return (
        <ServerContentBlock title={'Crash Guard'}>
            <div className={'cg:flex cg:flex-col cg:gap-4'}>
                {guard.isLoading && (
                    <div className={'cg:flex cg:justify-center cg:py-8'}>
                        <Spinner size={'large'} />
                    </div>
                )}
                {guard.isError && <Alert type={'danger'}>{httpErrorToHuman(guard.error)}</Alert>}
                {guard.data && (
                    <>
                        {guard.data.state === 'tripped' && <TrippedNotice identifier={identifier} guard={guard.data} />}
                        <SettingsCard identifier={identifier} guard={guard.data} />
                        <LogCard identifier={identifier} events={guard.data.events} link={minecraft && guard.data.mc_crash} />
                    </>
                )}
            </div>
        </ServerContentBlock>
    );
}

function SettingsCard({ identifier, guard }: { identifier: string; guard: Guard }) {
    const queryClient = useQueryClient();
    const canManage = useServerPermission('ext.crash-guard.manage');
    const [enabled, setEnabled] = useState(guard.enabled);
    const [max, setMax] = useState(String(guard.max_crashes));
    const [minutes, setMinutes] = useState(String(guard.window_minutes));

    useEffect(() => {
        setEnabled(guard.enabled);
        setMax(String(guard.max_crashes));
        setMinutes(String(guard.window_minutes));
    }, [guard.enabled, guard.max_crashes, guard.window_minutes]);

    const maxValue = clampInt(max, LIMITS.minCrashes, LIMITS.maxCrashes, guard.max_crashes);
    const minutesValue = clampInt(minutes, LIMITS.minMinutes, LIMITS.maxMinutes, guard.window_minutes);
    const dirty = enabled !== guard.enabled || maxValue !== guard.max_crashes || minutesValue !== guard.window_minutes;

    const save = useMutation({
        mutationFn: () => saveGuard(identifier, { enabled, max_crashes: maxValue, window_minutes: minutesValue }),
        onSuccess: (result) => {
            queryClient.setQueryData(keys.guard(identifier), result);
            toast.success(result.enabled ? 'Crash Guard is on.' : 'Crash Guard is off.');
        },
        onError: (error) => toast.error(httpErrorToHuman(error)),
    });

    return (
        <TitledGreyBox title={'Settings'}>
            <div className={'cg:flex cg:flex-col cg:gap-4'}>
                <Switch
                    checked={enabled}
                    disabled={!canManage || save.isPending}
                    onChange={setEnabled}
                    label={'Stop this server when it crashes over and over'}
                    description={
                        guard.defaults.enabled
                            ? 'Crash Guard is on for every server by default.'
                            : 'Crash Guard is off until someone turns it on here.'
                    }
                />

                <div className={'cg:flex cg:flex-wrap cg:items-end cg:gap-3'}>
                    <label className={'cg:flex cg:flex-col cg:gap-1 cg:text-sm cg:text-foreground'}>
                        Crashes
                        <Input
                            type={'number'}
                            className={'cg:w-24'}
                            min={LIMITS.minCrashes}
                            max={LIMITS.maxCrashes}
                            value={max}
                            disabled={!canManage || save.isPending}
                            onChange={(event) => setMax(event.target.value)}
                        />
                    </label>
                    <label className={'cg:flex cg:flex-col cg:gap-1 cg:text-sm cg:text-foreground'}>
                        Within (minutes)
                        <Input
                            type={'number'}
                            className={'cg:w-24'}
                            min={LIMITS.minMinutes}
                            max={LIMITS.maxMinutes}
                            value={minutes}
                            disabled={!canManage || save.isPending}
                            onChange={(event) => setMinutes(event.target.value)}
                        />
                    </label>
                    {canManage && dirty && (
                        <Button size={'small'} isLoading={save.isPending} onClick={() => save.mutate()}>
                            Save
                        </Button>
                    )}
                </div>

                <p className={'cg:text-xs cg:text-muted-foreground'}>
                    A crash is a stop or restart that no one asked for. When the server crashes {maxValue} times within {minutesValue}{' '}
                    minute
                    {minutesValue === 1 ? '' : 's'}, Crash Guard stops it and keeps it stopped until you clear it. Checked once a minute.
                </p>

                {guard.enabled && guard.state === 'watching' && (
                    <p className={'cg:text-sm cg:text-foreground'} role={'status'}>
                        Counted now:{' '}
                        <span className={guard.recent_crashes > 0 ? 'cg:text-warning' : 'cg:text-success'}>{guard.recent_crashes}</span> of{' '}
                        {guard.max_crashes} in the last {guard.window_minutes} minute{guard.window_minutes === 1 ? '' : 's'}.
                    </p>
                )}
            </div>
        </TitledGreyBox>
    );
}

const TONE: Record<GuardEvent['kind'], string> = {
    crash: 'cg:text-warning',
    trip: 'cg:text-destructive',
    blocked: 'cg:text-destructive',
    release: 'cg:text-success',
    cleared: 'cg:text-muted-foreground',
};

function LogCard({ identifier, events, link }: { identifier: string; events: GuardEvent[]; link: boolean }) {
    return (
        <TitledGreyBox title={'Crash log'}>
            {events.length === 0 ? (
                <p className={'cg:text-sm cg:text-muted-foreground'}>No crashes seen yet.</p>
            ) : (
                <ul className={'cg:flex cg:flex-col cg:divide-y cg:divide-border'}>
                    {events.map((event) => {
                        const { title, detail } = describeEvent(event);
                        return (
                            <li
                                key={event.id}
                                className={'cg:flex cg:flex-wrap cg:items-baseline cg:justify-between cg:gap-x-4 cg:gap-y-1 cg:py-2'}
                            >
                                <div className={'cg:min-w-0'}>
                                    <p className={`cg:text-sm cg:font-medium ${TONE[event.kind]}`}>{title}</p>
                                    {detail && <p className={'cg:text-xs cg:text-muted-foreground'}>{detail}</p>}
                                    {link && event.kind === 'crash' && event.status === 'confirmed' && (
                                        <QuietBoundary>
                                            <PanelLink
                                                destination={{ extension: 'mc-crash', screen: 'crashes', params: { id: identifier } }}
                                                className={'cg:text-xs cg:text-primary cg:hover:underline'}
                                            >
                                                Open Minecraft Crash
                                            </PanelLink>
                                        </QuietBoundary>
                                    )}
                                </div>
                                <time className={'cg:text-xs cg:text-muted-foreground'} dateTime={event.occurred_at}>
                                    {new Date(event.occurred_at).toLocaleString()}
                                </time>
                            </li>
                        );
                    })}
                </ul>
            )}
        </TitledGreyBox>
    );
}

/** The other extension's page may not be loaded (yet); a link to it is optional, so a failure shows nothing. */
class QuietBoundary extends Component<{ children: ReactNode }, { failed: boolean }> {
    state = { failed: false };

    static getDerivedStateFromError() {
        return { failed: true };
    }

    render() {
        return this.state.failed ? null : this.props.children;
    }
}
