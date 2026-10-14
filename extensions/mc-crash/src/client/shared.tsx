import { useState, type ReactNode } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { Button, Dialog, PanelLink, Spinner, httpErrorToHuman, toast } from '@pterodactyl/sdk';
import { getRaw, keys, shareFile, type Culprit, type Severity } from './api';
import { SEVERITY_CLASS, SEVERITY_LABEL, contentScreen, jarLabel, jarNames, shareWarning, tierLabel } from './logic';

export function SeverityBadge({ severity }: { severity: Severity }) {
    return (
        <span className={`mccr:rounded mccr:px-1.5 mccr:py-0.5 mccr:text-xs mccr:font-medium ${SEVERITY_CLASS[severity]}`}>
            {SEVERITY_LABEL[severity]}
        </span>
    );
}

export function CopyButton({ text, label = 'Copy' }: { text: string; label?: string }) {
    const copy = async () => {
        try {
            await navigator.clipboard.writeText(text);
            toast.success('Copied.');
        } catch {
            toast.error('Could not copy. Select the text and copy it by hand.');
        }
    };

    return (
        <Button size={'xsmall'} isSecondary onClick={() => void copy()}>
            {label}
        </Button>
    );
}

/** The trace, folded. The text can be passed in, or fetched when the section is opened. */
export function Folded({
    summary,
    text,
    load,
    actions,
}: {
    summary: string;
    text?: string;
    load?: { server: string; path: string };
    actions?: ReactNode;
}) {
    const [open, setOpen] = useState(false);
    const raw = useQuery({
        queryKey: keys.raw(load?.server ?? '', load?.path ?? ''),
        queryFn: () => getRaw(load?.server ?? '', load?.path ?? ''),
        enabled: open && load !== undefined,
        staleTime: 5 * 60_000,
    });
    const body = text ?? raw.data?.text ?? '';

    return (
        <details className={'mccr:rounded-md mccr:border mccr:border-border'} onToggle={(event) => setOpen(event.currentTarget.open)}>
            <summary
                className={'mccr:cursor-pointer mccr:px-3 mccr:py-2 mccr:text-sm mccr:text-muted-foreground mccr:hover:text-foreground'}
            >
                {summary}
            </summary>
            <div className={'mccr:flex mccr:flex-col mccr:gap-2 mccr:border-t mccr:border-border mccr:p-3'}>
                {load && raw.isLoading && <Spinner centered />}
                {load && raw.error && <p className={'mccr:text-sm mccr:text-destructive'}>{httpErrorToHuman(raw.error)}</p>}
                {(text !== undefined || raw.data) && (
                    <>
                        <div className={'mccr:flex mccr:flex-wrap mccr:items-center mccr:gap-2'}>
                            <CopyButton text={body} />
                            {actions}
                            {raw.data?.truncated && (
                                <span className={'mccr:text-xs mccr:text-muted-foreground'}>Only the start of this file is shown.</span>
                            )}
                        </div>
                        <pre
                            className={
                                'mccr:max-h-96 mccr:overflow-auto mccr:whitespace-pre mccr:rounded mccr:bg-muted mccr:p-3 mccr:font-mono mccr:text-xs mccr:text-foreground'
                            }
                        >
                            {body === '' ? 'Nothing to show.' : body}
                        </pre>
                    </>
                )}
            </div>
        </details>
    );
}

/** "Confirmed: LuckPerms 5.4" or "Likely: A or B", with links to the page that manages the jar. */
export function CulpritLine({ culprit, server, links }: { culprit: Culprit; server: string; links: boolean }) {
    const confirmed = culprit.tier === 'confirmed';

    return (
        <div className={'mccr:flex mccr:flex-col mccr:gap-1'}>
            <div className={'mccr:flex mccr:flex-wrap mccr:items-center mccr:gap-2 mccr:text-sm'}>
                <span className={'mccr:text-muted-foreground'}>Cause</span>
                <span
                    className={`mccr:rounded mccr:px-1.5 mccr:py-0.5 mccr:text-xs mccr:font-medium ${
                        confirmed ? 'mccr:bg-success/20 mccr:text-success' : 'mccr:bg-warning/15 mccr:text-warning'
                    }`}
                >
                    {tierLabel(culprit)}
                </span>
                {confirmed ? (
                    <span className={'mccr:font-medium mccr:text-foreground'}>
                        {culprit.jars.map((jar) => (
                            <span key={jar.file} className={'mccr:mr-2'}>
                                {jarLabel(jar)} <span className={'mccr:font-normal mccr:text-muted-foreground'}>({jar.file})</span>
                            </span>
                        ))}
                    </span>
                ) : (
                    <span className={'mccr:text-foreground'}>{jarNames(culprit)}</span>
                )}
                {links &&
                    culprit.jars.map((jar) => {
                        const screen = contentScreen(jar);
                        return screen === null ? null : (
                            <PanelLink
                                key={jar.file}
                                destination={{ extension: 'mc-content', screen, params: { id: server } }}
                                className={'mccr:text-xs mccr:text-primary mccr:hover:underline'}
                            >
                                Open in {screen === 'mods' ? 'Mods' : 'Plugins'}
                            </PanelLink>
                        );
                    })}
            </div>
            <p className={'mccr:text-xs mccr:text-muted-foreground'}>
                {confirmed ? culprit.basis : `This is a guess. ${culprit.basis.replace(/ This is a guess\.$/, '')}`}
            </p>
        </div>
    );
}

/**
 * Uploads a report or log to mclo.gs. The upload only happens after the user has read that the link is public.
 */
export function ShareButton({ server, path, name }: { server: string; path: string; name: string }) {
    const [asking, setAsking] = useState(false);
    const [url, setUrl] = useState<string | null>(null);

    const share = useMutation({
        mutationFn: () => shareFile(server, path),
        onSuccess: (result) => {
            setUrl(result.url);
            setAsking(false);
            toast.success('Uploaded to mclo.gs.');
        },
        onError: (error) => toast.error(httpErrorToHuman(error)),
    });

    return (
        <>
            {url ? (
                <span className={'mccr:flex mccr:flex-wrap mccr:items-center mccr:gap-2 mccr:text-xs'}>
                    <a href={url} target={'_blank'} rel={'noreferrer noopener'} className={'mccr:text-primary mccr:hover:underline'}>
                        {url}
                    </a>
                    <CopyButton text={url} label={'Copy link'} />
                </span>
            ) : (
                <Button size={'xsmall'} isSecondary onClick={() => setAsking(true)}>
                    Share
                </Button>
            )}
            {asking && (
                <Dialog.Confirm
                    open
                    title={'Share on mclo.gs?'}
                    confirm={'Upload'}
                    pending={share.isPending}
                    onClose={() => setAsking(false)}
                    onConfirmed={() => share.mutate()}
                >
                    <p className={'mccr:text-sm'}>{shareWarning(name)}</p>
                </Dialog.Confirm>
            )}
        </>
    );
}
