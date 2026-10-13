<?php

namespace McWorld\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use McWorld\Support\Properties;
use McWorld\Support\PropertyModel;
use Pterodactyl\Exceptions\Http\Server\FileSizeTooLargeException;
use Pterodactyl\Models\Server;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Reads and edits server.properties. A save changes only the keys that differ, keeps
 * everything else in the file as it was, and first copies the old file to
 * "server.properties.<date>.bak" (the newest five are kept).
 */
class PropertiesFile
{
    public const string PATH = '/server.properties';

    public const string NAME = 'server.properties';

    private const int MAX_BYTES = 1024 * 1024;

    private const int KEEP_BACKUPS = 5;

    public function __construct(private readonly ServerFiles $files) {}

    /**
     * @return array{exists: bool, raw: string, hash: string}
     */
    public function read(Server $server): array
    {
        $exists = ServerFiles::find($this->files->entries($server), self::NAME, true) !== null;
        $raw = $exists ? $this->load($server) : '';

        return ['exists' => $exists, 'raw' => $raw, 'hash' => self::hash($raw)];
    }

    public static function hash(string $raw): string
    {
        return sha1($raw);
    }

    /**
     * Apply changes to the file, as long as it is still the version the editor started from.
     *
     * @param  array<string, ?string>  $changes  key => new value, null to remove the key
     * @param  ?string  $expectedHash  hash of the file the changes were made against; null skips the check
     * @return array{changed: list<string>, raw: string, hash: string}
     *
     * @throws HttpException
     */
    public function change(Server $server, array $changes, ?string $expectedHash): array
    {
        foreach ($changes as $key => $value) {
            if ($value !== null && ($problem = PropertyModel::validate((string) $key, $value)) !== null) {
                throw new HttpException(422, (string) $key.': '.$problem);
            }
            if ($value === null && preg_match(PropertyModel::KEY, (string) $key) !== 1) {
                throw new HttpException(422, 'That is not a valid key.');
            }
        }

        try {
            return Cache::lock('ext:mc-world:properties:'.$server->uuid, 20)->block(10, function () use ($server, $changes, $expectedHash): array {
                $current = $this->read($server);
                if (! $current['exists']) {
                    throw new HttpException(404, 'server.properties does not exist yet. Start the server once so it is created.');
                }
                if ($expectedHash !== null && $current['hash'] !== $expectedHash) {
                    throw new HttpException(409, 'server.properties was changed since you opened this page. Reload to see the latest version.');
                }

                $before = Properties::parse($current['raw']);
                $effective = [];
                foreach ($changes as $key => $value) {
                    if (($value === null && $before->has((string) $key)) || ($value !== null && $before->get((string) $key) !== $value)) {
                        $effective[(string) $key] = $value;
                    }
                }
                if ($effective === []) {
                    return ['changed' => [], 'raw' => $current['raw'], 'hash' => $current['hash']];
                }

                $raw = $before->with($effective)->toString();
                $stamp = CarbonImmutable::now()->format('Ymd-His');
                // The panel's file write action records both writes in the activity log.
                $this->files->write($server, self::PATH.'.'.$stamp.'.bak', $current['raw']);
                $this->files->write($server, self::PATH, $raw);
                $this->prune($server);

                return ['changed' => array_keys($effective), 'raw' => $raw, 'hash' => self::hash($raw)];
            });
        } catch (LockTimeoutException) {
            throw new HttpException(409, 'Someone else is saving server.properties. Try again in a moment.');
        }
    }

    private function load(Server $server): string
    {
        try {
            $raw = $this->files->read($server, self::PATH, self::MAX_BYTES);
        } catch (FileSizeTooLargeException) {
            throw new HttpException(422, 'server.properties is larger than 1 MB. Edit it in the file manager.');
        }
        if (! mb_check_encoding($raw, 'UTF-8')) {
            throw new HttpException(422, 'server.properties is not valid UTF-8 text. Edit it in the file manager.');
        }

        return $raw;
    }

    /** Old copies are removed best effort: a failure here never fails the save. */
    private function prune(Server $server): void
    {
        try {
            $names = array_map(fn (array $entry): string => (string) ($entry['name'] ?? ''), $this->files->entries($server));
            $backups = array_values(array_filter($names, fn (string $name): bool => preg_match('/^server\.properties\.\d{8}-\d{6}\.bak$/D', $name) === 1));
            rsort($backups);
            $old = array_slice($backups, self::KEEP_BACKUPS);
            if ($old !== []) {
                $this->files->delete($server, '/', $old);
            }
        } catch (Throwable) {
            // Keeping an extra copy is harmless.
        }
    }
}
