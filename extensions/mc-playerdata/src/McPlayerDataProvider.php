<?php

namespace McPlayerData;

use Pterodactyl\Extensions\ExtensionProvider;

class McPlayerDataProvider extends ExtensionProvider
{
    public function boot(): void
    {
        // Loads routes/server.php at /api/client/servers/{server}/extensions/mc-playerdata.
        $this->registerApiRoutes();

        $this->registerPermissions('Minecraft player data: inventory, ender chest, position and effects.', [
            'read' => 'View a player\'s saved inventory, ender chest, position, experience, health and effects.',
            'manage' => 'Edit a player\'s saved data while the server is stopped: remove items, clear the inventory, ender chest or effects, change game mode and move the player.',
        ]);
    }
}
