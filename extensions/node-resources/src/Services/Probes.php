<?php

namespace NodeResources\Services;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use NodeResources\Support\Probe;
use NodeResources\Support\Tables;
use Pterodactyl\Contracts\Eggs\ImportsEggs;
use Pterodactyl\Contracts\Servers\CreatesServers;
use Pterodactyl\Contracts\Servers\DeletesServers;
use Pterodactyl\Contracts\Servers\ReadsServerLogs;
use Pterodactyl\Contracts\Servers\ReadsServerState;
use Pterodactyl\Contracts\Servers\SendsServerPower;
use Pterodactyl\Data\EggImportData;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * The probe: a tiny server on each node that prints the node's free disk space, memory and
 * load once a minute. Wings reports neither free disk nor free memory of the host, and a
 * container sees the host's /proc/meminfo and the disk its volumes live on.
 *
 * Setting one up imports the "Node Probe" egg (once) and creates a server from it on the
 * node. The collector reads its console and starts it again when it has stopped.
 */
class Probes
{
    public const string EGG_NAME = 'Node Probe';

    public const string EGG_AUTHOR = 'Enforcer0952@users.noreply.github.com';

    /** The smallest server the panel allows: 64 MB, 50 MB disk, a tenth of a thread. */
    public const array LIMITS = ['memory' => 64, 'swap' => 0, 'disk' => 50, 'io' => 10, 'cpu' => 10];

    /** A reading older than this is stale. */
    public const int FRESH_SECONDS = 180;

    /** A stopped probe is started again at most this often. */
    public const int RESTART_SECONDS = 300;

    public function __construct(
        private readonly CreatesServers $create,
        private readonly DeletesServers $delete,
        private readonly ImportsEggs $eggs,
        private readonly ReadsServerLogs $logs,
        private readonly ReadsServerState $state,
        private readonly SendsServerPower $power,
    ) {}

    public function row(Node $node): ?object
    {
        return DB::table(Tables::PROBES)->where('node_id', $node->id)->first();
    }

    /**
     * What the admin pages show. With $live the probe's power state is asked from Wings.
     *
     * @return array{state: string, server: array{id: string, name: string}|null, last_at: ?string}
     */
    public function status(Node $node, bool $live = false, ?int $now = null): array
    {
        $now ??= time();
        $row = $this->row($node);
        $server = $row === null ? null : Server::query()->without('allocation')->find($row->server_id);
        if ($row === null || $server === null) {
            return ['state' => 'none', 'server' => null, 'last_at' => null];
        }

        $fresh = $row->last_line_at !== null && $now - (int) $row->last_line_at <= self::FRESH_SECONDS;
        $state = match (true) {
            $server->status === Server::STATUS_INSTALLING => 'installing',
            in_array($server->status, [Server::STATUS_INSTALL_FAILED, Server::STATUS_REINSTALL_FAILED], true) => 'failed',
            $server->status === Server::STATUS_SUSPENDED => 'suspended',
            $fresh => 'ok',
            $row->last_line_at === null => 'waiting',
            default => 'stale',
        };

        if ($live && in_array($state, ['waiting', 'stale'], true)) {
            try {
                $power = $this->state->read($server)->state;
                $state = $power === 'offline' ? 'offline' : ($power === 'running' ? $state : 'starting');
            } catch (DaemonConnectionException) {
                $state = 'unreachable';
            }
        }

        return [
            'state' => $state,
            'server' => ['id' => $server->uuidShort, 'name' => $server->name],
            'last_at' => $row->last_line_at === null ? null : date(DATE_ATOM, (int) $row->last_line_at),
        ];
    }

    /**
     * The probe's newest reading, or null when there is no probe, it is not running yet or
     * it has gone quiet. A stopped probe is started again.
     *
     * @return array<string, mixed>|null
     */
    public function read(Node $node, int $now): ?array
    {
        $row = $this->row($node);
        if ($row === null) {
            return null;
        }

        $server = Server::query()->without('allocation')->find($row->server_id);
        if ($server === null) {
            DB::table(Tables::PROBES)->where('id', $row->id)->delete();

            return null;
        }
        if ($server->status !== null) {
            return null;
        }
        $server->setRelation('node', $node);

        try {
            $power = $this->state->read($server)->state;
            if ($power === 'offline') {
                $this->restart($server, $row, $now);

                return null;
            }
            if ($power !== 'running') {
                return null;
            }

            $reading = Probe::latest($this->logs->read($server, ReadsServerLogs::MAX_LINES));
        } catch (DaemonConnectionException) {
            return null;
        }

        if ($reading !== null && (int) $row->last_ts !== $reading['ts']) {
            DB::table(Tables::PROBES)->where('id', $row->id)->update(['last_ts' => $reading['ts'], 'last_line_at' => $now, 'updated_at' => date('Y-m-d H:i:s', $now)]);
            $row->last_line_at = $now;
        }

        return $row->last_line_at !== null && $now - (int) $row->last_line_at <= self::FRESH_SECONDS ? $reading : null;
    }

