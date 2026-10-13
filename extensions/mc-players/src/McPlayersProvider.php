<?php

namespace McPlayers;

use Pterodactyl\Extensions\ExtensionProvider;
use Pterodactyl\Services\Extensions\ExtensionSettingDefinition;
use Pterodactyl\Services\Extensions\ExtensionSettingsDefinition;

class McPlayersProvider extends ExtensionProvider
{
    public function boot(): void
    {
        // Services read settings through this binding, so they work on every panel build.
        $this->app->instance('ext.mc-players.settings', $this->settings());

        // Loads routes/server.php at /api/client/servers/{server}/extensions/mc-players.
        $this->registerApiRoutes();

        $this->registerPermissions('Minecraft players, whitelist, bans and operators.', [
            'view' => 'See online players, the whitelist, bans and operators.',
            'kick' => 'Kick online players.',
            'ban' => 'Ban and unban players and IP addresses.',
            'whitelist' => 'Add and remove whitelisted players, and turn the whitelist on or off.',
            'op' => 'Give and take operator status.',
        ]);

        $this->registerSettings(new ExtensionSettingsDefinition($this->settings(), [
            ExtensionSettingDefinition::make('skin_source', 'skin_source', 'mc-heads', ['required', 'string', 'in:mc-heads,crafatar,off'])
                ->label('Player skins')
                ->help('Where skins and heads are loaded from. "Off" makes no outside requests and shows a default skin.')
                ->field('select', [
                    ['value' => 'mc-heads', 'label' => 'mc-heads.net'],
                    ['value' => 'crafatar', 'label' => 'crafatar.com'],
                    ['value' => 'off', 'label' => 'Off'],
                ])
                ->frontend()
                ->frontendType('string'),
            ExtensionSettingDefinition::make('offline_skins', 'offline_skins', 'by_name', ['required', 'string', 'in:by_name,default'])
                ->label('Skins on offline-mode servers')
                ->help('Offline-mode UUIDs are not real accounts. Look the skin up by name (right for most cracked players using a premium name), or show the default skin.')
                ->field('select', [
                    ['value' => 'by_name', 'label' => 'Look up by name'],
                    ['value' => 'default', 'label' => 'Default skin'],
                ])
                ->frontend()
                ->frontendType('string'),
            ExtensionSettingDefinition::make('profile_links', 'profile_links', ['namemc', 'laby'], ['required', 'array'])
                ->label('Profile links')
                ->help('Outside profile pages linked from a player.')
                ->field('multiselect', [
                    ['value' => 'namemc', 'label' => 'NameMC'],
                    ['value' => 'laby', 'label' => 'Laby.net'],
                    ['value' => 'mcuuid', 'label' => 'mcuuid.net'],
                ])
                ->frontend()
                ->frontendType('array'),
            ExtensionSettingDefinition::make('mcbans', 'mcbans', 'on', ['required', 'string', 'in:on,off'])
                ->label('MCBans lookup')
                ->help('Show a player\'s global ban history from mcbans.com on their profile. Sends the player\'s UUID to mcbans.com; only Mojang accounts are looked up.')
                ->field('select', [
                    ['value' => 'on', 'label' => 'On'],
                    ['value' => 'off', 'label' => 'Off'],
                ])
                ->frontend()
                ->frontendType('string'),
            ExtensionSettingDefinition::make('link_behaviour', 'link_behaviour', 'popup', ['required', 'string', 'in:popup,page'])
                ->label('Clicking a player')
                ->help('Open the player in a popup over the console, or go straight to the full player page.')
                ->field('select', [
                    ['value' => 'popup', 'label' => 'Popup'],
                    ['value' => 'page', 'label' => 'Full page'],
                ])
                ->frontend()
                ->frontendType('string'),
        ]));
    }
}
