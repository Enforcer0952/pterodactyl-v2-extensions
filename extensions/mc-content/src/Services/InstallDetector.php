<?php

namespace McContent\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use McContent\Models\Install;
use McContent\Support\ContentKind;
use Pterodactyl\Contracts\Files\ReadsFileContents;
use Pterodactyl\Models\Server;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Recognises jars that were put in the plugins or mods folder by hand (SFTP, the
 * file manager, a modpack) by hashing them and asking Modrinth which version each
 * file is. Recognised files get an Install record, so they show their name,
 * version and updates, and count as installed in search results.
 *
 * Hashing means reading every jar once through Wings, so it happens in batches and
 * results are remembered per file name, size and modification time.
 */
class InstallDetector
{
    /** Jars larger than this are skipped; very few plugins or mods are. */
    private const int MAX_FILE_BYTES = 32 * 1024 * 1024;

    /** At most this much is read per request; the rest is done on the next one. */
    private const int MAX_BATCH_BYTES = 96 * 1024 * 1024;

    public function __construct(
        private readonly ReadsFileContents $reader,
        private readonly ModrinthClient $modrinth,
    ) {}

    /**
     * Identify up to $maxFiles untracked jars.
     *
     * @param  array<string, array<string, mixed>>  $jars  directory entries keyed by file name
     * @param  list<string>  $tracked  file names that already have an Install record
     * @return int how many untracked jars still need hashing
     */
    public function identify(Server $server, ContentKind $type, array $jars, array $tracked, int $maxFiles): int
    {
        $hashes = [];
        $pending = 0;
        $bytes = 0;
        $read = 0;

        foreach ($jars as $name => $file) {
            if (in_array($name, $tracked, true)) {
                continue;
            }

            $size = (int) ($file['size'] ?? 0);
            $key = 'ext:mc-content:sha1:'.$server->uuid.':'.sha1($type->directory().'/'.$name.'|'.$size.'|'.($file['modified'] ?? ''));
            $hash = Cache::get($key);

            if ($hash === null) {
                if ($size > self::MAX_FILE_BYTES) {
                    Cache::put($key, 'skip', now()->addDays(30));

                    continue;
                }

                if ($read >= $maxFiles || $bytes + $size > self::MAX_BATCH_BYTES) {
                    $pending++;

                    continue;
                }

                try {
                    $hash = sha1($this->reader->read($server, $type->directory().'/'.$name, self::MAX_FILE_BYTES));
                } catch (\Throwable $exception) {
                    Log::info('mc-content: could not hash '.$name, ['message' => $exception->getMessage()]);
                    $hash = 'skip';
                }

                $read++;
                $bytes += $size;
                Cache::put($key, $hash, now()->addDays(30));
            }

            if ($hash !== 'skip') {
                $hashes[$name] = $hash;
            }
        }

        if ($hashes !== []) {
            $this->record($server, $type, $hashes);
        }

        return $pending;
    }

    /**
     * @param  array<string, string>  $hashes  SHA-1 hashes keyed by file name
     */
    private function record(Server $server, ContentKind $type, array $hashes): void
    {
        try {
            $versions = $this->modrinth->versionsForHashes(array_values(array_unique($hashes)));
            $projects = $this->modrinth->projects(array_values(array_unique(array_filter(array_map(
                fn (array $version): ?string => is_string($version['project_id'] ?? null) ? $version['project_id'] : null,
                $versions,
            )))));
        } catch (HttpException $exception) {
            Log::warning('mc-content: hash lookup failed', ['message' => $exception->getMessage()]);

            return;
        }

        foreach ($hashes as $name => $hash) {
            $version = $versions[$hash] ?? null;
            if ($version === null) {
                continue; // Not on Modrinth; it stays listed by file name.
            }

            $project = $projects[(string) ($version['project_id'] ?? '')] ?? [];
            $icon = $project['icon_url'] ?? null;

            Install::query()->updateOrCreate(
                ['server_id' => $server->id, 'kind' => $type->value, 'filename' => $name],
                [
                    'project_id' => (string) $version['project_id'],
                    'version_id' => (string) ($version['id'] ?? ''),
                    'title' => mb_substr((string) ($project['title'] ?? $version['name'] ?? $name), 0, 255),
                    'version_number' => mb_substr((string) ($version['version_number'] ?? ''), 0, 128),
                    'icon_url' => is_string($icon) && parse_url($icon, PHP_URL_HOST) === 'cdn.modrinth.com' ? $icon : null,
                    'sha1' => $hash,
                ],
            );
        }
    }
}
