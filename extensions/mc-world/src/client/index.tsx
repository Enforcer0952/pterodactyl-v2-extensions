import './styles.css';
import { definePterodactylExtension, type ScreenContext } from '@pterodactyl/sdk';
import { isJavaServer } from './logic';

/** The manifest lets Java and Bedrock eggs through; Bedrock and proxies are left out here. */
const shows = (context: ScreenContext) => isJavaServer(context.server?.attributes.egg_tags, context.server?.attributes.egg_features);

export default definePterodactylExtension({
    setup({ screens }) {
        screens.register('world', () => import('./screens/WorldScreen'), { visible: shows });
    },
});
