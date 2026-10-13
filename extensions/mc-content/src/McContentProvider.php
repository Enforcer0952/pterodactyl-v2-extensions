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
            ExtensionSettingDefinition::make('project_links', 'project_links', 'popup', ['required', 'string', 'in:popup,modrinth'])
                ->label('Clicking a project')
                ->help('Show the project page in a popup inside the panel, or open it on modrinth.com in a new tab.')
                ->field('select', [
                    ['value' => 'popup', 'label' => 'Open a popup in the panel'],
                    ['value' => 'modrinth', 'label' => 'Open modrinth.com in a new tab'],
                ])
                ->frontend()
                ->frontendType('string'),
            ExtensionSettingDefinition::make('results_per_page', 'results_per_page', 20, ['required', 'integer', 'min:5', 'max:50'])
                ->label('Search results per page')
                ->field('number')
                ->frontend()
                ->frontendType('number'),
        ]));
    }
}
