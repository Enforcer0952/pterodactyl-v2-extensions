import './styles.css';
import { definePterodactylExtension, type ScreenContext } from '@pterodactyl/sdk';
import { useQuery } from '@tanstack/react-query';
import { getStatus, keys } from './api';
import CrashBadgeSlot from './CrashBadge';
import { setConfig } from './config';
import { isJavaOrProxy } from './logic';

/** The manifest lets Java, Bedrock and proxy eggs through; Bedrock is left out here. */
const shows = (context: ScreenContext) => isJavaOrProxy(context.server?.attributes.egg_tags, context.server?.attributes.egg_features);

/** The number of crashes since the last visit, shown on the navigation entry. */
function useBadge(context: ScreenContext): number | null {
    const identifier = context.server?.attributes.identifier ?? '';
    const status = useQuery({
        queryKey: keys.status(identifier),
        queryFn: () => getStatus(identifier),
        enabled: identifier !== '',
        staleTime: 60_000,
        retry: false,
    });

    return status.data && status.data.new > 0 ? status.data.new : null;
}

export default definePterodactylExtension({
    setup({ config, screens, slots }) {
        setConfig(config);

        slots.register('server.console.after', CrashBadgeSlot);
        screens.register('crashes', () => import('./screens/CrashesScreen'), { visible: shows, badge: useBadge });
    },
});
