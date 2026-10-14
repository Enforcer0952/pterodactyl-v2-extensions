import './styles.css';
import { definePterodactylExtension, type ScreenContext } from '@pterodactyl/sdk';
import PlayersSlot from './PlayersCard';
import SettingsSlot from './SettingsCard';
import { isValheimServer, usesBepInEx } from './api';

/**
 * The manifest can only match egg tags, and the Valheim eggs carry none. So the screens are
 * shown by this check: the "valheim" tag, or a startup command that runs valheim_server.
 */
function isValheimScreen({ server }: ScreenContext): boolean {
    return isValheimServer(server);
}

/** The Mods page also needs a startup command that loads BepInEx. */
function isModdedScreen({ server }: ScreenContext): boolean {
    return isValheimServer(server) && usesBepInEx(server?.attributes.invocation);
}

export default definePterodactylExtension({
    setup({ slots, screens }) {
        screens.register('lists', () => import('./screens/ListsScreen'), { visible: isValheimScreen });
        screens.register('worlds', () => import('./screens/WorldsScreen'), { visible: isValheimScreen });
        screens.register('mods', () => import('./screens/ModsScreen'), { visible: isModdedScreen });
        slots.register('server.console.after', PlayersSlot);
        slots.register('server.console.after', SettingsSlot);
    },
});
