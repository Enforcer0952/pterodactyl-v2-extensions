<?php

namespace PalworldTools\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
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

            DB::table(self::TABLE)->updateOrInsert(['server_id' => $server->id], [
                'user_id' => $userId,
                'payload' => json_encode($plan, JSON_THROW_ON_ERROR),
                // One that is already stopping was stopped on purpose, so it stays off.
                'start_after' => $state === 'running',
                'status' => 'waiting',
                'error' => null,
                'created_at' => CarbonImmutable::now(),
                'updated_at' => CarbonImmutable::now(),
            ]);

            if ($state === 'running') {
                $this->power->send($server, 'stop');
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

        if ($row !== null && $row->status === 'waiting' && $row->start_after) {
            try {
                if ($this->state->read($server)->state === 'offline') {
                    $this->power->send($server, 'start');
                }
            } catch (DaemonConnectionException) {
                // The node is unreachable; the server cannot be started from here anyway.
            }
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
            $plan = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
            $this->locked($server, function () use ($server, $plan): void {
                $current = $this->files->read($server);
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

        DB::table(self::TABLE)->where('id', $row->id)->delete();

        if ($row->start_after) {
            try {
                $this->power->send($server, 'start');
            } catch (Throwable $throwable) {
                // The settings are written; only the start failed. Say so on the page.
                DB::table(self::TABLE)->insert([
                    'server_id' => $server->id,
                    'user_id' => $row->user_id,
                    'payload' => json_encode(['raw' => null, 'edits' => [], 'vars' => [], 'secrets' => false]),
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
