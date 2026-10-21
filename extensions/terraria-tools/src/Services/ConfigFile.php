<?php

namespace TerrariaTools\Services;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Pterodactyl\Models\Server;
use Symfony\Component\HttpKernel\Exception\HttpException;
use TerrariaTools\Support\ServerConfig;
use TerrariaTools\Support\Terraria;

/**
 * serverconfig.txt in the server folder: read it, and write it back with the old
 * version kept as "serverconfig.txt.<date>.bak".
 */
class ConfigFile
{
    public const int MAX_BYTES = 256 * 1024;

    public function __construct(private readonly ServerFiles $files) {}

    /** The file's text, or null when the server has no serverconfig.txt (yet). */
    public function read(Server $server): ?string
    {
        return $this->files->read($server, '/', Terraria::CONFIG_FILE, self::MAX_BYTES);
    }

    public function parsed(Server $server): ServerConfig
    {
        return ServerConfig::parse($this->read($server) ?? '');
    }

    public static function hash(?string $contents): string
    {
        return sha1($contents ?? '');
    }

    /**
     * Change the file under a lock. $change gets the current text (null when the file
     * does not exist) and returns the new text, or null for no change. When $hash is
     * given, the file must still match it. An empty hash is refused (422).
     *
     * @param  callable(?string): ?string  $change
     * @return bool whether the file was written
     */
    public function update(Server $server, callable $change, ?string $hash = null): bool
    {
        if ($hash !== null && preg_match('/^[0-9a-f]{40}$/D', $hash) !== 1) {
            throw new HttpException(422, 'The save is missing the version of serverconfig.txt you opened. Reload the page and try again.');
        }

        try {
            return Cache::lock('ext:terraria-tools:config:'.$server->uuid, 30)->block(5, function () use ($server, $change, $hash): bool {
                $current = $this->read($server);
                if ($hash !== null && $hash !== self::hash($current)) {
                    throw new HttpException(409, 'serverconfig.txt changed since you opened it. Reload the page and try again.');
                }

                $new = $change($current);
                if ($new === null || $new === $current) {
                    return false;
                }

                $this->files->writeWithBackup($server, '/', Terraria::CONFIG_FILE, $new, $current);

                return true;
            });
        } catch (LockTimeoutException) {
            throw new HttpException(409, 'serverconfig.txt is being changed right now. Try again in a moment.');
        }
    }
}
