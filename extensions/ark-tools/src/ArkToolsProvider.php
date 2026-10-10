<?php

namespace ArkTools;

use Pterodactyl\Extensions\ExtensionProvider;

class ArkToolsProvider extends ExtensionProvider
{
    public function boot(): void
    {
        // Loads routes/server.php at /api/client/servers/{server}/extensions/ark-tools.
        $this->registerApiRoutes();

        $this->registerPermissions('ARK: players, whitelist, bans, admin commands and restarts.', [
            'view' => 'See online players, the whitelist, ban list and admin list.',
            'kick' => 'Kick online players.',
            'ban' => 'Ban and unban players.',
            'whitelist' => 'Add and remove whitelisted players, and turn the whitelist on or off.',
            'admin' => 'Run admin commands (save the world, wipe wild dinos, broadcast) and edit the admin list.',
            'restart' => 'Start, cancel and plan safe restarts with an in-game countdown.',
        ]);
    }
}
