import { Alert, PanelLink, ServerContentBlock, useCurrentServerRequired, type ScreenComponentProps } from '@pterodactyl/sdk';
import { ArrowLeft } from 'lucide-react';
import PlayerProfile from '../PlayerProfile';
import { isValidName } from '../console';

/** /server/<id>/player/<name>: the full player page. */
export default function PlayerScreen({ data }: ScreenComponentProps) {
    const server = useCurrentServerRequired();
    const name = decodeURIComponent(data.params.player ?? '');

    return (
        <ServerContentBlock title={name ? `${name} · Players` : 'Player'}>
            <div className={'mcp:mx-auto mcp:flex mcp:max-w-4xl mcp:flex-col mcp:gap-4'}>
                <PanelLink
                    destination={{ to: '/server/$id', params: { id: server.attributes.identifier } }}
                    className={
                        'mcp:flex mcp:w-fit mcp:items-center mcp:gap-1 mcp:text-sm mcp:text-muted-foreground mcp:hover:text-foreground'
                    }
                >
                    <ArrowLeft className={'mcp:size-4'} aria-hidden />
                    Console
                </PanelLink>
                {isValidName(name) ? (
                    <div className={'mcp:rounded-lg mcp:border mcp:border-border mcp:bg-background mcp:p-4 mcp:sm:p-6'}>
                        <PlayerProfile server={server.attributes.identifier} name={name} />
                    </div>
                ) : (
                    <Alert type={'danger'}>That is not a valid player name.</Alert>
                )}
            </div>
        </ServerContentBlock>
    );
}
