<?php

namespace McConsole;

use Pterodactyl\Extensions\ExtensionProvider;

class McConsoleProvider extends ExtensionProvider
{
    public function boot(): void
    {
        // Loads routes/server.php at /api/client/servers/{server}/extensions/mc-console.
        $this->registerApiRoutes();
    }
}
