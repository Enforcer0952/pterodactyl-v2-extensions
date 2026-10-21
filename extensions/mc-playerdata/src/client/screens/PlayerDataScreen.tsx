import { Alert, PanelLink, ServerContentBlock, useCurrentServerRequired, type ScreenComponentProps } from '@pterodactyl/sdk';
import { ArrowLeft } from 'lucide-react';
import PlayerDataView from '../PlayerDataView';
import { decodeParam, isPlayerRef } from '../format';

/** /server/<id>/player-data/<player>: a name or a UUID. */
export default function PlayerDataScreen({ data }: ScreenComponentProps) {
    const server = useCurrentServerRequired();
    const player = decodeParam(data.params.player);

    return (
        <ServerContentBlock title={player ? `${player} · Player data` : 'Player data'}>
            <div className={'mcpd:mx-auto mcpd:flex mcpd:max-w-6xl mcpd:flex-col mcpd:gap-4'}>
                <PanelLink
                    destination={{ to: '/server/$id', params: { id: server.attributes.identifier } }}
                    className={
                        'mcpd:flex mcpd:w-fit mcpd:items-center mcpd:gap-1 mcpd:text-sm mcpd:text-muted-foreground mcpd:hover:text-foreground'
                    }
                >
                    <ArrowLeft className={'mcpd:size-4'} aria-hidden />
                    Console
                </PanelLink>
                {isPlayerRef(player) ? (
                    <PlayerDataView key={player} player={player} />
                ) : (
                    <Alert type={'danger'}>That is not a valid player name or UUID.</Alert>
                )}
            </div>
        </ServerContentBlock>
    );
}
