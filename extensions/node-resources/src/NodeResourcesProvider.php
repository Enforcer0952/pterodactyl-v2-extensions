<?php

namespace NodeResources;

use Illuminate\Console\Scheduling\Schedule;
use NodeResources\Services\Collector;
use NodeResources\Services\Pruner;
use NodeResources\Support\Retention;
use NodeResources\Support\Warnings;
use Pterodactyl\Extensions\ExtensionProvider;
use Pterodactyl\Services\Extensions\ExtensionSettingDefinition;
use Pterodactyl\Services\Extensions\ExtensionSettingsDefinition;

class NodeResourcesProvider extends ExtensionProvider
{
    public function register(): void
    {
        $this->app->singleton(Collector::class);
    }

    public function boot(): void
    {
        // Services read settings through this binding, so they work on every panel build.
        $this->app->instance('ext.node-resources.settings', $this->settings());

        // Admin routes at /api/admin/extensions/node-resources, server routes at
        // /api/client/servers/{server}/extensions/node-resources.
        $this->registerApiRoutes();
        $this->loadExtensionMigrations();

        $this->registerPermissions('Node Resources: usage history of a server.', [
            'history' => 'See the History card (CPU, memory and disk over time) under the console.',
        ]);

        $this->registerSettings(new ExtensionSettingsDefinition($this->settings(), [
            ExtensionSettingDefinition::make('retention', 'retention', Retention::DEFAULT, ['required', 'string', 'in:'.implode(',', Retention::OPTIONS)])
                ->label('Keep history for')
                ->help('One-minute readings are kept for 48 hours, then summed up into 5-minute points. A shorter choice deletes older points at the next nightly clean-up.')
                ->field('select', [
                    ['value' => '30d', 'label' => '30 days (5-minute points)'],
                    ['value' => '90d', 'label' => '90 days (5-minute points)'],
                    ['value' => '1y', 'label' => '1 year (hourly points, 5-minute points for the last 30 days)'],
                ]),
            ExtensionSettingDefinition::make('disk_warning_percent', 'disk_warning_percent', Warnings::DISK_PERCENT, ['required', 'integer', 'min:50', 'max:99'])
                ->label('Warn when the disk is more than this full (%)')
                ->help('Needs the probe on that node.')
                ->field('number'),
            ExtensionSettingDefinition::make('forecast_warning_days', 'forecast_warning_days', Warnings::FORECAST_DAYS, ['required', 'integer', 'min:1', 'max:90'])
                ->label('Warn when the disk is expected to be full within (days)')
                ->help('The forecast is a straight line through the last 7 days of disk use.')
                ->field('number'),
        ]));

        // Reads every node and server once a minute. Runs never overlap.
        $this->registerSchedule(function (Schedule $schedule): void {
            $schedule->call(fn () => $this->app->make(Collector::class)->tick())
                ->name('node-resources:collect')
                ->everyMinute()
                ->withoutOverlapping(10);

            $schedule->call(fn () => $this->app->make(Pruner::class)->prune())
                ->name('node-resources:prune')
                ->dailyAt('03:25')
                ->withoutOverlapping(60);
        });
    }
}
