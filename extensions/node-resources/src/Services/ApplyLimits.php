<?php

namespace NodeResources\Services;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use NodeResources\Support\Suggestions;
use Pterodactyl\Contracts\Servers\UpdatesServerBuild;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Facades\Daemon;
use Pterodactyl\Models\Server;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Sets the limit of a server that has none to the suggested one. The admin confirms the exact
 * numbers they saw; the suggestion is worked out again here and must still be the same, so
 * the browser cannot ask for another number and nothing changes under the admin's feet. It
 * goes through the panel's own build update, which also tells Wings.
 */
class ApplyLimits
{
    public function __construct(private readonly UpdatesServerBuild $build, private readonly Reports $reports) {}

    /**
     * @param  array<string, int>  $confirmed  'memory' and/or 'disk' in bytes, as the screen showed them
     * @return array{applied: array<string, int>, warning: ?string} the new limits in MB
     */
    public function apply(Server $server, array $confirmed): array
    {
        try {
            return Cache::lock('node-resources:limits:'.$server->id, 30)->block(5, fn (): array => $this->doApply($server, $confirmed));
        } catch (LockTimeoutException) {
            throw new HttpException(409, 'Limits for this server are being changed right now. Try again in a moment.');
        }
    }

    /**
     * @param  array<string, int>  $confirmed
     * @return array{applied: array<string, int>, warning: ?string}
     */
    private function doApply(Server $server, array $confirmed): array
    {
        $server->refresh();
        $suggestions = $this->reports->suggestionsFor($server);

        $input = [];
        $old = [];
        foreach (['memory', 'disk'] as $field) {
            if (! array_key_exists($field, $confirmed)) {
                continue;
            }
            if ((int) $server->{$field} !== 0) {
                throw new HttpException(409, ucfirst($field).' already has a limit on this server.');
            }
            if ($suggestions[$field] === null) {
                throw new HttpException(422, 'There is no suggestion for '.$field.' yet. It needs three days of data with the server running for at least six hours.');
            }
            if (! Suggestions::confirms($suggestions[$field], $confirmed[$field])) {
                throw new HttpException(409, 'The suggestion for '.$field.' has changed since you opened this page. Refresh the page and look at the new number.');
            }
            $input[$field] = $suggestions[$field];
            $old[$field] = (int) $server->{$field};
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

        Activity::event('server:node-resources.limits')->subject($server)->property(['old' => $old, 'new' => $input])->log();

        Cache::forget('node-resources:alerts');

        return ['applied' => $input, 'warning' => $this->syncWarning($server)];
    }

    /**
     * The panel logs a failed sync to Wings and carries on. Check on it here so the admin is told:
     * the limits are saved, but the running server keeps the old ones until Wings hears about them.
     */
    private function syncWarning(Server $server): ?string
    {
        try {
            Daemon::server($server)->sync();
        } catch (DaemonConnectionException) {
            return 'The new limits are saved, but the node could not be told right now. It picks them up the next time the server starts, or press Save on the server\'s Build tab once the node is back.';
        }

        return null;
    }
}
