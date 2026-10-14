import './styles.css';
import { definePterodactylExtension } from '@pterodactyl/sdk';
import AlertBanner from './AlertBanner';
import AllocationSlot from './AllocationHelper';
import HistorySlot from './HistoryCard';

export default definePterodactylExtension({
    setup({ slots, screens }) {
        screens.register('overview', () => import('./screens/OverviewScreen'));
        screens.register('node', () => import('./screens/NodeScreen'));
        slots.register('panel.overview.before', AlertBanner);
        slots.register('panel.nodes.before', AlertBanner);
        slots.register('panel.servers.create.after', AllocationSlot);
        slots.register('panel.servers.detail.build.before', AllocationSlot);
        slots.register('server.console.after', HistorySlot);
    },
});
