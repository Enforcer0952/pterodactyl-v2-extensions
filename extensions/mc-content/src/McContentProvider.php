<?php

namespace McContent;

use McContent\Services\ModrinthClient;
use Pterodactyl\Extensions\ExtensionProvider;
use Pterodactyl\Services\Extensions\ExtensionSettingDefinition;
use Pterodactyl\Services\Extensions\ExtensionSettingsDefinition;

class McContentProvider extends ExtensionProvider
{
    public function register(): void
    {
        $this->app->singleton(ModrinthClient::class);
    }

    public function boot(): void
    {
        // Loads routes/server.php at /api/client/servers/{server}/extensions/mc-content.
        $this->registerApiRoutes();
        $this->loadExtensionMigrations();

        $this->registerSettings(new ExtensionSettingsDefinition($this->settings(), [
            ExtensionSettingDefinition::make('install_dependencies', 'install_dependencies', true, ['required', 'boolean'])
                ->label('Install required dependencies')
                ->help('When a plugin or mod needs another project to run, download that one too.')
                ->field('toggle'),
            ExtensionSettingDefinition::make('allow_prereleases', 'allow_prereleases', false, ['required', 'boolean'])
                ->label('Offer beta and alpha versions')
                ->help('Show pre-release versions in the version picker, not only releases.')
                ->field('toggle'),
            ExtensionSettingDefinition::make('results_per_page', 'results_per_page', 20, ['required', 'integer', 'min:5', 'max:50'])
                ->label('Search results per page')
                ->field('number')
                ->frontend()
                ->frontendType('number'),
        ]));
    }
}
