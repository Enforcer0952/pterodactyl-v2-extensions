import { ServerContentBlock, useCurrentServerRequired } from '@pterodactyl/sdk';
import PropertiesCard from '../PropertiesCard';
import WorldsCard from '../WorldsCard';

export default function WorldScreen() {
    const server = useCurrentServerRequired();
    const identifier = server.attributes.identifier;

    return (
        <ServerContentBlock title={'World'}>
            <div className={'mcw:flex mcw:flex-col mcw:gap-4'}>
                <WorldsCard identifier={identifier} />
                <PropertiesCard identifier={identifier} />
            </div>
        </ServerContentBlock>
    );
}
