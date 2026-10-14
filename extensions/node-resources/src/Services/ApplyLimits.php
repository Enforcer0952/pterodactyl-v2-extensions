<?php

namespace NodeResources\Services;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Pterodactyl\Contracts\Servers\UpdatesServerBuild;
use Pterodactyl\Models\Server;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Sets the limit of a server that has none to the suggested one. The suggestion is worked
 * out again here, so the browser cannot ask for another number, and it goes through the
 * panel's own build update, which also tells Wings.
 */
class ApplyLimits
{
    public function __construct(private readonly UpdatesServerBuild $build, private readonly Reports $reports) {}

    /**
     * @param  list<string>  $fields  'memory' and/or 'disk'
     * @return array<string, int> the new limits in MB
     */
    public function apply(Server $server, array $fields): array
    {
        try {
            return Cache::lock('node-resources:limits:'.$server->id, 30)->block(5, fn (): array => $this->doApply($server, $fields));
        } catch (LockTimeoutException) {
            throw new HttpException(409, 'Limits for this server are being changed right now. Try again in a moment.');
        }
    }

    /**
     * @param  list<string>  $fields
     * @return array<string, int>
     */
    private function doApply(Server $server, array $fields): array
    {
        $server->refresh();
        $suggestions = $this->reports->suggestionsFor($server);

        $input = [];
        foreach (array_unique($fields) as $field) {
            if ($field !== 'memory' && $field !== 'disk') {
                continue;
            }
            if ((int) $server->{$field} !== 0) {
                throw new HttpException(409, ucfirst($field).' already has a limit on this server.');
            }
            if ($suggestions[$field] === null) {
                throw new HttpException(422, 'There is no suggestion for '.$field.' yet. It needs a day of data.');
            }
            $input[$field] = $suggestions[$field];
        }
        if ($input === []) {
            throw new HttpException(422, 'Nothing to apply.');
        }

        // The build update clears feature limits that are left out, so pass the current ones.
        $this->build->update($server, [
            ...$input,
            'database_limit' => $server->database_limit,
            'allocation_limit' => $server->allocation_limit,
            'backup_limit' => $server->backup_limit,
        ]);

        Cache::forget('node-resources:alerts');

        return $input;
    }
}
