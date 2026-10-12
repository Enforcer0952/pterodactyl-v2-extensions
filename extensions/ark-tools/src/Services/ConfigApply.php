<?php

namespace ArkTools\Services;

use ArkTools\Support\ConfigModel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Pterodactyl\Contracts\Servers\ReadsServerState;
use Pterodactyl\Contracts\Servers\SendsServerPower;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Models\Server;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Saving settings. ARK writes GameUserSettings.ini back to disk when it shuts down,
 * so anything written while it runs is lost. A stopped server is written right
 * away. A running one gets a countdown that saves the world and stops it; the
 * change waits in the database until the server is off, is then written and the
 * server started again (see tick(), run every minute by the scheduler).
 */
class ConfigApply
{
    public const string TABLE = 'ext_ark_tools_config_applies';

    /** A change still waiting after this long is given up on. */
    private const int GIVE_UP_MINUTES = 90;

    public function __construct(
        private readonly ConfigFiles $files,
        private readonly ConfigModel $model,
        private readonly Restarts $restarts,
        private readonly ReadsServerState $state,
        private readonly SendsServerPower $power,
    ) {}

    /**
     * @param  array<string, string>  $raw
     * @param  list<array{file: string, section: string, key: string, value: ?string}>  $edits
     * @param  array<string, string>  $hashes  file name => hash of the contents the editor started from
     * @return 'unchanged'|'saved'|'waiting'
     */
    public function save(Server $server, ?int $userId, array $raw, array $edits, array $hashes, int $minutes, bool $start): string
    {
        $current = $this->files->read($server);
        foreach ($hashes as $file => $hash) {
            if (array_key_exists($file, $current) && ConfigFiles::hash($current[$file]) !== $hash) {
                throw new HttpException(409, $file.' was changed since you opened this page. Reload to see the latest version.');
            }
        }

        try {
            $changed = $this->model->apply($current, $raw, $edits);
        } catch (InvalidArgumentException $exception) {
            throw new HttpException(422, $exception->getMessage());
        }
        if ($changed === []) {
            return 'unchanged';
        }

        $state = $this->serverState($server);
        if (in_array($state, ['offline', 'stopped'], true)) {
            DB::table(self::TABLE)->where('server_id', $server->id)->delete();
            $this->files->write($server, $changed, $current);
            if ($start) {
                $this->power->send($server, 'start');
            }

            return 'saved';
        }

        if ($this->restarts->status($server)['running'] !== null && ! $this->restarts->applying($server)) {
            throw new HttpException(409, 'A restart is already counting down. Save again once the server is back up, or cancel the restart.');
        }

        DB::transaction(function () use ($server, $userId, $raw, $edits): void {
            DB::table(self::TABLE)->where('server_id', $server->id)->delete();
            DB::table(self::TABLE)->insert([
                'server_id' => $server->id,
                'user_id' => $userId,
                'payload' => json_encode(['raw' => $raw, 'edits' => $edits], JSON_THROW_ON_ERROR),
                'start_after' => true,
                'status' => 'waiting',
                'error' => null,
                'created_at' => CarbonImmutable::now(),
                'updated_at' => CarbonImmutable::now(),
            ]);
        });

        // A server that is already stopping just needs to finish; otherwise count down.
        if ($state === 'running' && ! $this->restarts->applying($server)) {
            $this->restarts->stopToApply($server, $minutes);
        }

        return 'waiting';
    }

    /**
     * @return array{status: string, since: string, error: ?string}|null
     */
    public function status(Server $server): ?array
    {
        $row = DB::table(self::TABLE)->where('server_id', $server->id)->first();

        return $row === null ? null : [
            'status' => $row->status,
            'since' => CarbonImmutable::parse($row->created_at)->toAtomString(),
            'error' => $row->error,
        ];
    }

    /** Drop waiting settings and stop their countdown. */
    public function cancel(Server $server): void
    {
        DB::table(self::TABLE)->where('server_id', $server->id)->delete();
        if ($this->restarts->applying($server)) {
            $this->restarts->cancel($server);
        }
    }

    /** Write waiting settings to every server that has stopped. Run every minute. */
    public function tick(): void
    {
        foreach (DB::table(self::TABLE)->where('status', 'waiting')->get() as $row) {
            $server = Server::query()->find($row->server_id);
            if (! $server instanceof Server) {
                DB::table(self::TABLE)->where('id', $row->id)->delete();

                continue;
            }

            try {
                $this->applyWaiting($server, $row);
            } catch (Throwable $throwable) {
                $this->fail($row->id, 'Could not write the settings: '.$throwable->getMessage());
            }
        }
    }

    private function applyWaiting(Server $server, object $row): void
    {
        try {
            $state = $this->state->read($server)->state;
        } catch (DaemonConnectionException) {
            return; // Node unreachable: try again next minute.
        }

        if (! in_array($state, ['offline', 'stopped'], true)) {
            $age = CarbonImmutable::parse($row->created_at)->diffInMinutes(CarbonImmutable::now());
            if ($age > self::GIVE_UP_MINUTES && ! $this->restarts->applying($server)) {
                $this->fail($row->id, 'The server did not stop, so the settings were not written. Save them again.');
            }

            return;
        }

        // Claim the row first, so two overlapping runs can never write twice.
        $claimed = DB::table(self::TABLE)->where('id', $row->id)->where('status', 'waiting')->update(['status' => 'writing', 'updated_at' => CarbonImmutable::now()]);
        if ($claimed !== 1) {
            return;
        }

        $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
        $current = $this->files->read($server);
        $changed = $this->model->apply($current, $payload['raw'] ?? [], $payload['edits'] ?? []);
        if ($changed !== []) {
            $this->files->write($server, $changed, $current);
        }

        DB::table(self::TABLE)->where('id', $row->id)->delete();

        if ($row->start_after) {
            $this->power->send($server, 'start');
        }
    }

    private function fail(int $id, string $error): void
    {
        DB::table(self::TABLE)->where('id', $id)->update([
            'status' => 'failed',
            'error' => mb_substr($error, 0, 1000),
            'updated_at' => CarbonImmutable::now(),
        ]);
    }

    private function serverState(Server $server): string
    {
        try {
            return $this->state->read($server)->state;
        } catch (DaemonConnectionException) {
            throw new HttpException(502, 'Could not reach this server\'s node.');
        }
    }
}
