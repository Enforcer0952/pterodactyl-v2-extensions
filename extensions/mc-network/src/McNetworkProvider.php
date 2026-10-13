<?php

namespace McNetwork;

use Pterodactyl\Extensions\ExtensionProvider;

class McNetworkProvider extends ExtensionProvider
{
    public function boot(): void
    {
        // Loads routes/server.php at /api/client/servers/{server}/extensions/mc-network.
        $this->registerApiRoutes();
    }
}
