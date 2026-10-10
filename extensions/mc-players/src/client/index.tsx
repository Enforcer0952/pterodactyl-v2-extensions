import './styles.css';
import { definePterodactylExtension } from '@pterodactyl/sdk';
import PlayersSlot from './PlayersCard';

export default definePterodactylExtension({
    setup({ slots }) {
        slots.register('server.console.after', PlayersSlot);
    },
});
