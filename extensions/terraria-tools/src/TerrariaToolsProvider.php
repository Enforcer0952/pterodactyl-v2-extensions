<?php

namespace TerrariaTools;

use Pterodactyl\Extensions\ExtensionProvider;
use Pterodactyl\Services\Extensions\ExtensionSettingDefinition;
use Pterodactyl\Services\Extensions\ExtensionSettingsDefinition;

class TerrariaToolsProvider extends ExtensionProvider
{
    public function boot(): void
    {
        // Loads routes/server.php at /api/client/servers/{server}/extensions/terraria-tools.
        $this->registerApiRoutes();

        $this->registerPermissions('Terraria: players, bans, admin commands, worlds and server settings.', [
            'view' => 'See who is online.',
            'kick' => 'Kick online players.',
            'ban' => 'Ban players, see the ban list and remove bans.',
            'admin' => 'Run admin commands (save, settle, time, say) and view and change serverconfig.txt.',
            'worlds' => 'See worlds and backups, and switch, back up, restore, create and delete worlds.',
        ]);

        $this->registerSettings(new ExtensionSettingsDefinition($this->settings(), [
            ExtensionSettingDefinition::make('backups_keep', 'backups_keep', 5, ['required', 'integer', 'min:1', 'max:50'])
                ->label('Backups to keep per world')
                ->help('When a world has more backups than this, the oldest ones are deleted after a new backup is made.')
                ->field('number'),
        ]));
    }
}
