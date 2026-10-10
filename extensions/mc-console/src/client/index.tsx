import './styles.css';
import { definePterodactylExtension } from '@pterodactyl/sdk';
import ConsoleAssistSlot from './ConsoleAssist';

export default definePterodactylExtension({
    setup({ slots }) {
        slots.register('server.console.after', ConsoleAssistSlot);
    },
});
