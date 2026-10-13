import './styles.css';
import { definePterodactylExtension, type ScreenContext } from '@pterodactyl/sdk';

/**
 * Java Edition servers only: tagged "minecraft" or carrying the "eula" feature every
 * Java egg has, and not a proxy or Bedrock. The API answers 404 for the rest.
 */
export function showsVersion(tags: readonly string[], features: readonly string[] | null | undefined): boolean {
    const lowerTags = tags.map((tag) => tag.toLowerCase());
    const lowerFeatures = (features ?? []).map((feature) => feature.toLowerCase());
    const java = lowerTags.includes('minecraft') || lowerFeatures.includes('eula');

    return java && !lowerTags.includes('proxy') && !lowerTags.includes('bedrock');
}

function useShowsVersion({ server }: ScreenContext): boolean {
    return server ? showsVersion(server.attributes.egg_tags ?? [], server.attributes.egg_features) : false;
}

export default definePterodactylExtension({
    setup({ screens }) {
        screens.register('version', () => import('./screens/VersionScreen'), { visible: useShowsVersion });
    },
});
