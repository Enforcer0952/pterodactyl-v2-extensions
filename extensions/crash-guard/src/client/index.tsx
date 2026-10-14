import './styles.css';
import { definePterodactylExtension } from '@pterodactyl/sdk';
import GuardBannerSlot from './GuardBanner';

export default definePterodactylExtension({
    setup({ slots, screens }) {
        screens.register('guard', () => import('./screens/GuardScreen'));
        screens.register('overview', () => import('./screens/AdminScreen'));
        slots.register('server.console.before', GuardBannerSlot);
    },
});
