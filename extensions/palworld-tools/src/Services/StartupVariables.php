<?php

namespace PalworldTools\Services;

use Illuminate\Support\Facades\Validator;
use Pterodactyl\Contracts\Servers\UpdatesServerVariable;
use Pterodactyl\Models\EggVariable;
use Pterodactyl\Models\Server;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Reads and writes the server's startup variables the way the Startup page does:
 * only variables the egg shows and lets users edit, and only values that pass the
 * egg's own rules for that variable.
 */
class StartupVariables
{
    public function __construct(private readonly UpdatesServerVariable $updater) {}

    /**
     * Visible variables by environment name.
     *
     * @return array<string, EggVariable>
     */
    public function visible(Server $server): array
    {
        $variables = [];
        foreach ($server->variables()->get() as $variable) {
            if ($variable instanceof EggVariable && $variable->user_viewable) {
                $variables[$variable->env_variable] = $variable;
            }
        }

        return $variables;
    }

    public function value(EggVariable $variable): string
    {
        return (string) ($variable->server_value ?? $variable->default_value ?? '');
    }

    public function editable(EggVariable $variable): bool
    {
        return $variable->user_viewable && $variable->user_editable;
    }

    /**
     * Check a value against the egg's rules for the variable.
     *
     * @throws HttpException 422 with the first problem
     */
    public function validate(EggVariable $variable, string $value): void
    {
        $rules = (string) $variable->rules;
        if ($rules === '') {
            return;
        }
        $validator = Validator::make(['value' => $value], ['value' => $rules], [], ['value' => $variable->name]);
        if ($validator->fails()) {
            throw new HttpException(422, (string) $validator->errors()->first('value'));
        }
    }

    public function set(Server $server, EggVariable $variable, string $value): void
    {
        if (! $this->editable($variable)) {
            throw new HttpException(403, 'The '.$variable->env_variable.' startup variable is read-only on this server.');
        }
        $this->validate($variable, $value);
        if ($this->value($variable) !== $value) {
            $this->updater->update($server, $variable->id, $value);
        }
    }
}
