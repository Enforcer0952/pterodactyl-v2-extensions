<?php

namespace McVersion\Services;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use McVersion\Support\Backups;
use McVersion\Support\JavaImages;
use McVersion\Support\Software;
use McVersion\Support\Warnings;
use Pterodactyl\Contracts\Files\DeletesFiles;
use Pterodactyl\Contracts\Files\PullsFiles;
use Pterodactyl\Contracts\Files\ReadsFileContents;
use Pterodactyl\Contracts\Files\RenamesFiles;
use Pterodactyl\Contracts\Files\WritesFileContents;
use Pterodactyl\Contracts\Servers\ReadsServerState;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Exceptions\Http\Server\FileSizeTooLargeException;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Plans, applies and rolls back a change of server software and version. Each step is
 * a core action (rename, pull, write, startup variable, Docker image), so the panel's
 * own checks and activity log apply; this class only decides the order and undoes a
 * half-finished switch.
 */
class Switcher
{
    /** Jars up to this size are read back after the download to check their checksum. */
    private const int VERIFY_MAX_BYTES = 24 * 1024 * 1024;

    /** Anything smaller is an error page or a cut-off download, not a server jar. */
    private const int MIN_JAR_BYTES = 10_000;

    private const int PULL_TIMEOUT = 300;

    public function __construct(
        private readonly Upstream $upstream,
        private readonly Overview $overview,
        private readonly Startup $startup,
        private readonly ServerFiles $files,
        private readonly Detector $detector,
        private readonly PullsFiles $puller,
        private readonly RenamesFiles $renamer,
        private readonly DeletesFiles $deleter,
        private readonly WritesFileContents $writer,
        private readonly ReadsFileContents $reader,
        private readonly ReadsServerState $state,
    ) {}

    /**
     * What a switch would do, without doing it.
     *
     * @return array<string, mixed>
     */
    public function plan(Server $server, string $software, string $version, bool $experimental): array
    {
        $build = $this->upstream->resolve($software, $version, $experimental);
        $now = $this->overview->effective($server);
        $warnings = $this->warnings($now, $software, $version, $build);
        $changes = $this->variableChanges($server, $software, $version, $build);

        $recommended = JavaImages::recommend($this->startup->images($server), $version);

        return [
            'target' => [
                'software' => $software,
                'label' => Software::label($software),
                'version' => $version,
                'build' => $build['build'],
                'channel' => $build['channel'],
                'stable' => $build['stable'],
                'size' => $build['size'],
                'checksum' => $build['hash']['algo'] ?? null,
            ],
            'jar' => $now['jar'],
            'keeps_copy' => ServerFiles::file($now['root'], $now['jar']) !== null,
            'warnings' => $warnings,
            'needs_confirmation' => Warnings::needsConfirmation($warnings),
            'variables' => array_map(fn (array $change): array => ['name' => $change['variable']->env_variable, 'from' => $this->startup->value($change['variable']), 'to' => $change['to']], $changes),
            'java' => [
                'recommended' => $recommended,
                'current' => $server->image,
                'change' => $recommended !== null && $recommended['image'] !== $server->image && ! $this->startup->imageIsLocked($server),
                'locked' => $this->startup->imageIsLocked($server),
            ],
        ];
    }

