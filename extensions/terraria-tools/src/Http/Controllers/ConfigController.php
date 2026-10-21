<?php

namespace TerrariaTools\Http\Controllers;

use InvalidArgumentException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Pterodactyl\Contracts\Servers\ReadsServerState;
use Pterodactyl\Models\Server;
use Symfony\Component\HttpKernel\Exception\HttpException;
use TerrariaTools\Http\Controllers\Concerns\TerrariaServer;
use TerrariaTools\Services\ConfigFile;
use TerrariaTools\Services\StartupVariables;
use TerrariaTools\Support\ServerConfig;
use TerrariaTools\Support\ServerSettings;

/**
 * serverconfig.txt as a form and a raw editor.
 *
 * The password is only sent to people who could read it anyway: those who may edit
 * the file or the startup variables.
 */
class ConfigController extends Controller
{
    use TerrariaServer;

    private const string PERMISSION = 'ext.terraria-tools.admin';

    public function __construct(
        private readonly ConfigFile $file,
        private readonly StartupVariables $variables,
        private readonly ReadsServerState $state,
    ) {}

    public function show(Request $request, Server $server): JsonResponse
    {
        $this->authorizeConfig($request, $server);

        return new JsonResponse($this->payload($request, $server, $this->file->read($server)));
    }

    public function update(Request $request, Server $server): JsonResponse
    {
        $this->authorizeConfig($request, $server);
        $this->ensureCan($request, $server, 'file.update');

        $data = $request->validate([
            // Raw text arrives base64-encoded: the panel trims request strings, which
            // would drop a file's trailing newline.
            'raw' => ['sometimes', 'nullable', 'string', 'max:400000'],
            'edits' => ['sometimes', 'array', 'max:20'],
            'edits.*' => ['present', 'nullable', 'string', 'max:500'],
            // The hash of the file as it was loaded: a save without it is refused.
            'hash' => ['required', 'string', 'regex:/^[0-9a-f]{40}$/D'],
        ]);

        $raw = null;
        if (isset($data['raw'])) {
            $raw = base64_decode($data['raw'], true);
            if ($raw === false || ! mb_check_encoding($raw, 'UTF-8') || strlen($raw) > ConfigFile::MAX_BYTES) {
                throw new HttpException(422, 'The text could not be read. Reload the page and try again.');
            }
        }
        $edits = array_map(fn (mixed $value): string => (string) $value, $data['edits'] ?? []);
        foreach (array_keys($edits) as $key) {
            if (! in_array(strtolower((string) $key), ServerSettings::keys(), true)) {
                throw new HttpException(422, 'Unknown setting '.$key.'.');
            }
        }

        $before = null;
        $after = null;
        $written = $this->file->update($server, function (?string $current) use ($raw, $edits, &$before, &$after): ?string {
            $before = ServerConfig::parse($current ?? '');
            $config = ServerConfig::parse($raw ?? $current ?? '');

            try {
                ServerSettings::apply($config, $edits);
                // A form field the raw text changed by hand must be valid too.
                foreach (ServerSettings::keys() as $key) {
                    $value = $config->get($key);
                    if ($value !== null && $value !== $before->get($key) && ($error = ServerSettings::validate($key, $value)) !== null) {
                        throw new InvalidArgumentException($error);
                    }
                }
            } catch (InvalidArgumentException $exception) {
                throw new HttpException(422, $exception->getMessage());
            }

            $after = $config;

            return $config->render();
        }, $data['hash']);

        $synced = ['updated' => [], 'skipped' => []];
        if ($written && $before instanceof ServerConfig && $after instanceof ServerConfig) {
            $synced = $this->syncVariables($request, $server, $before, $after);
        }

        return new JsonResponse(['written' => $written, 'variables' => $synced] + $this->payload($request, $server, $this->file->read($server)));
    }

    /**
     * Mirror changed settings into the egg's startup variables, so an egg that fills
     * serverconfig.txt from them at start does not undo the change.
     *
     * @return array{updated: list<string>, skipped: list<string>}
     */
    private function syncVariables(Request $request, Server $server, ServerConfig $before, ServerConfig $after): array
    {
        $result = ['updated' => [], 'skipped' => []];
        $allowed = $request->user()->can('startup.update', $server);
        foreach (ServerSettings::keys() as $key) {
            $new = $after->get($key);
            $env = ServerSettings::variableFor($key);
            if ($env === null || $new === null || $new === $before->get($key)) {
                continue;
            }
            if ($allowed && $this->variables->canSet($server, $env, $new)) {
                $this->variables->set($server, $env, $new);
                $result['updated'][] = $env;
            } else {
                $result['skipped'][] = $env;
            }
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request, Server $server, ?string $contents): array
    {
        $config = ServerConfig::parse($contents ?? '');
        $reveal = $this->canReveal($request, $server);

        return [
            'state' => $this->serverState($this->state, $server),
            'exists' => $contents !== null,
            'hash' => ConfigFile::hash($contents),
            // The password never leaves the panel for people who may not read it.
            'raw' => $reveal ? ($contents ?? '') : $config->masked(['password'], ServerSettings::MASK),
            'masked' => ! $reveal,
            'editable' => $request->user()->can('file.update', $server),
            'fields' => ServerSettings::fields($config, $reveal),
        ];
    }

    private function canReveal(Request $request, Server $server): bool
    {
        $user = $request->user();

        return $user->can('file.update', $server) || $user->can('startup.update', $server);
    }

    private function authorizeConfig(Request $request, Server $server): void
    {
        $this->ensureTerraria($server);
        $this->ensureCan($request, $server, [self::PERMISSION, 'file.read', 'file.read-content'], 'You do not have permission to see this server\'s settings.');
    }
}
