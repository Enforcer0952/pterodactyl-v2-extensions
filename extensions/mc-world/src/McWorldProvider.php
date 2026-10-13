<?php

namespace McWorld;

use Pterodactyl\Extensions\ExtensionProvider;
use Pterodactyl\Services\Extensions\ExtensionSettingDefinition;
use Pterodactyl\Services\Extensions\ExtensionSettingsDefinition;

class McWorldProvider extends ExtensionProvider
{
    public function boot(): void
    {
        // Services read settings through this binding, so they work on every panel build.
        $this->app->instance('ext.mc-world.settings', $this->settings());

        // Loads routes/server.php at /api/client/servers/{server}/extensions/mc-world.
        // Access follows the panel's own file and power permissions, so there are no extra ones.
        $this->registerApiRoutes();

        $this->registerSettings(new ExtensionSettingsDefinition($this->settings(), [
            ExtensionSettingDefinition::make('backups_to_keep', 'backups_to_keep', 5, ['required', 'integer', 'min:1', 'max:50'])
                ->label('Backups to keep per world')
                ->help('After a new world backup, older ones of the same world are deleted until this many are left.')
                ->field('number'),
        ]));
    }
}
