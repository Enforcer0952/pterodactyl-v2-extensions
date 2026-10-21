import { useEffect, useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
    Alert,
    Button,
    Dialog,
    Spinner,
    Table,
    TitledGreyBox,
    httpErrorToHuman,
    invalidateServerData,
    toast,
    useCurrentServerRequired,
    useServerPermission,
    type TableColumn,
} from '@pterodactyl/sdk';
import { getInstalled, install, keys, uninstall, type ContentKind, type Filters, type InstalledFile } from './api';
import ProjectIcon from './ProjectIcon';
import { formatBytes } from './format';

interface Props {
    kind: ContentKind;
    filters: Filters;
    directory: string;
    /** Set when installing is not possible on this server; turns Update off. */
    blockedReason?: string | null;
    /** Open a project's page; only for files recognised on Modrinth. */
    onOpen?: (projectId: string) => void;
}

export default function InstalledList({ kind, filters, directory, blockedReason = null, onOpen }: Props) {
    const server = useCurrentServerRequired();
    const identifier = server.attributes.identifier;
    const queryClient = useQueryClient();
    const canUpdate = useServerPermission(['file.create', 'file.delete']);
    const canDelete = useServerPermission('file.delete');
    const [removing, setRemoving] = useState<InstalledFile | null>(null);

    const installed = useQuery({
        queryKey: [...keys.installed(identifier, kind), filters],
        queryFn: () => getInstalled(identifier, kind, filters),
        // Keep asking while jars added by hand are still being identified.
        refetchInterval: (query) => ((query.state.data?.pending ?? 0) > 0 ? 1500 : false),
    });

    const pending = installed.data?.pending ?? 0;
    const previousPending = useRef(pending);
    useEffect(() => {
        if (previousPending.current > 0 && pending === 0) {
            void queryClient.invalidateQueries({ queryKey: ['mc-content', identifier, kind, 'search'] });
        }
        previousPending.current = pending;
    }, [pending, identifier, kind, queryClient]);

    const refresh = () =>
        Promise.all([
            queryClient.invalidateQueries({ queryKey: keys.installed(identifier, kind) }),
            invalidateServerData(server.attributes.uuid, ['files']),
        ]);

    const update = useMutation({
        mutationFn: (file: InstalledFile) => install(identifier, kind, file.update!.id, filters, file.filename),
        onSuccess: async (result) => {
            const first = result.installed[0];
            toast.success(
                `Updated ${first?.title ?? 'file'} to ${first?.version_number ?? 'the latest version'}. Restart the server to load it.`,
            );
            await refresh();
        },
        onError: (error) => toast.error(httpErrorToHuman(error)),
    });

    const remove = useMutation({
        mutationFn: (file: InstalledFile) => uninstall(identifier, kind, file.filename),
        onSuccess: async () => {
            toast.success('Removed. Restart the server to unload it.');
            setRemoving(null);
            await refresh();
        },
        onError: (error) => toast.error(httpErrorToHuman(error)),
    });

    const columns: TableColumn<InstalledFile>[] = [
        {
            id: 'name',
            label: 'Name',
            cell: (file) => (
                <div className={'mcc:flex mcc:items-center mcc:gap-3'}>
                    <ProjectIcon url={file.icon_url} title={file.title ?? file.filename} />
                    <div className={'mcc:min-w-0'}>
                        {file.project_id && onOpen ? (
                            <button
                                type={'button'}
                                onClick={() => onOpen(file.project_id as string)}
                                className={
                                    'mcc:block mcc:max-w-full mcc:truncate mcc:text-left mcc:font-medium mcc:text-foreground mcc:hover:underline'
                                }
                            >
                                {file.title ?? file.filename}
                            </button>
                        ) : (
                            <p className={'mcc:truncate mcc:font-medium mcc:text-foreground'}>{file.title ?? file.filename}</p>
                        )}
                        <p className={'mcc:truncate mcc:text-xs mcc:text-muted-foreground'}>
                            {file.title ? file.filename : 'Not found on Modrinth'}
                            {file.disabled ? ' · disabled' : ''}
                        </p>
                    </div>
                </div>
            ),
        },
        {
            id: 'version',
            label: 'Version',
            cell: (file) => (
                <div className={'mcc:flex mcc:flex-col mcc:gap-0.5 mcc:text-sm'}>
                    <span>{file.version_number ?? '—'}</span>
                    {file.update && <span className={'mcc:text-xs mcc:text-primary'}>{file.update.version_number} available</span>}
                </div>
            ),
        },
        { id: 'size', label: 'Size', cell: (file) => <span className={'mcc:text-sm'}>{formatBytes(file.size)}</span> },
        {
            id: 'actions',
            label: <span className={'mcc:sr-only'}>Actions</span>,
            cell: (file) => (
                <div className={'mcc:flex mcc:justify-end mcc:gap-2'}>
                    {file.update && canUpdate && (
                        <Button
                            size={'small'}
                            isLoading={update.isPending && update.variables?.filename === file.filename}
                            disabled={blockedReason !== null || update.isPending}
                            title={blockedReason ?? undefined}
                            onClick={() => update.mutate(file)}
                        >
                            Update
                        </Button>
                    )}
                    {canDelete && (
                        <Button.Danger size={'small'} isSecondary onClick={() => setRemoving(file)}>
                            Remove
                        </Button.Danger>
                    )}
                </div>
            ),
        },
    ];

    return (
        <TitledGreyBox title={`Installed in ${directory}`}>
            {installed.isLoading ? (
                <Spinner centered />
            ) : installed.error ? (
                <Alert type={'danger'}>{httpErrorToHuman(installed.error)}</Alert>
            ) : (
                <>
                    {pending > 0 && (
                        <p className={'mcc:mb-2 mcc:text-xs mcc:text-muted-foreground'}>
                            Identifying {pending} jar{pending === 1 ? '' : 's'} added by hand…
                        </p>
                    )}
                    <Table
                        rows={installed.data?.data ?? []}
                        columns={columns}
                        keyOf={(file) => file.filename}
                        isFetching={installed.isFetching}
                        emptyState={`Nothing in ${directory} yet. Install something from the Browse tab.`}
                    />
                </>
            )}

            <Dialog.Confirm
                open={removing !== null}
                onClose={() => setRemoving(null)}
                title={`Remove ${removing?.title ?? removing?.filename ?? ''}?`}
                confirm={'Remove'}
                pending={remove.isPending}
                onConfirmed={() => removing && remove.mutate(removing)}
            >
                This deletes {removing?.filename} from {directory}. Its config folder is kept.
            </Dialog.Confirm>
        </TitledGreyBox>
    );
}
