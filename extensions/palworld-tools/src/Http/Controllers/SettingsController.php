<?php

namespace PalworldTools\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use InvalidArgumentException;
use PalworldTools\Http\Controllers\Concerns\PalworldServer;
use PalworldTools\Services\RestClient;
use PalworldTools\Services\SettingsApply;
use PalworldTools\Services\SettingsFiles;
use PalworldTools\Services\StartupVariables;
use PalworldTools\Support\OptionSettings;
use PalworldTools\Support\SettingsModel;
use PalworldTools\Support\StartupOverrides;
use Pterodactyl\Contracts\Servers\ReadsServerState;
use Pterodactyl\Models\Server;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The Settings page: PalWorldSettings.ini as a form and a raw editor, saved safely
 * around the server being stopped.
 */
class SettingsController extends Controller
{
    use PalworldServer;

    private const string PERMISSION = 'ext.palworld-tools.settings';

    public function __construct(
        private readonly SettingsFiles $files,
        private readonly SettingsModel $model,
        private readonly SettingsApply $apply,
        private readonly StartupVariables $variables,
        private readonly RestClient $rest,
        private readonly ReadsServerState $state,
    ) {}

    public function show(Request $request, Server $server): JsonResponse
    {
        $this->authorizeSettings($request, $server);

        return new JsonResponse($this->payload($request, $server));
    }

    public function update(Request $request, Server $server): JsonResponse
    {
        $this->authorizeSettings($request, $server);
        $this->ensureCan($request, $server, 'file.update', 'You need permission to edit files to change the settings.');

        $data = $request->validate([
            // Raw text arrives base64-encoded: the panel trims request strings, which
            // would drop the file's trailing newline.
            'raw' => ['sometimes', 'nullable', 'string', 'max:1500000'],
            'edits' => ['sometimes', 'array', 'max:150'],
            'edits.*.key' => ['required', 'string', 'max:64'],
            'edits.*.value' => ['present', 'nullable', 'string', 'max:2000'],
            'hash' => ['required', 'string', 'size:40'],
            'restart' => ['sometimes', 'boolean'],
            'start' => ['sometimes', 'boolean'],
        ]);

        $raw = null;
        if (isset($data['raw'])) {
            $raw = base64_decode($data['raw'], true);
            if ($raw === false || ! mb_check_encoding($raw, 'UTF-8') || strlen($raw) > SettingsFiles::MAX_BYTES) {
                throw new HttpException(422, 'The text could not be read. Reload the page and try again.');
            }
        }

        $result = $this->submit($request, $server, $raw, array_values($data['edits'] ?? []), $data['hash'], (bool) ($data['restart'] ?? false), (bool) ($data['start'] ?? false));

        return new JsonResponse(['result' => $result] + $this->payload($request, $server));
    }

    /** Turn the REST API on (the Players card button). Applies after a restart. */
    public function enableRest(Request $request, Server $server): JsonResponse
    {
        $this->authorizeSettings($request, $server);
        $this->ensureCan($request, $server, 'file.update', 'You need permission to edit files to change the settings.');
        $data = $request->validate(['restart' => ['sometimes', 'boolean']]);

        $current = $this->files->read($server);
        $text = $this->files->base($server, $current);
        try {
            $options = OptionSettings::parse($text);
        } catch (InvalidArgumentException $exception) {
            throw new HttpException(422, $exception->getMessage());
        }
        if ((string) $options->get('AdminPassword') === '') {
            throw new HttpException(422, 'Set an admin password in the settings first. The REST API uses it to log in.');
        }

        $result = $this->submit(
            $request,
            $server,
            null,
            [['key' => 'RESTAPIEnabled', 'value' => 'True']],
            SettingsFiles::hash($this->model, $current),
            (bool) ($data['restart'] ?? false),
            false,
        );

        $port = (int) ($options->get('RESTAPIPort') ?? 8212);

        return new JsonResponse([
            'result' => $result,
            'port' => $port,
            'allocated' => $this->rest->isAllocated($server, $port),
        ]);
    }

    /** The page calls this while a change waits: write it as soon as the server is off. */
    public function process(Request $request, Server $server): JsonResponse
    {
        $this->authorizeSettings($request, $server);
        $this->apply->process($server);

        return new JsonResponse($this->payload($request, $server));
    }

    public function cancel(Request $request, Server $server): JsonResponse
    {
        $this->authorizeSettings($request, $server);
        $this->ensureCan($request, $server, 'file.update', 'You need permission to edit files to change the settings.');
        $this->apply->cancel($server);

        return new JsonResponse($this->payload($request, $server));
    }

