<?php

namespace TerrariaTools\Services;

use Illuminate\Support\Facades\Validator;
use Pterodactyl\Contracts\Servers\UpdatesServerVariable;
use Pterodactyl\Models\EggVariable;
use Pterodactyl\Models\Server;
use Symfony\Component\HttpKernel\Exception\HttpException;
use TerrariaTools\Support\Terraria;

/**
 * Reads and writes the server's startup variables the same way the Startup page
 * does: only variables the egg lets users see and edit, and only values that are
 * safe to sit in a start command and pass the egg's own rules. The write goes through
 * the panel's own action, which also records the activity.
 */
class StartupVariables
{
    public function __construct(private readonly UpdatesServerVariable $updater) {}

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

    /** Whether the variable can be changed to this value: editable, shell-safe and within the egg's rules. */
    public function canSet(Server $server, string $env, string $value): bool
    {
        $variable = $this->variable($server, $env);

        return $variable !== null
            && $variable->user_viewable
            && $variable->user_editable
            && Terraria::shellSafe($value)
            && $this->passesRules($variable, $value);
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
        if (! Terraria::shellSafe($value)) {
            throw new HttpException(422, 'The '.$env.' value has characters that are not allowed in a startup variable.');
        }
        if (! $this->passesRules($variable, $value)) {
            throw new HttpException(422, 'The '.$env.' value is not accepted by this server\'s startup rules.');
        }

        if ((string) ($variable->server_value ?? '') !== $value) {
            $this->updater->update($server, $variable->id, $value);
        }
    }

    private function passesRules(EggVariable $variable, string $value): bool
    {
        $rules = trim((string) ($variable->rules ?? ''));

        return $rules === '' || ! Validator::make(['value' => $value], ['value' => $rules])->fails();
    }

    private function variable(Server $server, string $env): ?EggVariable
    {
        $variable = $server->variables()->where('env_variable', $env)->first();

        return $variable instanceof EggVariable ? $variable : null;
    }
}
