<?php

namespace McConsole;

use Pterodactyl\Extensions\ExtensionProvider;

class McConsoleProvider extends ExtensionProvider
{
    public function boot(): void
    {
        // Loads routes/server.php at /api/client/servers/{server}/extensions/mc-console.
        $this->registerApiRoutes();

        $this->registerPermissions('Minecraft console suggestions.', [
            'suggestions' => 'See plugin commands and the names of everyone who has joined in console suggestions. Vanilla commands only need console access.',
        ]);
    }
}