    /**
     * @return array{software: string, version: string, build: ?string, notes: list<string>}
     */
    public function apply(Server $server, User $user, string $software, string $version, bool $experimental, bool $confirmed, bool $setJava): array
    {
        $this->requireOffline($server);

        return $this->locked($server, function () use ($server, $user, $software, $version, $experimental, $confirmed, $setJava): array {
            $this->requireOffline($server);

            $build = $this->upstream->resolve($software, $version, $experimental);
            $now = $this->overview->effective($server, fresh: true);

            $warnings = $this->warnings($now, $software, $version, $build);
            if (Warnings::needsConfirmation($warnings) && ! $confirmed) {
                throw new HttpException(409, 'This change needs your confirmation. Review the warnings and confirm.');
            }

            $jar = $now['jar'];
            $changes = $this->variableChanges($server, $software, $version, $build);
            $before = [
                'software' => $now['pending']['software'] ?? $now['software'],
                'version' => $now['pending']['version'] ?? $now['version'],
                'variables' => array_combine(
                    array_map(fn (array $change): string => $change['variable']->env_variable, $changes),
                    array_map(fn (array $change): string => $this->startup->value($change['variable']), $changes),
                ),
                'image' => $server->image,
            ];

            $copy = $this->swapIn($server, $now, $build, $before, ['software' => $software, 'version' => $version, 'build' => $build['build']], $jar);

            $notes = [];
            foreach ($changes as $change) {
                try {
                    if (! $this->startup->set($server, $change['variable'], $change['to'])) {
                        $notes[] = $change['variable']->env_variable.' was not changed: the egg does not allow that value.';
                    }
                } catch (\Throwable) {
                    $notes[] = $change['variable']->env_variable.' could not be changed.';
                }
            }

            $recommended = JavaImages::recommend($this->startup->images($server), $version);
            if ($setJava && $recommended !== null && $recommended['image'] !== $server->image) {
                $notes[] = $this->switchImage($server, $user, $recommended['image']);
            } elseif ($setJava && $recommended === null) {
                $notes[] = 'This egg offers no Java version that fits '.$version.'. Ask an administrator to add one.';
            }

            $this->prune($server, $user, $jar);
            $this->detector->forget($server);

            Activity::event('server:version.switch')->subject($server)->property([
                'software' => $software,
                'version' => $version,
                'build' => $build['build'],
                'from' => $before['software'].' '.$before['version'],
                'copy' => $copy,
            ])->log();

            return ['software' => $software, 'version' => $version, 'build' => $build['build'], 'notes' => array_values(array_filter($notes))];
        });
    }

    /**
     * Put the newest copy of the jar back, with the variables and Java image it had.
     * The jar being replaced is kept as a copy too, so restoring twice undoes itself.
     *
     * @return array{software: ?string, version: ?string, notes: list<string>}
     */
    public function rollback(Server $server, User $user, bool $restoreJava): array
    {
        $this->requireOffline($server);

        return $this->locked($server, function () use ($server, $user, $restoreJava): array {
            $this->requireOffline($server);

            $now = $this->overview->effective($server, fresh: true);
            $target = $now['copies'][0] ?? null;
            if ($target === null) {
                throw new HttpException(404, 'There is no earlier jar to restore.');
            }

            $jar = $now['jar'];
            $meta = $target['meta'] ?? Backups::readMeta(null);
            $names = ServerFiles::names($now['root']);
            $current = ServerFiles::file($now['root'], $jar);

            $variables = [];
            foreach (array_keys($meta['variables']) as $name) {
                $variable = $this->startup->find($server, [$name]);
                if ($variable !== null) {
                    $variables[$name] = $variable;
                }
            }

            $swap = Backups::name($jar, Backups::freshStamp($jar, $names, time()));
            try {
                if ($current !== null) {
                    $this->rename($server, $jar, $swap);
                }
                $this->rename($server, $target['name'], $jar);
            } catch (DaemonConnectionException $exception) {
                if ($current !== null) {
                    $this->quietly(fn () => $this->rename($server, $swap, $jar));
                }
                throw new HttpException(502, 'The jar could not be restored: '.$exception->getMessage(), $exception);
            }

            if ($current !== null) {
                $this->quietly(fn () => $this->writer->write($server, '/'.Backups::metaName($swap), Backups::writeMeta([
                    'software' => $now['pending']['software'] ?? $now['software'],
                    'version' => $now['pending']['version'] ?? $now['version'],
                    'variables' => array_map(fn ($variable): string => $this->startup->value($variable), $variables),
                    'image' => $server->image,
                ], ['software' => $meta['software'], 'version' => $meta['version'], 'build' => null], time())));
            }

            $notes = [];
            foreach ($variables as $name => $variable) {
                try {
                    if (! $this->startup->set($server, $variable, $meta['variables'][$name])) {
                        $notes[] = $name.' was not changed: it is read-only or the egg does not allow that value.';
                    }
                } catch (\Throwable) {
                    $notes[] = $name.' could not be changed.';
                }
            }

            if ($restoreJava && $meta['image'] !== null && $meta['image'] !== $server->image) {
                $notes[] = $this->switchImage($server, $user, $meta['image']);
            }

            $this->prune($server, $user, $jar);
            $this->detector->forget($server);

            Activity::event('server:version.rollback')->subject($server)->property([
                'restored' => $target['name'],
                'software' => $meta['software'],
                'version' => $meta['version'],
                'kept_as' => $current === null ? null : $swap,
            ])->log();

            return ['software' => $meta['software'], 'version' => $meta['version'], 'notes' => array_values(array_filter($notes))];
        });
    }

