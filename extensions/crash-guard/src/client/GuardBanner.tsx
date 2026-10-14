import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
    Alert,
    Button,
    SocketEvent,
    httpErrorToHuman,
    toast,
    useServerPermission,
    useServerWebsocketEvent,
    type SdkServer,
} from '@pterodactyl/sdk';
import { clearGuard, getGuard, keys, type Guard } from './api';
import { tripSummary } from './format';

/** The red banner above the console while Crash Guard is holding the server off. */
export default function GuardBannerSlot({ data: server }: { data: SdkServer }) {
    const canView = useServerPermission('ext.crash-guard.view');
    if (!canView) return null;

    return <GuardBanner identifier={server.attributes.identifier} />;
}

export function useGuard(identifier: string, enabled = true) {
    return useQuery({
        queryKey: keys.guard(identifier),
        queryFn: () => getGuard(identifier),
        enabled,
        refetchInterval: 30_000,
        staleTime: 15_000,
    });
}

function GuardBanner({ identifier }: { identifier: string }) {
    const queryClient = useQueryClient();
    const guard = useGuard(identifier);

    // A status change can mean the guard just acted, or a person just started the server.
    useServerWebsocketEvent(SocketEvent.STATUS, () => {
        void queryClient.invalidateQueries({ queryKey: keys.guard(identifier) });
    });

    if (guard.data?.state !== 'tripped' || !guard.data.tripped) return null;

    return (
        <div className={'cg:mb-4'}>
            <TrippedNotice identifier={identifier} guard={guard.data} />
        </div>
    );
}

/** What happened, and the buttons to move on. Used on the console banner and on the Crash Guard page. */
export function TrippedNotice({ identifier, guard }: { identifier: string; guard: Guard }) {
    const queryClient = useQueryClient();
    const canManage = useServerPermission('ext.crash-guard.manage');
    const canStart = useServerPermission('control.start');

    const clear = useMutation({
        mutationFn: (start: boolean) => clearGuard(identifier, start),
        onSuccess: (result, start) => {
            queryClient.setQueryData(keys.guard(identifier), result);
            toast.success(start ? 'Cleared. The server is starting.' : 'Cleared. The server stays stopped until you start it.');
        },
        onError: (error) => {
            toast.error(httpErrorToHuman(error));
            void queryClient.invalidateQueries({ queryKey: keys.guard(identifier) });
        },
    });

    if (!guard.tripped) return null;

    return (
        <Alert type={'danger'} title={tripSummary(guard.tripped.crashes, guard.tripped.minutes)}>
            <div className={'cg:flex cg:flex-col cg:gap-3'}>
                <p className={'cg:text-sm'}>
                    It keeps stopped until you clear this. Look at the console or the crash log to find out why it crashes before you start
                    it again.
                </p>
                {canManage && (
                    <div className={'cg:flex cg:flex-wrap cg:gap-2'}>
                        {canStart && (
                            <Button
                                size={'small'}
                                isLoading={clear.isPending && clear.variables === true}
                                disabled={clear.isPending}
                                onClick={() => clear.mutate(true)}
                            >
                                Clear and start
                            </Button>
                        )}
                        <Button
                            size={'small'}
                            isSecondary
                            isLoading={clear.isPending && clear.variables === false}
                            disabled={clear.isPending}
                            onClick={() => clear.mutate(false)}
                        >
                            Clear
                        </Button>
                    </div>
                )}
            </div>
        </Alert>
    );
}
