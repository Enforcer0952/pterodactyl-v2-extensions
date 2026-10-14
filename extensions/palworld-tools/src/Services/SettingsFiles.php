<?php

namespace PalworldTools\Services;

use Carbon\CarbonImmutable;
use PalworldTools\Support\SettingsModel;
use Pterodactyl\Models\Server;
use Throwable;

/**
 * PalWorldSettings.ini and its template. The file lives in Pal/Saved/Config/LinuxServer
 * (WindowsServer for Proton eggs). Every write first saves the old file as
 * "PalWorldSettings.ini.<date>.bak" next to it and keeps the newest five.
 *
 * Location and template: https://docs.palworldgame.com/settings-and-operation/configuration
 */
class SettingsFiles
{
    public const string NAME = 'PalWorldSettings.ini';

    public const string TEMPLATE = 'DefaultPalWorldSettings.ini';

    public const int MAX_BYTES = 1024 * 1024;

    private const int KEEP_BACKUPS = 5;

    private const array DIRECTORIES = ['/Pal/Saved/Config/LinuxServer', '/Pal/Saved/Config/WindowsServer'];

    public function __construct(
        private readonly ServerFiles $files,
        private readonly SettingsModel $model,
    ) {}

    /**
     * The folder the settings file is in, or should be in when it does not exist yet.
     *
     * @return array{directory: string, exists: bool}
     */
    public function locate(Server $server): array
    {
        foreach (self::DIRECTORIES as $directory) {
            if ($this->files->exists($server, $directory, self::NAME)) {
                return ['directory' => $directory, 'exists' => true];
            }
        }
        // A Proton server has a WindowsServer folder only.
        if (! $this->files->exists($server, '/Pal/Saved/Config', 'LinuxServer', false) && $this->files->exists($server, '/Pal/Saved/Config', 'WindowsServer', false)) {
            return ['directory' => self::DIRECTORIES[1], 'exists' => false];
        }

        return ['directory' => self::DIRECTORIES[0], 'exists' => false];
    }

    public function read(Server $server): ?string
    {
        return $this->files->read($server, $this->locate($server)['directory'], self::NAME, self::MAX_BYTES);
    }

    /** The game's default settings file in the install folder, if there. */
    public function template(Server $server): ?string
    {
        return $this->files->read($server, '/', self::TEMPLATE, self::MAX_BYTES);
    }

    /** The text to edit: the current file, or the template when the file is missing or empty. */
    public function base(Server $server, ?string $current): string
    {
        $template = ($current === null || trim($current) === '' || ! str_contains($current, 'OptionSettings')) ? $this->template($server) : null;

        return $this->model->base($current, $template);
    }

    public static function hash(SettingsModel $model, ?string $current): string
    {
        return sha1($model->mask($current ?? ''));
    }

    public function write(Server $server, string $contents, ?string $current): void
    {
        $directory = $this->locate($server)['directory'];
        $this->files->ensureDirectory($server, $directory);

        if ($current !== null && trim($current) !== '') {
            $this->files->write($server, $directory, self::NAME.'.'.CarbonImmutable::now('UTC')->format('Ymd-His').'.bak', $current);
        }
        $this->files->write($server, $directory, self::NAME, $contents);
        $this->prune($server, $directory);
    }

    /** Old backups are removed best effort: a failure here never fails the save. */
    private function prune(Server $server, string $directory): void
    {
        try {
            $backups = [];
            foreach ($this->files->entries($server, $directory) as $entry) {
                $name = (string) ($entry['name'] ?? '');
                if (($entry['file'] ?? true) !== false && preg_match('/^'.preg_quote(self::NAME, '/').'\.\d{8}-\d{6}\.bak$/D', $name) === 1) {
                    $backups[] = $name;
                }
            }
            rsort($backups);
            $this->files->delete($server, $directory, array_slice($backups, self::KEEP_BACKUPS));
        } catch (Throwable) {
            // Keeping an extra backup is harmless.
        }
    }
}
