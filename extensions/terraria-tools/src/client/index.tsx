import './styles.css';
import { definePterodactylExtension, type ScreenContext } from '@pterodactyl/sdk';
import AdminSlot from './AdminCard';
import ConsoleAssistSlot from './ConsoleAssist';
import PlayersSlot from './PlayersCard';
import { isTerrariaServer } from './api';

/**
 * The manifest can only match egg tags, and a Terraria egg may be tagged only
 * "srcds". So the screens are shown by this check: the "terraria" tag, or a startup
 * command that runs TerrariaServer.
 */
function isTerrariaScreen({ server }: ScreenContext): boolean {
    return isTerrariaServer(server);
}

export default definePterodactylExtension({
    setup({ slots, screens }) {
        screens.register('worlds', () => import('./screens/WorldsScreen'), { visible: isTerrariaScreen });
        screens.register('settings', () => import('./screens/SettingsScreen'), { visible: isTerrariaScreen });
        slots.register('server.console.after', PlayersSlot);
        slots.register('server.console.after', AdminSlot);
        slots.register('server.console.after', ConsoleAssistSlot);
    },
});
