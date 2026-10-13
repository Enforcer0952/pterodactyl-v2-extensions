import { useEffect, useRef } from 'react';
import { createPortal } from 'react-dom';
import { PanelLink } from '@pterodactyl/sdk';
import { Maximize2, X } from 'lucide-react';
import PlayerProfile from './PlayerProfile';

/** The player profile over the console page. Escape, the backdrop or the close button close it. */
export default function PlayerPopup({ server, name, onClose }: { server: string; name: string; onClose: () => void }) {
    const panel = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            // A confirm dialog opened from inside the popup handles its own Escape.
            if (event.key === 'Escape' && !document.querySelector('[role="alertdialog"], [role="dialog"]:not([data-mcp-popup])')) {
                onClose();
            }
        };
        document.addEventListener('keydown', onKey);
        const overflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        panel.current?.focus();

        return () => {
            document.removeEventListener('keydown', onKey);
            document.body.style.overflow = overflow;
        };
    }, [onClose]);

    return createPortal(
        <div
            className={'mcp:fixed mcp:inset-0 mcp:z-50 mcp:flex mcp:items-center mcp:justify-center mcp:bg-black/60 mcp:p-2 mcp:sm:p-6'}
            onMouseDown={(event) => {
                if (event.target === event.currentTarget) onClose();
            }}
        >
            <div
                ref={panel}
                role={'dialog'}
                aria-modal
                aria-label={`Player ${name}`}
                data-mcp-popup
                tabIndex={-1}
                className={
                    'mcp:flex mcp:max-h-full mcp:w-full mcp:max-w-3xl mcp:flex-col mcp:overflow-hidden mcp:rounded-lg mcp:border mcp:border-border mcp:bg-background mcp:shadow-2xl mcp:outline-none'
                }
            >
                <div className={'mcp:flex mcp:items-center mcp:justify-end mcp:gap-1 mcp:border-b mcp:border-border mcp:px-3 mcp:py-2'}>
                    <PanelLink
                        destination={{ extension: 'mc-players', screen: 'player', params: { id: server, player: name } }}
                        className={
                            'mcp:flex mcp:items-center mcp:gap-1 mcp:rounded mcp:px-2 mcp:py-1 mcp:text-sm mcp:text-muted-foreground mcp:hover:bg-muted mcp:hover:text-foreground'
                        }
                        onClick={onClose}
                    >
                        <Maximize2 className={'mcp:size-4'} aria-hidden />
                        Full page
                    </PanelLink>
                    <button
                        type={'button'}
                        aria-label={'Close'}
                        className={'mcp:rounded mcp:p-1 mcp:text-muted-foreground mcp:hover:bg-muted mcp:hover:text-foreground'}
                        onClick={onClose}
                    >
                        <X className={'mcp:size-5'} aria-hidden />
                    </button>
                </div>
                <div className={'mcp:min-h-0 mcp:flex-1 mcp:overflow-y-auto mcp:p-4 mcp:sm:p-5'}>
                    <PlayerProfile server={server} name={name} />
                </div>
            </div>
        </div>,
        document.body,
    );
}
