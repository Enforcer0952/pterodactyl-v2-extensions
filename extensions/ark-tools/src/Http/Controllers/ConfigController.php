<?php

namespace ArkTools\Http\Controllers;

use ArkTools\Http\Controllers\Concerns\ArkServer;
use ArkTools\Services\ConfigApply;
use ArkTools\Services\ConfigFiles;
use ArkTools\Services\Restarts;
use ArkTools\Services\StartupVariables;
use ArkTools\Support\ConfigModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Pterodactyl\Contracts\Servers\ReadsServerState;
use Pterodactyl\Models\Server;

/**
 * The Config page: GameUserSettings.ini and Game.ini as a form, a raw editor and a
 * list of problems, saved safely around ARK's own shutdown write.
 */
class ConfigController extends Controller
{
    use ArkServer;

    private const string PERMISSION = 'ext.ark-tools.config';

    public function __construct(
        private readonly ConfigFiles $files,
        private readonly ConfigModel $model,
        private readonly ConfigApply $apply,
        private readonly Restarts $restarts,
        private readonly StartupVariables $startup,
        private readonly ReadsServerState $state,
    ) {}

    public function show(Request $request, Server $server): JsonResponse
    {
        $this->authorizeConfig($request, $server);

        return new JsonResponse($this->payload($server));
    }

    public function update(Request $request, Server $server): JsonResponse
    {
        $this->authorizeConfig($request, $server);

        $files = implode(',', array_keys($this->model->files()));
        $data = $request->validate([
            // Raw text arrives base64-encoded: the panel trims request strings, which
            // would drop a file's trailing newline and turn an empty file into null.
            'raw' => ['sometimes', 'array', 'max:2'],
            'raw.*' => ['string', 'max:5600000'],
            'edits' => ['sometimes', 'array', 'max:500'],
            'edits.*.file' => ['required', 'string', 'in:'.$files],
            'edits.*.section' => ['required', 'string', 'max:200'],
            'edits.*.key' => ['required', 'string', 'max:128'],
            'edits.*.value' => ['present', 'nullable', 'string', 'max:2000'],
            'hashes' => ['required', 'array'],
            'hashes.*' => ['string', 'size:40'],
            'minutes' => ['sometimes', 'integer', 'in:'.implode(',', Restarts::MINUTES)],
            'start' => ['sometimes', 'boolean'],
        ]);

        $raw = [];
        foreach (array_intersect_key($data['raw'] ?? [], $this->model->files()) as $file => $encoded) {
            $decoded = base64_decode($encoded, true);
            if ($decoded === false || ! mb_check_encoding($decoded, 'UTF-8')) {
                throw new \Symfony\Component\HttpKernel\Exception\HttpException(422, $file.' could not be read. Reload the page and try again.');
            }
            $raw[$file] = $decoded;
        }
        $edits = array_map(fn (array $edit): array => [
            'file' => $edit['file'],
            'section' => $edit['section'],
            'key' => $edit['key'],
            'value' => $edit['value'],
        ], array_values($data['edits'] ?? []));

        $result = $this->apply->save(
            $server,
            $request->user()?->id,
            $raw,
            $edits,
            $data['hashes'],
            (int) ($data['minutes'] ?? 5),
            (bool) ($data['start'] ?? false),
        );

        return new JsonResponse(['result' => $result] + $this->payload($server));
    }

    public function cancel(Request $request, Server $server): JsonResponse
    {
        $this->authorizeConfig($request, $server);
        $this->apply->cancel($server);

        return new JsonResponse($this->payload($server));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Server $server): array
    {
        $contents = $this->files->read($server);
        $startupKeys = ConfigModel::startupKeys((string) $server->startup, (string) $this->startup->get($server, 'ARGS'));

        $files = [];
        foreach ($contents as $name => $text) {
            $files[$name] = [
                'path' => $this->model->files()[$name],
                'exists' => $text !== null,
                'raw' => $text === null ? '' : $this->model->mask($text),
                'hash' => ConfigFiles::hash($text),
            ];
        }

        $schema = $this->model->schema();

        return [
            'state' => $this->serverState($this->state, $server),
            'files' => $files,
            'fields' => $this->model->fields($contents, $startupKeys),
            'issues' => $this->model->issues($contents),
            'apply' => $this->apply->status($server),
            'restart' => $this->restarts->status($server)['running'],
            'minutes' => Restarts::MINUTES,
            'schema' => [
                'groups' => $schema['groups'],
                'settings' => $schema['settings'],
                'presets' => $schema['presets'],
            ],
        ];
    }

    private function authorizeConfig(Request $request, Server $server): void
    {
        $this->ensureArk($server);
        $this->ensureCan($request, $server, self::PERMISSION, 'You do not have permission to change this server\'s settings.');
    }
}
