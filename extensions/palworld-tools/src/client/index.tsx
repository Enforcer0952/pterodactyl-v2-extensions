import './styles.css';
import { definePterodactylExtension, type ScreenContext } from '@pterodactyl/sdk';
import PlayersSlot from './PlayersCard';
import { isPalworldServer } from './api';

/**
 * The manifest can only match egg tags, and the common Palworld egg is tagged just
 * "steamcmd". So the screens are shown by this check: the "palworld" tag, or a startup
 * command that runs PalServer.
 */
function isPalworldScreen({ server }: ScreenContext): boolean {
    return isPalworldServer(server);
}

export default definePterodactylExtension({
    setup({ slots, screens }) {
        screens.register('settings', () => import('./screens/SettingsScreen'), { visible: isPalworldScreen });
        screens.register('saves', () => import('./screens/SavesScreen'), { visible: isPalworldScreen });
        slots.register('server.console.after', PlayersSlot);
    },
});
