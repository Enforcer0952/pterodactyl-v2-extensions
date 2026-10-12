<?php

namespace ArkTools;

use ArkTools\Services\ConfigApply;
use Illuminate\Console\Scheduling\Schedule;
use Pterodactyl\Extensions\ExtensionProvider;

class ArkToolsProvider extends ExtensionProvider
{
    public function boot(): void
    {
        // Loads routes/server.php at /api/client/servers/{server}/extensions/ark-tools.
        $this->registerApiRoutes();

        $this->registerPermissions('ARK: players, whitelist, bans, admin commands, restarts and server settings.', [
            'view' => 'See online players, the whitelist, ban list and admin list.',
            'kick' => 'Kick online players.',
            'ban' => 'Ban and unban players.',
            'whitelist' => 'Add and remove whitelisted players, and turn the whitelist on or off.',
            'admin' => 'Run admin commands (save the world, wipe wild dinos, broadcast) and edit the admin list.',
            'restart' => 'Start, cancel and plan safe restarts with an in-game countdown.',
            'config' => 'View and change GameUserSettings.ini and Game.ini. Saving restarts a running server.',
        ]);

        // Settings saved while the server ran are written once it has stopped.
        $this->registerSchedule(function (Schedule $schedule): void {
            $schedule->call(fn () => $this->app->make(ConfigApply::class)->tick())
                ->name('ark-tools:config-apply')
                ->everyMinute()
                ->withoutOverlapping(10);
        });
    }
}
