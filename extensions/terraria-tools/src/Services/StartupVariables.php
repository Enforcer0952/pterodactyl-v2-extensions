<?php

namespace TerrariaTools\Services;

use Pterodactyl\Facades\Activity;
use Pterodactyl\Models\EggVariable;
use Pterodactyl\Models\Server;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Reads and writes the server's startup variables the same way the Startup page
 * does: only variables the egg lets users see and edit.
 */
class StartupVariables
{
    public function get(Server $server, string $env): ?string
    {
        $variable = $this->variable($server, $env);

        return $variable === null ? null : (string) ($variable->server_value ?? $variable->default_value ?? '');
    }

    /** Whether the egg has this variable and shows it to users. */
    public function exists(Server $server, string $env): bool
    {
        $variable = $this->variable($server, $env);

        return $variable !== null && $variable->user_viewable;
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

        $server->serverVariables()->updateOrCreate(['variable_id' => $variable->id], ['variable_value' => $value]);

        Activity::event('server:startup.edit')
            ->subject($variable)
            ->property(['variable' => $env, 'old' => $original, 'new' => $value])
            ->log();
    }

    private function variable(Server $server, string $env): ?EggVariable
    {
        $variable = $server->variables()->where('env_variable', $env)->first();

        return $variable instanceof EggVariable ? $variable : null;
    }
}
