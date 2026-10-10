<?php

namespace McContent\Services;

use Illuminate\Support\Facades\Cache;
use McContent\Support\ContentKind;
use Pterodactyl\Contracts\Files\ListsDirectories;
use Pterodactyl\Contracts\Files\ReadsFileContents;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\Extensions\ExtensionManager;

/**
 * Works out which Minecraft version a server runs. In order: the version a user
 * saved on the page, the egg's startup variables, then traces the server jar
 * leaves in the server's files.
 */
class GameVersionDetector
{
    private const string VERSION = '\d+\.\d+(?:\.\d+)?';

    private const int MAX_LOG_BYTES = 2 * 1024 * 1024;

    public function __construct(
        private readonly ListsDirectories $lister,
        private readonly ReadsFileContents $reader,
        private readonly ExtensionManager $extensions,
    ) {}

    /**
     * @return array{version: ?string, source: ?string}
     */
    public function detect(Server $server): array
    {
        $saved = $this->saved($server);
        if ($saved !== null) {
            return ['version' => $saved, 'source' => 'saved'];
        }

        $fromVariables = ContentKind::detectGameVersion($server);
        if ($fromVariables !== null) {
            return ['version' => $fromVariables, 'source' => 'startup'];
        }

        // Found versions are kept for ten minutes; a miss only for one, so a
        // server that was just started is picked up quickly.
        $key = 'ext:mc-content:detected-version:'.$server->uuid;
        $fromFiles = Cache::get($key);
        if ($fromFiles === null) {
            $fromFiles = $this->fromFiles($server) ?? false;
            Cache::put($key, $fromFiles, $fromFiles === false ? 60 : 600);
        }

        return is_string($fromFiles)
            ? ['version' => $fromFiles, 'source' => 'files']
            : ['version' => null, 'source' => null];
    }

    public function saved(Server $server): ?string
    {
        $value = $this->extensions->settings('mc-content')->forServer($server)->get('game_version');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function save(Server $server, ?string $version): void
    {
        $store = $this->extensions->settings('mc-content')->forServer($server);

        $version === null ? $store->forget('game_version') : $store->set('game_version', $version);
    }

    public function forget(Server $server): void
    {
        Cache::forget('ext:mc-content:detected-version:'.$server->uuid);
    }

    private function fromFiles(Server $server): ?string
    {
        foreach ([
            fn () => $this->fromPaperHistory($server),
            fn () => $this->fromDirectory($server, '/versions'),
            fn () => $this->fromDirectory($server, '/libraries/net/minecraft/server'),
            fn () => $this->fromDirectory($server, '/.fabric/server'),
            fn () => $this->fromDirectory($server, '/cache', '/^mojang_('.self::VERSION.')\.jar$/'),
            fn () => $this->fromLog($server),
        ] as $probe) {
            try {
                $version = $probe();
            } catch (\Throwable) {
                $version = null;
            }

            if ($version !== null) {
                return $version;
            }
        }

        return null;
    }

    /** Paper and its forks record "git-Paper-123 (MC: 1.21.4)" in version_history.json. */
    private function fromPaperHistory(Server $server): ?string
    {
        $json = json_decode($this->reader->read($server, '/version_history.json', 64 * 1024), true);
        $current = is_array($json) ? ($json['currentVersion'] ?? null) : null;

        return is_string($current) && preg_match('/\(MC: ('.self::VERSION.')\)/', $current, $match) === 1 ? $match[1] : null;
    }

    /**
     * The newest version named by the entries of a directory, such as
     * libraries/net/minecraft/server/1.20.1-20230612.114412 (Forge and NeoForge),
     * versions/1.21.4 (Paper 1.21+) or .fabric/server/1.21.1-server.jar (Fabric).
     */
    private function fromDirectory(Server $server, string $directory, ?string $pattern = null): ?string
    {
        $pattern ??= '/^('.self::VERSION.')(?:$|[-_.])/';
        $versions = [];

        foreach ($this->lister->list($server, $directory) as $entry) {
            if (preg_match($pattern, (string) ($entry['name'] ?? ''), $match) === 1) {
                $versions[] = $match[1];
            }
        }

        if ($versions === []) {
            return null;
        }

        usort($versions, 'version_compare');

        return end($versions);
    }

    /** Every Java server logs "Starting minecraft server version 1.21.1" on start. */
    private function fromLog(Server $server): ?string
    {
        $log = $this->reader->read($server, '/logs/latest.log', self::MAX_LOG_BYTES);

        return preg_match('/Starting minecraft server version ('.self::VERSION.')/i', $log, $match) === 1 ? $match[1] : null;
    }
}
