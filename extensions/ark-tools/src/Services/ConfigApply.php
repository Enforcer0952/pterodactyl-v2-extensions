<?php

namespace ArkTools\Services;

use ArkTools\Support\ApplyPlan;
use ArkTools\Support\ConfigModel;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
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

    /** ARK rewrites this file itself when it stops, so a changed hash there is expected. */
    private const array REWRITTEN_ON_SHUTDOWN = ['GameUserSettings.ini'];

    /** A write that was claimed but never finished (worker died) is retried after this long. */
    private const int STALE_WRITE_MINUTES = 10;

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
        if (! ApplyPlan::validHashes($hashes)) {
            throw new HttpException(422, 'The page is out of date. Reload it and try again.');
        }

        $current = $this->files->read($server);
        $changedNow = ApplyPlan::changedFiles($hashes, array_map(fn (?string $contents): string => ConfigFiles::hash($contents), $current));
        if ($changedNow !== []) {
            throw new HttpException(409, $changedNow[0].' was changed since you opened this page. Reload to see the latest version.');
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
        if ($state === 'starting') {
            throw new HttpException(409, 'The server is starting. Save again once it is running or stopped.');
        }
        if ($state === 'offline') {
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

        $claimed = DB::table(self::TABLE)->where('server_id', $server->id)->where('status', 'writing')->exists();
        if ($claimed) {
            throw new HttpException(409, 'Earlier settings are being written right now. Save again in a minute.');
        }

        // A running server is started again after the write. One that is already
        // stopping stays off, unless settings that wait already asked for a start.
        $pending = DB::table(self::TABLE)->where('server_id', $server->id)->first();
        DB::table(self::TABLE)->updateOrInsert(['server_id' => $server->id], [
            'user_id' => $userId,
            'payload' => $this->seal(['raw' => $raw, 'edits' => $edits, 'hashes' => $hashes]),
            'start_after' => ApplyPlan::startAfter($pending === null ? null : (bool) $pending->start_after, $state),
            'status' => 'waiting',
            'error' => null,
            'created_at' => CarbonImmutable::now(),
            'updated_at' => CarbonImmutable::now(),
        ]);

        // A server that is already stopping just needs to finish; otherwise count down.
        if ($state === 'running' && ! $this->restarts->applying($server)) {
            try {
                $this->restarts->stopToApply($server, $minutes);
            } catch (Throwable $throwable) {
                // Without a countdown nothing would ever write these settings.
                DB::table(self::TABLE)->where('server_id', $server->id)->delete();

                throw $throwable;
            }
        }

        return 'waiting';
    }

    /** Whether settings that wait will start the server again. */
    public function startsAfter(Server $server): bool
    {
        return (bool) DB::table(self::TABLE)->where('server_id', $server->id)->where('status', 'waiting')->value('start_after');
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

    /**
     * Drop waiting settings and stop their countdown. When the countdown already
     * stopped the server, it is started again, so cancelling never leaves it off.
     */
    public function cancel(Server $server): void
    {
        $row = DB::table(self::TABLE)->where('server_id', $server->id)->first();
        if ($row !== null && $row->status === 'writing') {
            throw new HttpException(409, 'The settings are being written right now and can no longer be cancelled.');
        }

        DB::table(self::TABLE)->where('server_id', $server->id)->delete();
        if ($this->restarts->applying($server)) {
            $this->restarts->cancel($server);
        }

        if ($row === null || $row->status !== 'waiting' || ! $row->start_after) {
            return;
        }

        try {
            \Illuminate\Support\Facades\Cache::forget('resources:'.$server->uuid); // the panel caches power state for 20 s
            $state = $this->state->read($server)->state;
        } catch (DaemonConnectionException) {
            return; // The node is unreachable; the server cannot be started from here anyway.
        }

        if ($state === 'offline') {
            $this->power->send($server, 'start');
        } elseif ($state === 'stopping') {
            // A server cannot be started while it shuts down. Keep a row with nothing
            // to write, so the minute check starts it once it is off.
            DB::table(self::TABLE)->insert([
                'server_id' => $server->id,
                'user_id' => $row->user_id,
                'payload' => $this->seal(['raw' => [], 'edits' => [], 'hashes' => []]),
                'start_after' => true,
                'status' => 'waiting',
                'error' => null,
                'created_at' => CarbonImmutable::now(),
                'updated_at' => CarbonImmutable::now(),
            ]);
        }
    }

    /** Write waiting settings to every server that has stopped. Run every minute. */
    public function tick(): void
    {
        // A worker that died mid-write leaves a claimed row; give it another go.
        DB::table(self::TABLE)
            ->where('status', 'writing')
            ->where('updated_at', '<', CarbonImmutable::now()->subMinutes(self::STALE_WRITE_MINUTES))
            ->update(['status' => 'waiting', 'updated_at' => CarbonImmutable::now()]);

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
            \Illuminate\Support\Facades\Cache::forget('resources:'.$server->uuid); // the panel caches power state for 20 s
            $state = $this->state->read($server)->state;
        } catch (DaemonConnectionException) {
            return; // Node unreachable: try again next minute.
        }

        if ($state !== 'offline') {
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

        // The countdown's last task would stop the server again once it is started.
        $this->restarts->dropApplySchedule($server);

        try {
            $payload = $this->open($row->payload);
            $current = $this->files->read($server);
            $changedFiles = ApplyPlan::changedFiles(ApplyPlan::guarded($payload['hashes'] ?? [], array_keys($payload['raw'] ?? []), self::REWRITTEN_ON_SHUTDOWN), array_map(fn (?string $contents): string => ConfigFiles::hash($contents), $current));
            if ($changedFiles !== []) {
                // Somebody edited the file while the server was stopping: do not overwrite that.
                $this->fail($row->id, $changedFiles[0].' was changed while the server was stopping, so the settings were not written. Save them again.'.($row->start_after ? ' The server was started again.' : ''));
                if ($row->start_after) {
                    try {
                        $this->power->send($server, 'start');
                    } catch (Throwable) {
                        // Nothing was written; the failure notice stays on the page.
                    }
                }

                return;
            }
            $changed = $this->model->apply($current, $payload['raw'] ?? [], $payload['edits'] ?? []);
            if ($changed !== []) {
                $this->files->write($server, $changed, $current);
            }
        } catch (DaemonConnectionException) {
            // A node hiccup: nothing was written, so try again next minute.
            DB::table(self::TABLE)->where('id', $row->id)->update(['status' => 'waiting', 'updated_at' => CarbonImmutable::now()]);

            return;
        }

        DB::table(self::TABLE)->where('id', $row->id)->delete();

        if ($row->start_after) {
            try {
                $this->power->send($server, 'start');
            } catch (Throwable $throwable) {
                // The settings are written; only the start failed. Say so on the page.
                DB::table(self::TABLE)->insert([
                    'server_id' => $server->id,
                    'user_id' => $row->user_id,
                    'payload' => $this->seal(['raw' => [], 'edits' => []]),
                    'start_after' => false,
                    'status' => 'failed',
                    'error' => 'The settings were written, but the server could not be started: '.mb_substr($throwable->getMessage(), 0, 500).' Start it with the power buttons.',
                    'created_at' => CarbonImmutable::now(),
                    'updated_at' => CarbonImmutable::now(),
                ]);
            }
        }
    }

    /**
     * The payload can hold passwords from the ini files, so it is stored encrypted.
     *
     * @param  array<string, mixed>  $payload
     */
    private function seal(array $payload): string
    {
        return Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function open(string $payload): array
    {
        try {
            $payload = Crypt::decryptString($payload);
        } catch (DecryptException) {
            // A row written before payloads were encrypted.
        }

        return json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
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
            \Illuminate\Support\Facades\Cache::forget('resources:'.$server->uuid); // the panel caches power state for 20 s
            return $this->state->read($server)->state;
        } catch (DaemonConnectionException) {
            throw new HttpException(502, 'Could not reach this server\'s node.');
        }
    }
}
