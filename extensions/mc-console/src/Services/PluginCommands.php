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

    /** Per request; the browser asks again for the rest. Small, so a request never nears the PHP time limit. */
    private const int MAX_BATCH_BYTES = 24 * 1024 * 1024;

    private const int MAX_BATCH_FILES = 6;

    /** A plugin.yml bigger than this is not a real one (or a zip bomb); it is skipped. */
    private const int MAX_YAML_BYTES = 512 * 1024;

    private const int MAX_COMMANDS_PER_PLUGIN = 200;

    private const int MAX_COMMANDS = 2000;

    public function __construct(
        private readonly ListsDirectories $lister,
        private readonly ReadsFileContents $reader,
    ) {}

    /**
     * @return array{commands: list<array<string, mixed>>, pending: int}
     */
    public function forServer(Server $server): array
    {
        // Some Wings versions answer a listing of a missing folder with a 500, so the
        // root is checked first. A node that is down simply gives no plugin commands.
        try {
            if (! $this->rootHas($server, 'plugins')) {
                return ['commands' => [], 'pending' => 0];
            }
            $files = $this->lister->list($server, '/plugins');
        } catch (DaemonConnectionException) {
            return ['commands' => [], 'pending' => 0];
        }

        // One reader per server at a time; a second tab gets what is cached so far.
        $lock = Cache::lock('ext:mc-console:read:'.$server->uuid, 60);
        $reading = $lock->get();

        try {
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
                    } elseif (! $reading || $read >= self::MAX_BATCH_FILES || $bytes + $size > self::MAX_BATCH_BYTES) {
                        $pending++;

                        continue;
                    } else {
                        $read++;
                        $bytes += $size;
                        $result = $this->readJar($server, $name);
                    }

                    // A failed read (node hiccup) is retried after a few minutes, not a month.
                    Cache::put($key, $result ?? [], $result === null ? now()->addMinutes(5) : now()->addDays(30));
                    $result ??= [];
                }

                foreach ($result as $command) {
                    if (count($commands) >= self::MAX_COMMANDS) {
                        break 2;
                    }
                    $commands[] = $command;
                }
            }
        } finally {
            if ($reading) {
                $lock->release();
            }
        }

        return ['commands' => $commands, 'pending' => $pending];
    }

    /** @var array<string, list<string>> per request */
    private array $root = [];

    /** Whether the server root holds a file or folder with this name. */
    public function rootHas(Server $server, string $name): bool
    {
        $this->root[$server->uuid] ??= array_values(array_map(
            fn (array $entry): string => (string) ($entry['name'] ?? ''),
            $this->lister->list($server, '/'),
        ));

        return in_array($name, $this->root[$server->uuid], true);
    }

    /**
     * Commands a plugin jar declares. Null when the jar could not be read, so the
     * failure is not remembered as "no commands".
     *
     * @return list<array<string, mixed>>|null
     */
    private function readJar(Server $server, string $name): ?array
    {
        $temp = tempnam(sys_get_temp_dir(), 'mcc');
        if ($temp === false) {
            return null;
        }

        try {
            try {
                file_put_contents($temp, $this->reader->read($server, '/plugins/'.$name, self::MAX_JAR_BYTES));
            } catch (\Throwable $exception) {
                Log::info('mc-console: could not read '.$name, ['message' => $exception->getMessage()]);

                return null;
            }

            $zip = new \ZipArchive();
            if ($zip->open($temp, \ZipArchive::RDONLY) !== true) {
                return []; // Not a zip: it will never have commands.
            }

            // Bukkit plugins declare commands in plugin.yml; Paper-only plugins may
            // only have paper-plugin.yml.
            $yaml = false;
            foreach (['plugin.yml', 'paper-plugin.yml'] as $entry) {
                $stat = $zip->statName($entry);
                if ($stat !== false && $stat['size'] <= self::MAX_YAML_BYTES) {
                    $yaml = $zip->getFromName($entry);
                    break;
                }
            }
            $zip->close();

            return $yaml === false ? [] : $this->parse($yaml);
        } catch (\Throwable $exception) {
            Log::info('mc-console: could not parse '.$name, ['message' => $exception->getMessage()]);

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
            if (count($result) >= self::MAX_COMMANDS_PER_PLUGIN) {
                break;
            }
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