    /**
     * Rename the old jar to a copy, download the new one and check it. Anything that
     * goes wrong puts the old jar back.
     *
     * @param  array<string, mixed>  $now
     * @param  array<string, mixed>  $build
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function swapIn(Server $server, array $now, array $build, array $before, array $after, string $jar): ?string
    {
        $names = ServerFiles::names($now['root']);
        $hadJar = ServerFiles::file($now['root'], $jar) !== null;
        $copy = $hadJar ? Backups::name($jar, Backups::freshStamp($jar, $names, time())) : null;
        $moved = false;
        $started = false;

        try {
            if ($copy !== null) {
                $this->rename($server, $jar, $copy);
                $moved = true;
            }

            // The panel's pull action records the download in the activity log.
            $started = true;
            $this->puller->pull($server, $build['url'], '/', [
                'filename' => $jar,
                'use_header' => false,
                'foreground' => true,
                'timeout' => self::PULL_TIMEOUT,
            ]);

            $this->verify($server, $jar, $build);
        } catch (DaemonConnectionException $exception) {
            $this->undo($server, $jar, $started, $moved ? $copy : null);
            throw new HttpException(502, ($started ? 'The download failed' : 'The jar could not be renamed').' ('.$exception->getMessage().'). The server was left as it was.', $exception);
        } catch (HttpException $exception) {
            $this->undo($server, $jar, $started, $moved ? $copy : null);
            throw $exception;
        } catch (\Throwable $exception) {
            $this->undo($server, $jar, $started, $moved ? $copy : null);
            throw new HttpException(502, 'The download failed. The server was left as it was.', $exception);
        }

        if ($copy !== null) {
            $this->quietly(fn () => $this->writer->write($server, '/'.Backups::metaName($copy), Backups::writeMeta($before, $after, time())));
        }
        $this->files->forget($server);

        return $copy;
    }

    /**
     * The file is there, as large as the API says, a zip archive, and for small jars
     * the checksum matches. Wings has no way to check a checksum itself, and reading a
     * 50 MB jar into the panel's memory is not worth it, so large jars are checked by
     * size only; the download is over HTTPS from the project's own host.
     *
     * @param  array<string, mixed>  $build
     */
    private function verify(Server $server, string $jar, array $build): void
    {
        $this->files->forget($server);
        $entry = ServerFiles::file($this->files->list($server, '/'), $jar);
        if ($entry === null) {
            throw new HttpException(502, 'The download finished but '.$jar.' is not in the server files.');
        }

        $size = (int) ($entry['size'] ?? 0);
        if ($size < self::MIN_JAR_BYTES) {
            throw new HttpException(502, 'The downloaded file is only '.$size.' bytes, so it is not a server jar. The server was left as it was.');
        }
        if ($build['size'] !== null && $size !== $build['size']) {
            throw new HttpException(502, 'The downloaded file is '.$size.' bytes but should be '.$build['size'].'. The server was left as it was.');
        }
        if ($size > self::VERIFY_MAX_BYTES) {
            return;
        }

        try {
            $content = $this->reader->read($server, '/'.$jar, self::VERIFY_MAX_BYTES);
        } catch (FileSizeTooLargeException|DaemonConnectionException) {
            return;
        }

        if (! str_starts_with($content, "PK\x03\x04")) {
            throw new HttpException(502, 'The downloaded file is not a jar. The server was left as it was.');
        }
        if ($build['hash'] !== null && ! hash_equals($build['hash']['value'], hash($build['hash']['algo'], $content))) {
            throw new HttpException(502, 'The downloaded file does not match the checksum its source published. The server was left as it was.');
        }
    }

