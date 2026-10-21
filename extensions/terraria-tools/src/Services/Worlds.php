<?php

namespace TerrariaTools\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Pterodactyl\Contracts\Servers\SendsServerCommands;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\Extensions\ExtensionSettingsRegistry;
use Symfony\Component\HttpKernel\Exception\HttpException;
use TerrariaTools\Support\ServerConfig;
use TerrariaTools\Support\Terraria;
use TerrariaTools\Support\WorldFiles;

/**
 * World files: list, switch, back up, restore and delete, and set up a new world for
 * the server to generate on its next start. Backups are plain copies in a "Backups"
 * folder next to the worlds folder.
 */
class Worlds
{
    private const int DEFAULT_KEEP = 5;

    /** Seconds the game gets to write the world after a save. */
    private const int SAVE_WAIT = 2;

    public function __construct(
        private readonly ServerFiles $files,
        private readonly ConfigFile $config,
        private readonly StartupVariables $variables,
        private readonly SendsServerCommands $commands,
        private readonly ExtensionSettingsRegistry $settings,
    ) {}

    /** How many backups each world keeps (setting). */
    public function keep(): int
    {
        $value = $this->settings->get('terraria-tools')?->get('backups_keep');

        return is_numeric($value) ? max(1, min(50, (int) $value)) : self::DEFAULT_KEEP;
    }

