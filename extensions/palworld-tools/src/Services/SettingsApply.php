<?php

namespace PalworldTools\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PalworldTools\Support\ApplyPlan;
use PalworldTools\Support\SettingsModel;
use Pterodactyl\Contracts\Servers\ReadsServerState;
use Pterodactyl\Contracts\Servers\SendsServerPower;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Models\Server;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Saving settings. Palworld can put its own copy of PalWorldSettings.ini back when it
 * shuts down, so a file written under a running server may be lost. A stopped server
 * is written right away. A running one is stopped, the change waits in the database
 * until it is off, is then written and the server started again. The page asks for
 * that every few seconds (process()) and the scheduler does it every minute (tick()).
 */
class SettingsApply
{
    public const string TABLE = 'ext_palworld_tools_settings_applies';

    /** A change still waiting after this long is given up on. */
    private const int GIVE_UP_MINUTES = 30;

    private const array EMPTY_PLAN = ['raw' => null, 'edits' => [], 'vars' => [], 'secrets' => false];

    /** A write that was claimed but never finished (worker died) is retried after this long. */
    private const int STALE_WRITE_MINUTES = 10;

    public function __construct(
        private readonly SettingsFiles $files,
        private readonly SettingsModel $model,
        private readonly StartupVariables $variables,
        private readonly ReadsServerState $state,
        private readonly SendsServerPower $power,
    ) {}

    /**
     * @param  array{raw: ?string, edits: list<array{key: string, value: ?string}>, vars: array<string, string>, secrets: bool}  $plan
     * @return 'unchanged'|'saved'|'waiting'
     */
    public function save(Server $server, ?int $userId, array $plan, string $hash, bool $restart, bool $start): string
    {
        return $this->locked($server, function () use ($server, $userId, $plan, $hash, $restart, $start): string {
            if (! ApplyPlan::validHash($hash)) {
                throw new HttpException(422, 'The page is out of date. Reload it and try again.');
            }

            $current = $this->files->read($server);
            if (SettingsFiles::hash($this->model, $current) !== $hash) {
                throw new HttpException(409, 'PalWorldSettings.ini was changed since you opened this page. Reload to see the latest version.');
            }

            try {
                $new = $this->model->apply($this->files->base($server, $current), $plan['raw'], $plan['edits'], $plan['secrets']);
            } catch (InvalidArgumentException $exception) {
                throw new HttpException($exception->getCode() === SettingsModel::FORBIDDEN ? 403 : 422, $exception->getMessage());
            }
            if ($new === $current && $plan['vars'] === []) {
                return 'unchanged';
            }

            $state = $this->serverState($server);
            if ($state === 'offline') {
                DB::table(SettingsApply::TABLE)->where('server_id', $server->id)->delete();
                $this->write($server, $new, $current, $plan['vars']);
                if ($start) {
                    $this->power->send($server, 'start');
                }

                return 'saved';
            }

            if ($state === 'starting') {
                throw new HttpException(409, 'The server is starting. Save again once it is running or stopped.');
            }
            if (! $restart) {
                throw new HttpException(409, 'The server is '.$state.'. Save with a restart, or stop the server first.');
            }
            if (DB::table(self::TABLE)->where('server_id', $server->id)->where('status', 'writing')->exists()) {
                throw new HttpException(409, 'Earlier settings are being written right now. Save again in a minute.');
            }

            // The plan can hold passwords, so it is stored encrypted.
            $pending = DB::table(self::TABLE)->where('server_id', $server->id)->first();
            DB::table(self::TABLE)->updateOrInsert(['server_id' => $server->id], [
                'user_id' => $userId,
                'payload' => $this->seal($plan + ['hash' => $hash]),
                // One that is already stopping was stopped on purpose, so it stays off,
                // unless settings that wait already asked for a start.
                'start_after' => ApplyPlan::startAfter($pending === null ? null : (bool) $pending->start_after, $state),
                'status' => 'waiting',
                'error' => null,
                'created_at' => CarbonImmutable::now(),
                'updated_at' => CarbonImmutable::now(),
            ]);

            if ($state === 'running') {
                try {
                    $this->power->send($server, 'stop');
                } catch (Throwable $throwable) {
                    // Without a stop nothing would ever write these settings.
                    DB::table(self::TABLE)->where('server_id', $server->id)->delete();

                    throw $throwable;
                }
            }

            return 'waiting';
        });
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

    /** Drop waiting settings. When the server is already off it is started again, so cancelling never leaves it off. */
    public function cancel(Server $server): void
    {
        $row = DB::table(self::TABLE)->where('server_id', $server->id)->first();
        if ($row !== null && $row->status === 'writing') {
            throw new HttpException(409, 'The settings are being written right now and can no longer be cancelled.');
        }

        DB::table(self::TABLE)->where('server_id', $server->id)->delete();

        if ($row === null || $row->status !== 'waiting' || ! $row->start_after) {
            return;
        }

        try {
            Cache::forget('resources:'.$server->uuid); // the panel caches power state for 20 s
            $state = $this->state->read($server)->state;
        } catch (DaemonConnectionException) {
            return; // The node is unreachable; the server cannot be started from here anyway.
        }

        if ($state === 'offline') {
            $this->power->send($server, 'start');
        } elseif ($state === 'stopping') {
            // A server cannot be started while it shuts down. Keep a row with nothing to
            // write, so the next check starts it once it is off.
            $this->queueStart($server, $row->user_id);
        }
    }

    /** Write the waiting settings of one server if it has stopped. */
    public function process(Server $server): void
    {
        $row = DB::table(self::TABLE)->where('server_id', $server->id)->where('status', 'waiting')->first();
        if ($row !== null) {
            $this->applyWaiting($server, $row);
        }
    }

    /** Write waiting settings to every server that has stopped. Run every minute. */
    public function tick(): void
    {
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
            return; // Node unreachable: try again next time.
        }

        if ($state !== 'offline') {
            $age = CarbonImmutable::parse($row->created_at)->diffInMinutes(CarbonImmutable::now());
            if ($age > self::GIVE_UP_MINUTES) {
                $this->fail($row->id, 'The server did not stop, so the settings were not written. Save them again.');
            }

            return;
        }

        // Claim the row first, so two overlapping runs can never write twice.
        $claimed = DB::table(self::TABLE)->where('id', $row->id)->where('status', 'waiting')->update(['status' => 'writing', 'updated_at' => CarbonImmutable::now()]);
        if ($claimed !== 1) {
            return;
        }

        try {
            $plan = $this->open($row->payload);
            $changedBy = false;
            $this->locked($server, function () use ($server, $plan, &$changedBy): void {
                $current = $this->files->read($server);
                if (ApplyPlan::changedWhileWaiting($plan['raw'] ?? null, $plan['hash'] ?? null, SettingsFiles::hash($this->model, $current))) {
                    // Somebody edited the file while the server was stopping: do not overwrite that.
                    $changedBy = true;

                    return;
                }
                $new = $this->model->apply($this->files->base($server, $current), $plan['raw'] ?? null, $plan['edits'] ?? [], (bool) ($plan['secrets'] ?? false));
                $this->write($server, $new, $current, $plan['vars'] ?? []);
            });
        } catch (DaemonConnectionException) {
            // A node hiccup: nothing was written, so try again next time.
            DB::table(self::TABLE)->where('id', $row->id)->update(['status' => 'waiting', 'updated_at' => CarbonImmutable::now()]);

            return;
        } catch (Throwable $throwable) {
            $this->fail($row->id, 'Could not write the settings: '.mb_substr($throwable->getMessage(), 0, 500));

            return;
        }

        if ($changedBy) {
            $this->fail($row->id, 'PalWorldSettings.ini was changed while the server was stopping, so the settings were not written. Save them again.'.($row->start_after ? ' The server was started again.' : ''));
            if ($row->start_after) {
                $this->startWithSavesLock($server, null, false);
            }

            return;
        }

        DB::table(self::TABLE)->where('id', $row->id)->delete();

        if ($row->start_after) {
            $this->startWithSavesLock($server, $row->user_id);
        }
    }