    /**
     * Import the egg if the panel does not have it, create the probe server on the node and
     * start it. Doing it twice returns the probe that is already there.
     *
     * @return array{state: string, server: array{id: string, name: string}|null, last_at: ?string}
     */
    public function setup(Node $node, User $admin): array
    {
        try {
            return Cache::lock('node-resources:probe:'.$node->id, 60)->block(10, fn (): array => $this->doSetup($node, $admin));
        } catch (LockTimeoutException) {
            throw new HttpException(409, 'The probe is already being set up. Try again in a moment.');
        }
    }

    public function remove(Node $node): void
    {
        $row = $this->row($node);
        if ($row === null) {
            return;
        }

        $server = Server::query()->find($row->server_id);
        try {
            if ($server !== null) {
                $this->delete->delete($server);
            }
        } catch (DaemonConnectionException) {
            throw new HttpException(502, 'Could not reach the node to remove the probe server. Try again when it is online.');
        }

        DB::table(Tables::PROBES)->where('node_id', $node->id)->delete();
    }

    /** @return array{state: string, server: array{id: string, name: string}|null, last_at: ?string} */
    private function doSetup(Node $node, User $admin): array
    {
        $existing = $this->row($node);
        if ($existing !== null && Server::query()->whereKey($existing->server_id)->exists()) {
            return $this->status($node);
        }

        $egg = $this->egg();
        $allocation = Allocation::query()->unassigned()->where('node_id', $node->id)->orderBy('port')->first();
        if ($allocation === null) {
            throw new HttpException(422, 'This node has no free allocation. Add one on the node\'s Allocation tab; the probe needs one, but does not listen on it.');
        }
        $image = array_values($egg->docker_images)[0] ?? null;
        if (! is_string($image)) {
            throw new HttpException(422, 'The Node Probe egg has no Docker image.');
        }

        try {
            $server = $this->create->create([
                'name' => 'Node probe',
                'description' => 'Reports the free disk space and memory of this node to the Node Resources extension. Safe to delete with the extension.',
                'owner_id' => $admin->id,
                'egg_id' => $egg->id,
                'image' => $image,
                'startup' => (string) $egg->startup,
                'environment' => [],
                'oom_disabled' => false,
                'database_limit' => 0,
                'allocation_limit' => 0,
                'backup_limit' => 0,
                'allocation_id' => $allocation->id,
                'start_on_completion' => true,
                ...self::LIMITS,
            ]);
        } catch (DaemonConnectionException) {
            throw new HttpException(502, 'Could not reach the node to create the probe server.');
        }

        DB::table(Tables::PROBES)->updateOrInsert(['node_id' => $node->id], [
            'server_id' => $server->id,
            'created_by' => $admin->id,
            'last_ts' => null,
            'last_line_at' => null,
            'last_start_at' => null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->status($node);
    }

    private function egg(): Egg
    {
        $egg = Egg::query()->where('name', self::EGG_NAME)->where('author', self::EGG_AUTHOR)->first();
        if ($egg !== null) {
            return $egg;
        }

        $json = file_get_contents(dirname(__DIR__, 2).'/resources/node-probe-egg.json');
        if ($json === false) {
            throw new HttpException(500, 'The Node Probe egg file is missing from the extension.');
        }

        try {
            return $this->eggs->importParsed(EggImportData::fromJson($json));
        } catch (Throwable $exception) {
            throw new HttpException(500, 'The Node Probe egg could not be imported: '.$exception->getMessage(), $exception);
        }
    }

    private function restart(Server $server, object $row, int $now): void
    {
        if ($row->last_start_at !== null && $now - (int) $row->last_start_at < self::RESTART_SECONDS) {
            return;
        }

        DB::table(Tables::PROBES)->where('id', $row->id)->update(['last_start_at' => $now]);

        try {
            $this->power->send($server, 'start');
        } catch (DaemonConnectionException) {
            // Tried again in five minutes.
        }
    }
}
