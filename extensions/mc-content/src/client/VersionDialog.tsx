import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Button, Dialog, Spinner, httpErrorToHuman, invalidateServerData, toast, useCurrentServerRequired } from '@pterodactyl/sdk';
import { getVersions, install, keys, type ContentKind, type Filters, type SearchHit } from './api';
import { formatBytes, formatDate, loaderLabel, versionRange } from './format';

interface Props {
    kind: ContentKind;
    project: SearchHit;
    filters: Filters;
    onClose: () => void;
    onInstalled: () => void;
}

export default function VersionDialog({ kind, project, filters, onClose, onInstalled }: Props) {
    const server = useCurrentServerRequired();
    const identifier = server.attributes.identifier;
    const queryClient = useQueryClient();

    const versions = useQuery({
        queryKey: keys.versions(identifier, kind, project.project_id, filters),
        queryFn: () => getVersions(identifier, kind, project.project_id, filters),
    });

    const installedVersion = versions.data?.installed ?? project.installed;

    const installVersion = useMutation({
        mutationFn: (versionId: string) => install(identifier, kind, versionId, filters, installedVersion?.filename),
        onSuccess: async (result) => {
            const names = result.installed.map((item) => `${item.title} ${item.version_number}`.trim());
            toast.success(`${installedVersion ? 'Switched to' : 'Installed'} ${names.join(', ')}. Restart the server to load it.`);
            for (const skipped of result.skipped_dependencies) {
                toast.warning(`A required dependency was not installed: ${skipped.reason}`);
            }
            await Promise.all([
                queryClient.invalidateQueries({ queryKey: keys.installed(identifier, kind) }),
                queryClient.invalidateQueries({ queryKey: ['mc-content', identifier, kind, 'search'] }),
                queryClient.invalidateQueries({ queryKey: ['mc-content', identifier, kind, 'versions', project.project_id] }),
                invalidateServerData(server.attributes.uuid, ['files']),
            ]);
            onInstalled();
            onClose();
        },
        onError: (error) => toast.error(httpErrorToHuman(error)),
    });

    return (
        <Dialog
            open
            onClose={() => !installVersion.isPending && onClose()}
            preventExternalClose={installVersion.isPending}
            title={installedVersion ? `Change ${project.title} version` : `Install ${project.title}`}
            description={
                filters.gameVersion
                    ? `Versions for ${loaderLabel(filters.loader)} on Minecraft ${filters.gameVersion}.`
                    : `Versions for ${loaderLabel(filters.loader)}.`
            }
        >
            {versions.isLoading ? (
                <Spinner centered />
            ) : versions.error ? (
                <Alert type={'danger'}>{httpErrorToHuman(versions.error)}</Alert>
            ) : !versions.data?.data.length ? (
                <Alert type={'warning'}>
                    No version of {project.title} supports {loaderLabel(filters.loader)}
                    {filters.gameVersion ? ` on Minecraft ${filters.gameVersion}` : ''}.
                </Alert>
            ) : (
                <ul className={'mcc:flex mcc:max-h-96 mcc:flex-col mcc:divide-y mcc:divide-border mcc:overflow-y-auto'}>
                    {versions.data.data.map((version, index) => {
                        const isCurrent = installedVersion?.version_id === version.id;

                        return (
                            <li
                                key={version.id}
                                className={`mcc:flex mcc:items-center mcc:gap-3 mcc:py-2.5 ${isCurrent ? 'mcc:opacity-60' : ''}`}
                            >
                                <div className={'mcc:min-w-0 mcc:flex-1'}>
                                    <div className={'mcc:flex mcc:flex-wrap mcc:items-center mcc:gap-2'}>
                                        <span className={'mcc:truncate mcc:font-medium mcc:text-foreground'}>{version.version_number}</span>
                                        {index === 0 && (
                                            <span
                                                className={
                                                    'mcc:rounded mcc:bg-primary/15 mcc:px-1.5 mcc:py-0.5 mcc:text-xs mcc:text-primary'
                                                }
                                            >
                                                Latest
                                            </span>
                                        )}
                                        {isCurrent && (
                                            <span className={'mcc:rounded mcc:bg-muted mcc:px-1.5 mcc:py-0.5 mcc:text-xs mcc:font-medium'}>
                                                Installed
                                            </span>
                                        )}
                                        {version.version_type !== 'release' && (
                                            <span className={'mcc:rounded mcc:bg-muted mcc:px-1.5 mcc:py-0.5 mcc:text-xs mcc:capitalize'}>
                                                {version.version_type}
                                            </span>
                                        )}
                                    </div>
                                    <p className={'mcc:mt-0.5 mcc:truncate mcc:text-xs mcc:text-muted-foreground'}>
                                        {versionRange(version.game_versions)} · {version.loaders.map(loaderLabel).join(', ')}
                                        {version.file ? ` · ${formatBytes(version.file.size)}` : ''}
                                        {version.date_published ? ` · ${formatDate(version.date_published)}` : ''}
                                    </p>
                                </div>
                                <Button
                                    size={'small'}
                                    isSecondary={index !== 0 || isCurrent}
                                    isLoading={installVersion.isPending && installVersion.variables === version.id}
                                    disabled={installVersion.isPending || isCurrent}
                                    onClick={() => installVersion.mutate(version.id)}
                                >
                                    {isCurrent ? 'Installed' : installedVersion ? 'Switch' : 'Install'}
                                </Button>
                            </li>
                        );
                    })}
                </ul>
            )}
        </Dialog>
    );
}
