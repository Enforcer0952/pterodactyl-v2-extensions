<?php

namespace ValheimTools\Services;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Pterodactyl\Models\Server;
use Symfony\Component\HttpKernel\Exception\HttpException;
use ValheimTools\Support\ListFile;
use ValheimTools\Support\PlayerIds;
use ValheimTools\Support\Valheim;

/**
 * adminlist.txt, bannedlist.txt and permittedlist.txt in the save folder
 * (~/.config/unity3d/IronGate/Valheim, or -savedir).
 */
class AccessLists
{
    private const int MAX_BYTES = 1024 * 1024;

    private const int MAX_ENTRIES = 2000;

    public function __construct(
        private readonly ServerFiles $files,
        private readonly Preferences $preferences,
        private readonly KnownPlayers $known,
    ) {}

    public function directory(Server $server): string
    {
        return Valheim::saveDirectory((string) $server->startup);
    }

    /**
     * @return array{directory: string, style: string, lists: array<string, array{file: string, exists: bool, entries: list<array{id: string, steam_id: ?string, name: ?string, last_seen: ?string}>}>}
     */
    public function all(Server $server): array
    {
        $directory = $this->directory($server);
        $names = $this->known->names($server);

        $lists = [];
        foreach (ListFile::NAMES as $kind => $file) {
            $contents = $this->files->read($server, $directory, $file, self::MAX_BYTES);
            $entries = [];
            foreach ($contents === null ? [] : array_slice(ListFile::parse($contents)->entries(), 0, self::MAX_ENTRIES) as $id) {
                $known = $names[PlayerIds::key($id)] ?? null;
                $entries[] = [
                    'id' => $id,
                    'steam_id' => PlayerIds::steamId($id),
                    'name' => $known['name'] ?? null,
                    'last_seen' => $known['last_seen'] ?? null,
                ];
            }
            $lists[$kind] = ['file' => $file, 'exists' => $contents !== null, 'entries' => $entries];
        }

        return ['directory' => ltrim($directory, '/'), 'style' => $this->preferences->idStyle(), 'lists' => $lists];
    }

    /**
     * Add a player. Returns false when the player was already listed.
     *
     * @throws HttpException
     */
    public function add(Server $server, string $kind, string $input, bool $confirmed = false): bool
    {
        $file = $this->file($kind);
        $id = PlayerIds::fromInput($input, $this->preferences->idStyle());
        if ($id === null) {
            throw new HttpException(422, 'Enter a Steam ID (17 digits, like 76561198051014133) or an ID such as Xbox_1234567890.');
        }

        return $this->locked($server, $kind, function () use ($server, $kind, $file, $id, $confirmed): bool {
            $directory = $this->directory($server);
            $contents = $this->files->read($server, $directory, $file, self::MAX_BYTES);
            $list = $contents === null ? ListFile::empty($kind) : ListFile::parse($contents);

            if ($kind === 'permitted' && ! $confirmed && $list->entries() === []) {
                throw new HttpException(409, 'The permitted list is empty, so everyone may join now. Once a player is on it, only the players on it can join.');
            }
            if (count($list->entries()) >= self::MAX_ENTRIES) {
                throw new HttpException(422, 'This list is full.');
            }
            if (! $list->add($id)) {
                return false;
            }

            $this->files->writeWithBackup($server, $directory, $file, $list->render(), $contents);

            return true;
        });
    }

    /** Remove a player in any spelling. Returns how many lines went. */
    public function remove(Server $server, string $kind, string $input): int
    {
        $file = $this->file($kind);
        $id = trim($input);
        if (! PlayerIds::isValid($id)) {
            throw new HttpException(422, 'That is not a player ID.');
        }

        return $this->locked($server, $kind, function () use ($server, $kind, $file, $id): int {
            $directory = $this->directory($server);
            $contents = $this->files->read($server, $directory, $file, self::MAX_BYTES);
            if ($contents === null) {
                return 0;
            }

            $list = ListFile::parse($contents);
            $removed = $list->remove($id);
            if ($removed > 0) {
                $this->files->writeWithBackup($server, $directory, $file, $list->render(), $contents);
            }

            return $removed;
        });
    }

    private function file(string $kind): string
    {
        return ListFile::NAMES[$kind] ?? throw new HttpException(404, 'There is no such list.');
    }

    /**
     * @template T
     *
     * @param  callable(): T  $run
     * @return T
     */
    private function locked(Server $server, string $kind, callable $run): mixed
    {
        try {
            return Cache::lock('ext:valheim-tools:list:'.$server->uuid.':'.$kind, 30)->block(5, $run);
        } catch (LockTimeoutException) {
            throw new HttpException(409, 'The list is being changed right now. Try again in a moment.');
        }
    }
}