    /**
     * Remove a download that failed and bring the previous jar back. The jar is only
     * deleted once the download started, so a failed rename never costs the old one.
     */
    private function undo(Server $server, string $jar, bool $downloadStarted, ?string $copy): void
    {
        if ($downloadStarted) {
            $this->quietly(function () use ($server, $jar): void {
                $this->files->forget($server);
                if (ServerFiles::file($this->files->list($server, '/'), $jar) !== null) {
                    $this->deleter->delete($server, '/', [$jar]);
                }
            });
        }

        if ($copy !== null) {
            $this->quietly(fn () => $this->rename($server, $copy, $jar));
        }
        $this->files->forget($server);
    }

    /** Keep the newest copies only. Deleting needs the file.delete permission. */
    private function prune(Server $server, User $user, string $jar): void
    {
        if (! $user->can('file.delete', $server)) {
            return;
        }

        $this->quietly(function () use ($server, $jar): void {
            $this->files->forget($server);
            $old = Backups::prune($jar, ServerFiles::names($this->files->list($server, '/')));
            if ($old !== []) {
                $this->deleter->delete($server, '/', $old);
            }
        });
    }

    private function switchImage(Server $server, User $user, string $image): string
    {
        if (! $user->can('startup.docker-image', $server)) {
            return 'The Java version was not changed: you do not have permission to change the Docker image.';
        }
        if ($this->startup->imageIsLocked($server)) {
            return 'The Java version was not changed: an administrator set this server\'s Docker image by hand.';
        }

        try {
            $this->startup->setImage($server, $image);
        } catch (HttpException $exception) {
            return 'The Java version was not changed: '.$exception->getMessage();
        }

        return '';
    }

    /**
     * The startup variables that change with a switch: the version, and the build
     * number (a number for Paper and Purpur, "latest" for software without build numbers).
     *
     * @param  array<string, mixed>  $build
     * @return list<array{variable: \Pterodactyl\Models\EggVariable, to: string}>
     */
    private function variableChanges(Server $server, string $software, string $version, array $build): array
    {
        $changes = [];

        $variable = $this->startup->find($server, Startup::VERSION_VARIABLES);
        if ($variable !== null && $this->startup->value($variable) !== $version && $this->startup->accepts($variable, $version)) {
            $changes[] = ['variable' => $variable, 'to' => $version];
        }

        $variable = $this->startup->find($server, Startup::BUILD_VARIABLES);
        if ($variable !== null) {
            $to = in_array($software, ['paper', 'purpur'], true) && $build['build'] !== null ? (string) $build['build'] : 'latest';
            if ($this->startup->value($variable) !== $to && $this->startup->accepts($variable, $to)) {
                $changes[] = ['variable' => $variable, 'to' => $to];
            }
        }

        return $changes;
    }

    /**
     * @param  array<string, mixed>  $now
     * @param  array<string, mixed>  $build
     * @return list<array{code: string, severity: string, message: string}>
     */
    private function warnings(array $now, string $software, string $version, array $build): array
    {
        return Warnings::for(
            $now['pending']['software'] ?? $now['software'],
            $now['pending']['version'] ?? $now['version'],
            $software,
            $version,
            $build['stable'],
        );
    }

    private function rename(Server $server, string $from, string $to): void
    {
        $this->renamer->rename($server, '/', [['from' => $from, 'to' => $to]]);
        $this->files->forget($server);
    }

    private function requireOffline(Server $server): void
    {
        try {
            $state = $this->state->read($server)->state;
        } catch (DaemonConnectionException) {
            throw new HttpException(502, 'Could not reach this server\'s node.');
        }

        if ($state !== 'offline') {
            throw new HttpException(409, 'Stop the server first.');
        }
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    private function locked(Server $server, \Closure $callback): mixed
    {
        try {
            return Cache::lock('ext:mc-version:switch:'.$server->uuid, 600)->block(5, $callback);
        } catch (LockTimeoutException) {
            throw new HttpException(409, 'Another version change is running on this server. Wait for it to finish.');
        }
    }

    private function quietly(\Closure $callback): void
    {
        try {
            $callback();
        } catch (\Throwable) {
            // A clean-up that fails leaves a stray file, which is better than hiding the real error.
        }
    }
}
