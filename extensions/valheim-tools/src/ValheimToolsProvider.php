<?php

namespace ValheimTools;

use Pterodactyl\Extensions\ExtensionProvider;
use Pterodactyl\Services\Extensions\ExtensionSettingDefinition;
use Pterodactyl\Services\Extensions\ExtensionSettingsDefinition;

class ValheimToolsProvider extends ExtensionProvider
{
    public function boot(): void
    {
        // Services read settings through this binding, so they work on every panel build.
        $this->app->instance('ext.valheim-tools.settings', $this->settings());

        // Loads routes/server.php at /api/client/servers/{server}/extensions/valheim-tools.
        $this->registerApiRoutes();
        $this->loadExtensionMigrations();

        $this->registerPermissions('Valheim: players, access lists, worlds, mods and server settings.', [
            'view' => 'See who is online and who was seen recently.',
            'players' => 'Ban players, make them admin or permit them from the Players card.',
            'lists' => 'See and change the admin, banned and permitted lists.',
            'worlds' => 'See worlds and backups, and switch, back up, restore and delete worlds.',
            'mods' => 'Search Thunderstore, and install, update and remove BepInEx mods.',
            'settings' => 'See and change the server name, password, visibility and crossplay.',
        ]);

        $this->registerSettings(new ExtensionSettingsDefinition($this->settings(), [
            ExtensionSettingDefinition::make('keep_backups', 'keep_backups', 5, ['required', 'integer', 'min:1', 'max:50'])
                ->label('Backups to keep per world')
                ->help('When a world has more backups made here than this, the oldest ones are deleted after a new backup. Valheim\'s own automatic backups are not counted.')
                ->field('number'),
            ExtensionSettingDefinition::make('steam_id_format', 'steam_id_format', 'v', ['required', 'string', 'in:v,steam,plain'])
                ->label('How Steam players are written to the lists')
                ->help('Valheim 1.0 writes IDs as V_ followed by the Steam ID. Servers before 1.0 use the plain Steam ID, and Steam_ also works. Existing lines are never rewritten.')
                ->field('select', [
                    ['value' => 'v', 'label' => 'V_76561198051014133 (Valheim 1.0)'],
                    ['value' => 'steam', 'label' => 'Steam_76561198051014133'],
                    ['value' => 'plain', 'label' => '76561198051014133 (before 1.0)'],
                ]),
        ]));
    }
}
