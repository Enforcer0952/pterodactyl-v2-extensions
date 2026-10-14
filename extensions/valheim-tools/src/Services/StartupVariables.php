<?php

namespace ValheimTools\Services;

use Illuminate\Support\Facades\Validator;
use Pterodactyl\Contracts\Servers\UpdatesServerVariable;
use Pterodactyl\Models\EggVariable;
use Pterodactyl\Models\Server;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Reads and writes the server's startup variables the way the Startup page does: only
 * variables the egg lets users see and edit, and only values the egg's own rules accept.
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

    /**
     * Check the values against the egg's rules without saving. Returns an error per variable.
     *
     * @param  array<string, string>  $values  variable => value
     * @return array<string, string>
     */
    public function problems(Server $server, array $values): array
    {
        $problems = [];
        foreach ($values as $env => $value) {
            $variable = $this->variable($server, $env);
            if ($variable === null || ! $variable->user_viewable) {
                $problems[$env] = 'This server has no '.$env.' startup variable.';
            } elseif (! $variable->user_editable) {
                $problems[$env] = 'The '.$env.' startup variable is read-only on this server.';
            } else {
                $rules = trim((string) ($variable->rules ?? ''));
                if ($rules !== '') {
                    $validator = Validator::make(['value' => $value], ['value' => $rules]);
                    if ($validator->fails()) {
                        $problems[$env] = (string) $validator->errors()->first('value');
                    }
                }
            }
        }

        return $problems;
    }

    /**
     * Save several variables, all or none: nothing is written unless every value passes.
     *
     * @param  array<string, string>  $values
     */
    public function setMany(Server $server, array $values): void
    {
        $problems = $this->problems($server, $values);
        if ($problems !== []) {
            throw new HttpException(422, implode(' ', $problems));
        }

        foreach ($values as $env => $value) {
            $variable = $this->variable($server, $env);
            if ($variable !== null && (string) ($variable->server_value ?? '') !== $value) {
                $this->updater->update($server, $variable->id, $value);
            }
        }
    }

    private function variable(Server $server, string $env): ?EggVariable
    {
        $variable = $server->variables()->where('env_variable', $env)->first();

        return $variable instanceof EggVariable ? $variable : null;
    }
}
