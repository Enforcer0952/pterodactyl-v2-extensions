import './styles.css';
import { definePterodactylExtension, type ScreenContext } from '@pterodactyl/sdk';
import { useQuery } from '@tanstack/react-query';
import { getPlatform, keys, type ContentKind } from './api';
import { setConfig } from './config';

/**
 * Show a page only for servers that load that kind of content, judged from the
 * server's files (a Fabric server on a Paper egg gets Mods, not Plugins). Hidden
 * until the panel has answered.
 */
function useShows(kind: ContentKind, context: ScreenContext): boolean {
    const identifier = context.server?.attributes.identifier ?? '';
    const platform = useQuery({
        queryKey: keys.platform(identifier),
        queryFn: () => getPlatform(identifier),
        enabled: identifier !== '',
        staleTime: 5 * 60_000,
        retry: false,
    });

    return platform.data?.kinds.includes(kind) ?? false;
}

export default definePterodactylExtension({
    setup({ config, screens }) {
        setConfig(config);

        screens.register('plugins', () => import('./screens/PluginsScreen'), { visible: (context) => useShows('plugins', context) });
        screens.register('mods', () => import('./screens/ModsScreen'), { visible: (context) => useShows('mods', context) });
    },
});
