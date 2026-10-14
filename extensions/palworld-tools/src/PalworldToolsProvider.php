<?php

namespace PalworldTools;

use Illuminate\Console\Scheduling\Schedule;
use PalworldTools\Services\ServerFiles;
use PalworldTools\Services\SettingsApply;
use Pterodactyl\Extensions\ExtensionProvider;
use Pterodactyl\Services\Extensions\ExtensionSettingDefinition;
use Pterodactyl\Services\Extensions\ExtensionSettingsDefinition;

class PalworldToolsProvider extends ExtensionProvider
{
    public function register(): void
    {
        $this->app->singleton(ServerFiles::class);
    }

    public function boot(): void
    {
        // Services read settings through this binding, so they work on every panel build.
        $this->app->instance('ext.palworld-tools.settings', $this->settings());

        // Loads routes/server.php at /api/client/servers/{server}/extensions/palworld-tools.
        $this->registerApiRoutes();

        $this->registerPermissions('Palworld: players, settings, world saves and the ban list.', [
            'view' => 'See online players and the server info on the console page.',
            'players' => 'Kick, ban and unban players, announce messages and save the world (needs the REST API).',
            'settings' => 'See and change PalWorldSettings.ini. Saving needs the file permission, and a restart needs the power permissions.',
            'saves' => 'See worlds and backups, back up, restore and delete backups, and manage the ban list file.',
        ]);

        $this->registerSettings(new ExtensionSettingsDefinition($this->settings(), [
            ExtensionSettingDefinition::make('backups_keep', 'backups_keep', 5, ['required', 'integer', 'min:1', 'max:50'])
                ->label('Backups to keep per world')
                ->help('When a world has more backups than this, the oldest ones are deleted after a new backup is made.')
                ->field('number'),
        ]));

        // Settings saved while the server ran are written once it has stopped.
        $this->registerSchedule(function (Schedule $schedule): void {
            $schedule->call(fn () => $this->app->make(SettingsApply::class)->tick())
                ->name('palworld-tools:settings-apply')
                ->everyMinute()
                ->withoutOverlapping(10);
        });
    }
}
