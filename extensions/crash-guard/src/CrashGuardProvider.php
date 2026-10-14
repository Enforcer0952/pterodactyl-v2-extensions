<?php

namespace CrashGuard;

use CrashGuard\Services\Watcher;
use Illuminate\Console\Scheduling\Schedule;
use Pterodactyl\Extensions\ExtensionProvider;
use Pterodactyl\Services\Extensions\ExtensionSettingDefinition;
use Pterodactyl\Services\Extensions\ExtensionSettingsDefinition;

class CrashGuardProvider extends ExtensionProvider
{
    public function boot(): void
    {
        // Services read settings through this binding, so they work on every panel build.
        $this->app->instance('ext.crash-guard.settings', $this->settings());

        // Server routes at /api/client/servers/{server}/extensions/crash-guard,
        // admin routes at /api/admin/extensions/crash-guard.
        $this->registerApiRoutes();
        $this->loadExtensionMigrations();

        $this->registerPermissions('Crash Guard: stop servers that crash over and over.', [
            'view' => 'See the Crash Guard page, its crash log and the banner on the console page.',
            'manage' => 'Turn Crash Guard on or off for the server, change its limits, and clear a stopped server. Starting the server again also needs the Start permission.',
        ]);

        $this->registerSettings(new ExtensionSettingsDefinition($this->settings(), [
            ExtensionSettingDefinition::make('default_enabled', 'default_enabled', false, ['required', 'boolean'])
                ->label('Guard every server by default')
                ->help('Servers without their own choice follow this. Turn it on to guard all servers; a server can still be switched off on its Crash Guard page.')
                ->field('toggle'),
            ExtensionSettingDefinition::make('default_max_crashes', 'default_max_crashes', 3, ['required', 'integer', 'min:2', 'max:20'])
                ->label('Default crashes before stopping')
                ->help('A server that crashes this many times within the default window is stopped.')
                ->field('number'),
            ExtensionSettingDefinition::make('default_window_minutes', 'default_window_minutes', 10, ['required', 'integer', 'min:1', 'max:120'])
                ->label('Default window in minutes')
                ->help('How far back crashes are counted.')
                ->field('number'),
            ExtensionSettingDefinition::make('keep_days', 'keep_days', 30, ['required', 'integer', 'min:1', 'max:365'])
                ->label('Keep the crash log for (days)')
                ->help('Older entries are deleted.')
                ->field('number'),
        ]));

        // Reads every guarded server once a minute. Ticks never overlap.
        $this->registerSchedule(function (Schedule $schedule): void {
            $schedule->call(fn () => $this->app->make(Watcher::class)->tick())
                ->name('crash-guard:watch')
                ->everyMinute()
                ->withoutOverlapping(10);
        });
    }
}