    /**
     * @return array<string, mixed>
     */
    public function overview(Server $server): array
    {
        $config = $this->config->parsed($server);
        $worlds = $this->worldsDirectory($config);
        $backups = Terraria::backupsDirectory($worlds);
        $variable = $this->variables->get($server, 'WORLD_NAME');
        $active = $this->activeName($config, $variable);
        $activeNames = $this->activeNames($config, $variable);

        $files = $this->files->files($server, $worlds);
        $names = array_column($files, 'name');

        $list = [];
        foreach ($files as $file) {
            $name = WorldFiles::worldName($file['name']);
            if ($name === null) {
                continue;
            }
            $list[] = [
                'name' => $name,
                'size' => $file['size'],
                'modified' => $file['modified'],
                'active' => in_array($name, $activeNames, true),
                'has_bak' => in_array($file['name'].'.bak', $names, true),
                'has_twld' => in_array(WorldFiles::twld($file['name']), $names, true),
            ];
        }
        usort($list, fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        $saved = [];
        $backupFiles = $this->files->files($server, $backups);
        $backupNames = array_column($backupFiles, 'name');
        foreach ($backupFiles as $file) {
            $parsed = WorldFiles::parseBackup($file['name']);
            if ($parsed !== null) {
                $saved[] = [
                    'file' => $file['name'],
                    'world' => $parsed['world'],
                    'stamp' => $parsed['stamp'],
                    'size' => $file['size'],
                    'modified' => $file['modified'],
                    'has_twld' => in_array(WorldFiles::twld($file['name']), $backupNames, true),
                ];
            }
        }
        usort($saved, fn (array $a, array $b): int => strcmp($b['stamp'], $a['stamp']));

        return [
            'worlds' => $list,
            'backups' => $saved,
            'active' => $active,
            'worlds_directory' => ltrim($worlds, '/'),
            'backups_directory' => ltrim($backups, '/'),
            'keep' => $this->keep(),
            'config_exists' => $this->config->read($server) !== null,
            'variables' => [
                'name' => $this->variables->exists($server, 'WORLD_NAME'),
                'name_editable' => $this->variables->editable($server, 'WORLD_NAME'),
            ],
        ];
    }

    /**
     * Make a world the one the server loads.
     *
     * @return array{updated: list<string>, skipped: list<string>}
     */
    public function switch(Server $server, string $name, bool $setVariables): array
    {
        return $this->locked($server, function () use ($server, $name, $setVariables): array {
            $worlds = $this->worldsDirectory($this->config->parsed($server));
            $this->requireWorld($server, $worlds, $name);

            $this->config->update($server, function (?string $current) use ($name, $worlds): string {
                $config = ServerConfig::parse($current ?? '');
                $config->set('worldname', $name);
                $config->set('world', WorldFiles::configPath($config->get('world'), $config->get('worldpath'), $worlds, $name));

                return $config->render();
            });

            return $this->setVariables($server, ['WORLD_NAME' => $name], $setVariables);
        });
    }

    /**
     * Copy a world into the Backups folder. A running server saves first.
     *
     * @return string the backup's file name
     */
    public function backup(Server $server, string $name, bool $running): string
    {
        return $this->locked($server, function () use ($server, $name, $running): string {
            $worlds = $this->worldsDirectory($this->config->parsed($server));
            $this->requireWorld($server, $worlds, $name);

            if ($running) {
                // The game writes the world to a new file and swaps it in, so the copy is always whole.
                $this->commands->send($server, 'save');
                sleep(self::SAVE_WAIT);
            }

            $file = $this->makeBackup($server, $worlds, $name);
            $this->prune($server, Terraria::backupsDirectory($worlds));

            return $file;
        });
    }

    /**
     * Put a backup back as its world. The world it replaces is backed up first.
     *
     * @return array{world: string, safety: ?string}
     */
    public function restore(Server $server, string $file): array
    {
        return $this->locked($server, function () use ($server, $file): array {
            $parsed = WorldFiles::parseBackup($file);
            if ($parsed === null) {
                throw new HttpException(422, 'That is not a world backup.');
            }
            $worlds = $this->worldsDirectory($this->config->parsed($server));
            $backups = Terraria::backupsDirectory($worlds);
            if (! $this->files->exists($server, $backups, $file)) {
                throw new HttpException(404, 'That backup does not exist (any more).');
            }

            $world = $parsed['world'];
            $this->files->ensureDirectory($server, $worlds);

            $safety = null;
            if ($this->files->exists($server, $worlds, $world.'.wld')) {
                $safety = $this->makeBackup($server, $worlds, $world);
            }
            // The game's own .bak and the modded-content file belong to the world being replaced.
            $this->files->delete($server, $worlds, array_values(array_filter(
                WorldFiles::companions($world),
                fn (string $name): bool => $this->files->exists($server, $worlds, $name),
            )));

            $this->files->copyTo($server, $backups, $file, $worlds, $world.'.wld');
            $twld = WorldFiles::twld($file);
            if ($this->files->exists($server, $backups, $twld)) {
                $this->files->copyTo($server, $backups, $twld, $worlds, $world.'.twld');
            }

            return ['world' => $world, 'safety' => $safety];
        });
    }

    public function deleteBackup(Server $server, string $file): void
    {
        $this->locked($server, function () use ($server, $file): void {
            if (WorldFiles::parseBackup($file) === null) {
                throw new HttpException(422, 'That is not a world backup.');
            }
            $backups = Terraria::backupsDirectory($this->worldsDirectory($this->config->parsed($server)));
            if (! $this->files->exists($server, $backups, $file)) {
                throw new HttpException(404, 'That backup does not exist (any more).');
            }

            $this->files->delete($server, $backups, $this->withTwld($server, $backups, [$file]));
        });
    }

    /**
     * Delete a world and the files the game keeps with it. The active world stays.
     *
     * @return ?string the backup taken first, if any
     */
    public function delete(Server $server, string $name, bool $backupFirst): ?string
    {
        return $this->locked($server, function () use ($server, $name, $backupFirst): ?string {
            $config = $this->config->parsed($server);
            $worlds = $this->worldsDirectory($config);
            $this->requireWorld($server, $worlds, $name);

            if (in_array($name, $this->activeNames($config, $this->variables->get($server, 'WORLD_NAME')), true)) {
                throw new HttpException(409, 'This is the world the server loads. Switch to another world or create a new one first.');
            }

            $backup = $backupFirst ? $this->makeBackup($server, $worlds, $name) : null;
            $this->files->delete($server, $worlds, array_values(array_filter(
                WorldFiles::companions($name),
                fn (string $file): bool => $this->files->exists($server, $worlds, $file),
            )));

            return $backup;
        });
    }

    /**
     * Set up a new world: the server generates it when it starts, because the
     * file is not there yet and autocreate is on.
     *
     * @param  array{name: string, size: int, difficulty: int, seed: ?string}  $input
     * @return array{updated: list<string>, skipped: list<string>}
     */
    public function create(Server $server, array $input, bool $setVariables): array
    {
        return $this->locked($server, function () use ($server, $input, $setVariables): array {
            $name = $input['name'];
            if (! Terraria::validNewWorld($name)) {
                throw new HttpException(422, 'Use letters, numbers, spaces, dashes and underscores in a world name (up to 40 characters).');
            }

            $worlds = $this->worldsDirectory($this->config->parsed($server));
            foreach ($this->files->files($server, $worlds) as $file) {
                if (strcasecmp((string) WorldFiles::worldName($file['name']), $name) === 0) {
                    throw new HttpException(409, 'A world called '.$name.' already exists.');
                }
            }

            $this->config->update($server, function (?string $current) use ($input, $name, $worlds): string {
                $config = ServerConfig::parse($current ?? '');
                $config->set('worldname', $name);
                $config->set('world', WorldFiles::configPath($config->get('world'), $config->get('worldpath'), $worlds, $name));
                $config->set('difficulty', (string) $input['difficulty']);
                $config->set('autocreate', (string) $input['size']);
                $config->set('seed', $input['seed'] ?? '');

                return $config->render();
            });

            return $this->setVariables($server, [
                'WORLD_NAME' => $name,
                'WORLD_SIZE' => (string) $input['size'],
                'WORLD_DIFFICULTY' => (string) $input['difficulty'],
                'WORLD_SEED' => $input['seed'] ?? '',
            ], $setVariables);
        });
    }

    /** The world the server loads: the "world" path first, then "worldname", then the WORLD_NAME variable. */
    public function activeName(ServerConfig $config, ?string $variableName): ?string
    {
        return $this->activeNames($config, $variableName)[0] ?? null;
    }

    /**
     * Every world the server may load.
     *
     * @return list<string>
     */
    public function activeNames(ServerConfig $config, ?string $variableName): array
    {
        return WorldFiles::activeNames($config->get('world'), $config->get('worldname'), $variableName);
    }

    private function worldsDirectory(ServerConfig $config): string
    {
        return Terraria::worldsDirectory($config->get('worldpath'));
    }

    private function requireWorld(Server $server, string $worlds, string $name): void
    {
        if (WorldFiles::worldName($name.'.wld') !== $name || ! $this->files->exists($server, $worlds, $name.'.wld')) {
            throw new HttpException(404, 'There is no world called '.$name.' on this server.');
        }
    }

    /**
     * The backup files plus the .twld that belongs to each, where there is one.
     *
     * @param  list<string>  $files
     * @return list<string>
     */
    private function withTwld(Server $server, string $backups, array $files): array
    {
        $all = $files;
        foreach ($files as $file) {
            $twld = WorldFiles::twld($file);
            if ($this->files->exists($server, $backups, $twld)) {
                $all[] = $twld;
            }
        }

        return $all;
    }

    /** Copy the world into the Backups folder without any pruning. */
    private function makeBackup(Server $server, string $worlds, string $name): string
    {
        $backups = Terraria::backupsDirectory($worlds);
        $this->files->ensureDirectory($server, $backups);

        $file = WorldFiles::backupFile($name, CarbonImmutable::now('UTC'));
        if ($this->files->exists($server, $backups, $file)) {
            throw new HttpException(409, 'A backup was just made. Wait a second and try again.');
        }

        $this->files->copyTo($server, $worlds, $name.'.wld', $backups, $file);

        // tModLoader keeps the modded content of a world in NAME.twld: it goes in the backup too.
        if ($this->files->exists($server, $worlds, $name.'.twld')) {
            try {
                $this->files->copyTo($server, $worlds, $name.'.twld', $backups, WorldFiles::twld($file));
            } catch (\Throwable $exception) {
                // Never leave a backup that is missing half of the world.
                try {
                    $this->files->delete($server, $backups, [$file]);
                } catch (\Throwable) {
                }

                throw $exception;
            }
        }

        return $file;
    }

    private function prune(Server $server, string $backups): void
    {
        try {
            $this->files->delete($server, $backups, $this->withTwld($server, $backups, WorldFiles::expired(array_column($this->files->files($server, $backups), 'name'), $this->keep())));
        } catch (\Throwable) {
            // An extra backup is harmless.
        }
    }

    /**
     * Mirror values into the egg's startup variables, so an egg that fills
     * serverconfig.txt from them at start does not undo the change.
     *
     * @param  array<string, string>  $values  variable => value
     * @return array{updated: list<string>, skipped: list<string>}
     */
    private function setVariables(Server $server, array $values, bool $allowed): array
    {
        $result = ['updated' => [], 'skipped' => []];
        foreach ($values as $env => $value) {
            if ($allowed && $this->variables->canSet($server, $env, $value)) {
                $this->variables->set($server, $env, $value);
                $result['updated'][] = $env;
            } else {
                $result['skipped'][] = $env;
            }
        }

        return $result;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $run
     * @return T
     */
    private function locked(Server $server, callable $run): mixed
    {
        try {
            return Cache::lock('ext:terraria-tools:worlds:'.$server->uuid, 600)->block(5, $run);
        } catch (LockTimeoutException) {
            throw new HttpException(409, 'Another world action is running. Try again in a moment.');
        }
    }
}
