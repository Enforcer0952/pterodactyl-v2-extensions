<?php

namespace TerrariaTools\Services;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Pterodactyl\Models\Server;
use Symfony\Component\HttpKernel\Exception\HttpException;
use TerrariaTools\Support\BanList;
use TerrariaTools\Support\Terraria;

/**
 * The server's ban list, banlist.txt (or where banlist= in serverconfig.txt points).
 * The game adds to it on "ban"; removing a ban edits the file while the server is
 * stopped, with the old version kept as a .bak.
 */
class Bans
{
    private const int MAX_BYTES = 1024 * 1024;

    public function __construct(
        private readonly ServerFiles $files,
        private readonly ConfigFile $config,
    ) {}

    /**
     * @return array{directory: string, name: string, path: string}
     */
    public function location(Server $server): array
    {
        $path = Terraria::banlistPath($this->config->parsed($server)->get('banlist'));

        return ['directory' => dirname($path) === '.' ? '/' : dirname($path), 'name' => basename($path), 'path' => $path];
    }

    /**
     * @return array{exists: bool, path: string, entries: list<array{name: ?string, id: string}>}
     */
    public function all(Server $server): array
    {
        $where = $this->location($server);
        $contents = $this->files->read($server, $where['directory'], $where['name'], self::MAX_BYTES);

        return [
            'exists' => $contents !== null,
            'path' => ltrim($where['path'], '/'),
            'entries' => $contents === null ? [] : BanList::parse($contents),
        ];
    }

    /** Remove every ban with this identifier. */
    public function remove(Server $server, string $id): void
    {
        if (! BanList::validId($id)) {
            throw new HttpException(422, 'That is not a ban entry.');
        }

        $where = $this->location($server);
        try {
            Cache::lock('ext:terraria-tools:bans:'.$server->uuid, 30)->block(5, function () use ($server, $where, $id): void {
                $contents = $this->files->read($server, $where['directory'], $where['name'], self::MAX_BYTES);
                $updated = $contents === null ? null : BanList::remove($contents, $id);
                if ($updated === null) {
                    throw new HttpException(404, 'That ban is not in the list (any more).');
                }

                $this->files->writeWithBackup($server, $where['directory'], $where['name'], $updated, $contents);
            });
        } catch (LockTimeoutException) {
            throw new HttpException(409, 'The ban list is being changed right now. Try again in a moment.');
        }
    }
}
