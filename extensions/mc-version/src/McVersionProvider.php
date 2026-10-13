<?php

namespace McVersion;

use Pterodactyl\Extensions\ExtensionProvider;

class McVersionProvider extends ExtensionProvider
{
    public function boot(): void
    {
        // Loads routes/server.php at /api/client/servers/{server}/extensions/mc-version.
        // The page uses the panel's own file, startup and Docker image permissions,
        // so there are no extension permissions to register.
        $this->registerApiRoutes();
    }
}
