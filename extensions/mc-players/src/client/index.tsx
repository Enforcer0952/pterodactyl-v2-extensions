import './styles.css';
import { definePterodactylExtension } from '@pterodactyl/sdk';
import PlayersSlot from './PlayersCard';
import { setConfig } from './config';

export default definePterodactylExtension({
    setup({ config, screens, slots }) {
        setConfig(config);

        slots.register('server.console.after', PlayersSlot);
        screens.register('player', () => import('./screens/PlayerScreen'));
    },
});
