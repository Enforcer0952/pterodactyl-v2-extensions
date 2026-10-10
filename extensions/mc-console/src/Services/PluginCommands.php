<?php

namespace McConsole\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Contracts\Files\ListsDirectories;
use Pterodactyl\Contracts\Files\ReadsFileContents;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Models\Server;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads the commands that Bukkit-style plugins declare in their plugin.yml. Every
 * jar is read once through Wings and its result kept per file name, size and
 * modification time, so only new or updated plugins are opened again.
 */
class PluginCommands
{
    private const int MAX_JAR_BYTES = 24 * 1024 * 1024;

    private const int MAX_BATCH_BYTES = 96 * 1024 * 1024;

    private const int MAX_BATCH_FILES = 30;

    public function __construct(
        private readonly ListsDirectories $lister,
        private readonly ReadsFileContents $reader,
    ) {}

    /**
     * @return array{commands: list<array<string, mixed>>, pending: int}
     */
    public function forServer(Server $server): array
    {
        try {
            $files = $this->lister->list($server, '/plugins');
        } catch (DaemonConnectionException) {
            return ['commands' => [], 'pending' => 0];
        }

        $commands = [];
        $pending = 0;
        $read = 0;
        $bytes = 0;

        foreach ($files as $file) {
            $name = (string) ($file['name'] ?? '');
            if (! ($file['file'] ?? true) || ! str_ends_with(strtolower($name), '.jar')) {
                continue;
            }

            $size = (int) ($file['size'] ?? 0);
            $key = 'ext:mc-console:plugin:'.$server->uuid.':'.sha1($name.'|'.$size.'|'.($file['modified'] ?? ''));
            $result = Cache::get($key);

            if ($result === null) {
                if ($size > self::MAX_JAR_BYTES) {
                    $result = [];
                } elseif ($read >= self::MAX_BATCH_FILES || $bytes + $size > self::MAX_BATCH_BYTES) {
                    $pending++;

                    continue;
                } else {
                    $read++;
                    $bytes += $size;
                    $result = $this->readJar($server, $name);
                }

                Cache::put($key, $result, now()->addDays(30));
            }

            array_push($commands, ...$result);
        }

        return ['commands' => $commands, 'pending' => $pending];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readJar(Server $server, string $name): array
    {
        $temp = tempnam(sys_get_temp_dir(), 'mcc');
        if ($temp === false) {
            return [];
        }

        try {
            file_put_contents($temp, $this->reader->read($server, '/plugins/'.$name, self::MAX_JAR_BYTES));

            $zip = new \ZipArchive();
            if ($zip->open($temp, \ZipArchive::RDONLY) !== true) {
                return [];
            }

            $yaml = $zip->getFromName('plugin.yml');
            $zip->close();

            return $yaml === false ? [] : $this->parse($yaml);
        } catch (\Throwable $exception) {
            Log::info('mc-console: could not read '.$name, ['message' => $exception->getMessage()]);

            return [];
        } finally {
            @unlink($temp);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parse(string $yaml): array
    {
        $data = Yaml::parse($yaml);
        if (! is_array($data) || ! is_array($data['commands'] ?? null)) {
            return [];
        }

        $plugin = is_string($data['name'] ?? null) ? $data['name'] : 'Plugin';
        $result = [];

        foreach ($data['commands'] as $command => $info) {
            if (! is_string($command) || preg_match('/^[A-Za-z0-9_:.\-]{1,64}$/', $command) !== 1) {
                continue;
            }

            $info = is_array($info) ? $info : [];
            $aliases = $info['aliases'] ?? [];
            $aliases = is_string($aliases) ? [$aliases] : (is_array($aliases) ? $aliases : []);

            $result[] = [
                'name' => strtolower($command),
                'aliases' => array_values(array_filter(array_map(
                    fn ($alias) => is_string($alias) ? strtolower($alias) : null,
                    $aliases,
                ), fn ($alias): bool => $alias !== null && preg_match('/^[a-z0-9_:.\-]{1,64}$/', $alias) === 1)),
                'description' => is_string($info['description'] ?? null) ? mb_substr($info['description'], 0, 200) : '',
                'usage' => is_string($info['usage'] ?? null) ? mb_substr($info['usage'], 0, 500) : null,
                'plugin' => mb_substr($plugin, 0, 64),
            ];
        }

        return $result;
    }
}