    /**
     * @param  list<array{key: string, value: ?string}>  $edits
     * @return 'unchanged'|'saved'|'waiting'
     */
    private function submit(Request $request, Server $server, ?string $raw, array $edits, string $hash, bool $restart, bool $start): string
    {
        $user = $request->user();
        $canStartup = $user->can('startup.update', $server);
        $canSecrets = $user->can('file.update', $server) || $canStartup;

        $startup = (string) $server->startup;
        $visible = $this->variables->visible($server);
        $locks = StartupOverrides::locks($startup, $this->envMap(), array_keys($visible));
        $parser = StartupOverrides::usesParser($startup);

        $vars = [];
        foreach ($edits as $edit) {
            $setting = $this->model->setting($edit['key']) ?? throw new HttpException(422, 'Unknown setting '.$edit['key'].'.');
            $lock = $locks[$setting['key']] ?? null;
            if ($lock === null) {
                continue;
            }
            if ($lock['kind'] === 'startup') {
                throw new HttpException(422, $setting['label'].' is set in the startup command, which wins over the file. It cannot be changed here.');
            }
            if ($lock['kind'] === 'allocation') {
                throw new HttpException(422, $setting['label'].' comes from the server\'s port. Change the port on the Network page.');
            }

            $variable = $visible[$lock['env']];
            if (! $this->variables->editable($variable) || ! $canStartup) {
                throw new HttpException(403, $setting['label'].' comes from the '.$lock['env'].' startup variable, and you may not change it.');
            }
            if ($edit['value'] === null) {
                throw new HttpException(422, $setting['label'].' comes from the '.$lock['env'].' startup variable and needs a value.');
            }
            try {
                $value = OptionSettings::display($this->model->format($setting['key'], $edit['value']));
            } catch (InvalidArgumentException $exception) {
                throw new HttpException(422, $exception->getMessage());
            }
            // The egg's parser tool finds a value by looking for the next comma or bracket.
            if ($parser && preg_match('/[,()"]/', $value) === 1) {
                throw new HttpException(422, $setting['label'].' cannot contain commas, brackets or quotes: the egg\'s config tool would break the file.');
            }
            $this->variables->validate($variable, $value);
            $vars[$lock['env']] = $value;
        }

        $state = $this->serverState($this->state, $server);
        if ($state !== 'offline' && $restart) {
            $this->ensureCan($request, $server, ['control.stop', 'control.start'], 'You need permission to stop and start the server to save with a restart.');
        }
        if ($state === 'offline' && $start) {
            $this->ensureCan($request, $server, 'control.start', 'You need permission to start the server.');
        }
        if ($state === 'running' && $restart) {
            // Let the game write the world before it is stopped. Best effort.
            try {
                $this->rest->post($server, '/save');
            } catch (\Throwable) {
            }
        }

        return $this->apply->save($server, $user->id, [
            'raw' => $raw,
            'edits' => $edits,
            'vars' => $vars,
            'secrets' => $canSecrets,
        ], $hash, $restart, $start);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request, Server $server): array
    {
        $user = $request->user();
        $canStartup = $user->can('startup.update', $server);
        $canSecrets = $user->can('file.update', $server) || $canStartup;

        $current = $this->files->read($server);
        $text = $this->files->base($server, $current);
        $location = $this->files->locate($server);

        $startup = (string) $server->startup;
        $visible = $this->variables->visible($server);
        $locks = StartupOverrides::locks($startup, $this->envMap(), array_keys($visible));

        $fields = [];
        foreach ($this->model->fields($text) as $field) {
            $secret = $this->model->isSecret($field['key']);
            if ($secret && ! $canSecrets) {
                continue;
            }

            $lock = $locks[$field['key']] ?? null;
            if ($lock !== null && $lock['kind'] === 'variable') {
                $variable = $visible[$lock['env']];
                $value = $this->variables->value($variable);
                if ($secret) {
                    $field['set'] = $value !== '';
                } else {
                    $field['value'] = $value;
                }
                $lock['editable'] = $this->variables->editable($variable) && $canStartup;
            }
            $field['lock'] = $lock;
            $fields[] = $field;
        }

        $schema = $this->model->schema();
        $settings = array_values(array_filter(
            $schema['settings'],
            fn (array $setting): bool => $canSecrets || ! $this->model->isSecret($setting['key']),
        ));

        return [
            'state' => $this->serverState($this->state, $server),
            'file' => [
                'path' => ltrim($location['directory'], '/').'/'.SettingsFiles::NAME,
                'exists' => $current !== null,
                // True when the file is empty or missing and the game's default file is shown.
                'from_template' => $current === null || $text !== $current,
                'raw' => $this->model->mask($text),
                'hash' => SettingsFiles::hash($this->model, $current),
            ],
            'fields' => $fields,
            'issues' => array_map(fn (array $issue): array => $issue, $this->model->issues($text)),
            'apply' => $this->apply->status($server),
            'parser' => StartupOverrides::usesParser($startup),
            'schema' => [
                'groups' => $schema['groups'],
                'settings' => $settings,
                'presets' => $schema['presets'],
            ],
            'can' => [
                'secrets' => $canSecrets,
                'control' => $user->can('control.stop', $server) && $user->can('control.start', $server),
                'start' => $user->can('control.start', $server),
                'write' => $user->can('file.update', $server),
            ],
        ];
    }

    /**
     * @return array<string, string> setting key => environment variable
     */
    private function envMap(): array
    {
        $map = [];
        foreach ($this->model->schema()['settings'] as $setting) {
            if (isset($setting['env'])) {
                $map[$setting['key']] = $setting['env'];
            }
        }

        return $map;
    }

    private function authorizeSettings(Request $request, Server $server): void
    {
        $this->ensurePalworld($server);
        $this->ensureCan($request, $server, self::PERMISSION, 'You do not have permission to see this server\'s settings.');
    }
}
