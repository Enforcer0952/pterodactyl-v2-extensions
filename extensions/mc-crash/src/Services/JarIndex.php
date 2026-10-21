<?php

namespace McCrash\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use McCrash\Support\JarInspector;
use McCrash\Support\JarMap;
use Pterodactyl\Models\Server;

/**
 * An index of the installed plugin and mod jars: their descriptor and the packages their classes live in. Every
 * jar is read once through Wings and the result kept per folder, file name, size and modification time, so
 * only new or updated jars are opened again. The work is spread over several requests, like mc-console does
 * for plugin commands; whatever is not read yet is counted in "pending".
 */
class JarIndex
{
    /** Jars above this size are not downloaded; they are known by file name only. */
    private const int MAX_JAR_BYTES = 32 * 1024 * 1024;

    /** Per request, so one request never nears the PHP time limit. */
    private const int MAX_BATCH_BYTES = 48 * 1024 * 1024;

    private const int MAX_BATCH_FILES = 8;

    private const int MAX_SECONDS = 15;

    private const int MAX_JARS = 400;

    /** @var array<string, array{map: JarMap, total: int, pending: int, signature: string}> */
    private array $memo = [];

    public function __construct(private readonly Files $files) {}

    /**
     * @return array{map: JarMap, total: int, pending: int, signature: string}
     */
    public function forServer(Server $server): array
    {
        if (isset($this->memo[$server->uuid])) {
            return $this->memo[$server->uuid];
        }

        $jars = [];
        foreach (['plugins' => 'plugin', 'mods' => 'mod'] as $folder => $kind) {
            foreach ($this->files->folder($server, $folder) as $entry) {
                $name = (string) ($entry['name'] ?? '');
                if (! Files::isPlainFile($entry) || ! str_ends_with(strtolower($name), '.jar') || $name !== basename($name) || str_contains($name, "\0")) {
                    continue;
                }
                $jars[] = ['folder' => $folder, 'kind' => $kind, 'name' => $name, 'size' => (int) ($entry['size'] ?? 0), 'modified' => (string) ($entry['modified'] ?? '')];
            }
        }
        $jars = array_slice($jars, 0, self::MAX_JARS);

        $lock = Cache::lock('ext:mc-crash:index:'.$server->uuid, 90);
        $reading = $lock->get();
        $started = microtime(true);
        $read = 0;
        $bytes = 0;
        $pending = 0;
        $infos = [];
        $signature = [];

        try {
            foreach ($jars as $jar) {
                $signature[] = $jar['folder'].'/'.$jar['name'].'|'.$jar['size'].'|'.$jar['modified'];
                $key = 'ext:mc-crash:jar:'.$server->uuid.':'.sha1($jar['folder'].'/'.$jar['name'].'|'.$jar['size'].'|'.$jar['modified']);
                $info = Cache::get($key);

                if ($info === null) {
                    if ($jar['size'] > self::MAX_JAR_BYTES) {
                        $info = $this->nameOnly($jar);
                    } elseif (! $reading || $read >= self::MAX_BATCH_FILES || $bytes + $jar['size'] > self::MAX_BATCH_BYTES || microtime(true) - $started > self::MAX_SECONDS) {
                        $pending++;
                        $infos[] = $this->nameOnly($jar);

                        continue;
                    } else {
                        $read++;
                        $bytes += $jar['size'];
                        $info = $this->readJar($server, $jar);
                    }

                    // A failed read (node hiccup) is retried after a few minutes, not a month.
                    Cache::put($key, $info ?? $this->nameOnly($jar), $info === null ? now()->addMinutes(5) : now()->addDays(30));
                    $info ??= $this->nameOnly($jar);
                }

                $infos[] = $info;
            }
        } finally {
            // Released even when a read throws, so the next request is not held out for 90 seconds.
            if ($reading) {
                $lock->release();
            }
        }

        sort($signature);

        return $this->memo[$server->uuid] = [
            'map' => new JarMap($infos),
            'total' => count($jars),
            'pending' => $pending,
            'signature' => substr(sha1(implode("\n", $signature)), 0, 12),
        ];
    }

    /**
     * @param  array<string, mixed>  $jar
     * @return array<string, mixed>
     */
    private function nameOnly(array $jar): array
    {
        return [
            'file' => $jar['name'], 'size' => $jar['size'], 'kind' => $jar['kind'], 'loader' => 'unknown', 'ids' => [], 'names' => [],
            'version' => null, 'mains' => [], 'api' => null, 'depends' => [], 'packages' => [], 'coarse' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $jar
     * @return array<string, mixed>|null null when the jar could not be read this time
     */
    private function readJar(Server $server, array $jar): ?array
    {
        $temp = tempnam(sys_get_temp_dir(), 'mcc');
        if ($temp === false) {
            return null;
        }

        try {
            $content = $this->files->read($server, '/'.$jar['folder'].'/'.$jar['name'], ['size' => $jar['size']], self::MAX_JAR_BYTES);
            if ($content === null) {
                return $this->nameOnly($jar);
            }
            file_put_contents($temp, $content);
            unset($content);

            // Not a zip: it will never have a descriptor.
            return JarInspector::inspect($temp, $jar['name'], $jar['size'], $jar['kind']) ?? $this->nameOnly($jar);
        } catch (\Throwable $exception) {
            Log::info('mc-crash: could not read '.$jar['name'], ['message' => $exception->getMessage()]);

            return null;
        } finally {
            @unlink($temp);
        }
    }
}
