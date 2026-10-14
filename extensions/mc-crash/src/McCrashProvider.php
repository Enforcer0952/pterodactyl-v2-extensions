<?php

namespace McCrash;

use Pterodactyl\Extensions\ExtensionProvider;
use Pterodactyl\Services\Extensions\ExtensionSettingDefinition;
use Pterodactyl\Services\Extensions\ExtensionSettingsDefinition;

class McCrashProvider extends ExtensionProvider
{
    public function boot(): void
    {
        // Services read settings through this binding, so they work on every panel build.
        $this->app->instance('ext.mc-crash.settings', $this->settings());

        // Loads routes/server.php at /api/client/servers/{server}/extensions/mc-crash.
        $this->registerApiRoutes();

        $this->registerPermissions('Minecraft crash reports and log errors.', [
            'view' => 'See crash reports and log errors with what broke and how to fix it. Also needs the file permission to read file contents.',
            'share' => 'Upload a crash report or log to mclo.gs, where it gets a public link.',
        ]);

        $this->registerSettings(new ExtensionSettingsDefinition($this->settings(), [
            ExtensionSettingDefinition::make('sharing', 'sharing', 'on', ['required', 'string', 'in:on,off'])
                ->label('Sharing to mclo.gs')
                ->help('Lets users upload a crash report or log to mclo.gs, where it gets a public link. Nothing is sent until a user confirms. "Off" removes the Share button.')
                ->field('select', [
                    ['value' => 'on', 'label' => 'On'],
                    ['value' => 'off', 'label' => 'Off'],
                ])
                ->frontend()
                ->frontendType('string'),
        ]));
    }
}
