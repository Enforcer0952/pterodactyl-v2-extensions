import './styles.css';
import { definePterodactylExtension } from '@pterodactyl/sdk';
import AdminSlot from './AdminCard';
import ConsoleAssistSlot from './ConsoleAssist';
import PlayersSlot from './PlayersCard';

export default definePterodactylExtension({
    setup({ slots, screens }) {
        screens.register('mods', () => import('./screens/ModsScreen'));
        screens.register('config', () => import('./screens/ConfigScreen'));
        slots.register('server.console.after', PlayersSlot);
        slots.register('server.console.after', AdminSlot);
        slots.register('server.console.after', ConsoleAssistSlot);
    },
});