    /**
     * Start the server, but never while a backup or restore holds the saves lock: the
     * world would be started halfway through being replaced. When the lock stays taken,
     * a row with nothing to write is kept so the next check tries again.
     */
    private function startWithSavesLock(Server $server, ?int $userId, bool $report = true): void
    {
        try {
            Cache::lock(Saves::lockKey($server), 120)->block(10, function () use ($server): void {
                $this->power->send($server, 'start');
            });
        } catch (LockTimeoutException) {
            $this->queueStart($server, $userId);
        } catch (Throwable $throwable) {
            if ($report) {
                // The settings are written; only the start failed. Say so on the page.
                DB::table(self::TABLE)->updateOrInsert(['server_id' => $server->id], [
                    'user_id' => $userId,
                    'payload' => $this->seal(self::EMPTY_PLAN),
                    'start_after' => false,
                    'status' => 'failed',
                    'error' => 'The settings were written, but the server could not be started: '.mb_substr($throwable->getMessage(), 0, 500).' Start it with the power buttons.',
                    'created_at' => CarbonImmutable::now(),
                    'updated_at' => CarbonImmutable::now(),
                ]);
            }
        }
    }

    /** A row with nothing to write that only makes the next check start the server. */
    private function queueStart(Server $server, ?int $userId): void
    {
        DB::table(self::TABLE)->updateOrInsert(['server_id' => $server->id], [
            'user_id' => $userId,
            'payload' => $this->seal(self::EMPTY_PLAN),
            'start_after' => true,
            'status' => 'waiting',
            'error' => null,
            'created_at' => CarbonImmutable::now(),
            'updated_at' => CarbonImmutable::now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function seal(array $plan): string
    {
        return Crypt::encryptString(json_encode($plan, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function open(string $payload): array
    {
        try {
            $payload = Crypt::decryptString($payload);
        } catch (DecryptException) {
            // A row written by version 1.0.0, before payloads were encrypted.
        }

        return json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Write the file, then the startup variables the egg's parser would otherwise
     * copy over it at the next start.
     *
     * @param  array<string, string>  $vars  environment name => value
     */
    private function write(Server $server, string $contents, ?string $current, array $vars): void
    {
        if ($contents !== $current) {
            $this->files->write($server, $contents, $current);
        }
        if ($vars !== []) {
            $visible = $this->variables->visible($server);
            foreach ($vars as $env => $value) {
                if (isset($visible[$env])) {
                    $this->variables->set($server, $visible[$env], (string) $value);
                }
            }
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
            \Illuminate\Support\Facades\Cache::forget('resources:'.$server->uuid); // the panel caches power state for 20 s
            return $this->state->read($server)->state;
        } catch (DaemonConnectionException) {
            throw new HttpException(502, 'Could not reach this server\'s node.');
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $run
     * @return T
     */
    private function locked(Server $server, callable $run): mixed
    {
        try {
            return Cache::lock('ext:palworld-tools:settings:'.$server->uuid, 120)->block(10, $run);
        } catch (LockTimeoutException) {
            throw new HttpException(409, 'Another change to the settings is running. Try again in a moment.');
        }
    }
}
