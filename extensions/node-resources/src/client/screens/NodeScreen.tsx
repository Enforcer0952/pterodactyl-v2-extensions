import { Alert, useCurrentResource } from '@pterodactyl/sdk';
import NodeView from '../NodeView';

/** The Resources tab on a node's admin page. */
export default function NodeScreen() {
    const context = useCurrentResource();
    if (!context || context.kind !== 'admin.node') return <Alert type={'danger'}>Open this tab from a node.</Alert>;

    return <NodeView nodeId={context.resource.attributes.id} />;
}
