<?php

namespace ArkTools\Services;

use Pterodactyl\Contracts\Servers\UpdatesServerVariable;
use Pterodactyl\Models\EggVariable;
use Pterodactyl\Models\Server;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Reads and writes the server's startup variables the same way the Startup page
 * does: only variables the egg lets users see and edit.
 */
class StartupVariables
{
    public function __construct(private readonly UpdatesServerVariable $updater) {}

    public function get(Server $server, string $env): ?string
    {
        $variable = $this->variable($server, $env);

        return $variable === null ? null : (string) ($variable->server_value ?? $variable->default_value ?? '');
    }

    public function editable(Server $server, string $env): bool
    {
        $variable = $this->variable($server, $env);

        return $variable !== null && $variable->user_viewable && $variable->user_editable;
    }

    public function set(Server $server, string $env, string $value): void
    {
        $variable = $this->variable($server, $env);
        if ($variable === null || ! $variable->user_viewable) {
            throw new HttpException(404, 'This server has no '.$env.' startup variable.');
        }
        if (! $variable->user_editable) {
            throw new HttpException(403, 'The '.$env.' startup variable is read-only on this server.');
        }

        $original = (string) ($variable->server_value ?? '');
        if ($original === $value) {
            return;
        }

        // The panel's action stores the value and records the change in the activity log.
        $this->updater->update($server, $variable->id, $value);
    }

    private function variable(Server $server, string $env): ?EggVariable
    {
        $variable = $server->variables()->where('env_variable', $env)->first();

        return $variable instanceof EggVariable ? $variable : null;
    }
}
