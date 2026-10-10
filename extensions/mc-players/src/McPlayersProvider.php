<?php

namespace McPlayers;

use Pterodactyl\Extensions\ExtensionProvider;

class McPlayersProvider extends ExtensionProvider
{
    public function boot(): void
    {
        // Loads routes/server.php at /api/client/servers/{server}/extensions/mc-players.
        $this->registerApiRoutes();

        $this->registerPermissions('Minecraft players, whitelist, bans and operators.', [
            'view' => 'See online players, the whitelist, bans and operators.',
            'kick' => 'Kick online players.',
            'ban' => 'Ban and unban players and IP addresses.',
            'whitelist' => 'Add and remove whitelisted players, and turn the whitelist on or off.',
            'op' => 'Give and take operator status.',
        ]);
    }
}
