<?php

namespace ValheimTools\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Pterodactyl\Models\Server;
use Symfony\Component\HttpKernel\Exception\HttpException;
use ValheimTools\Http\Controllers\Concerns\ValheimServer;
use ValheimTools\Services\StartupVariables;
use ValheimTools\Services\Worlds;
use ValheimTools\Support\ServerSettings;

/**
 * The server settings card: name, public, crossplay, password and world preset, kept
 * in the egg's startup variables like the Startup page does.
 */
class SettingsController extends Controller
{
    use ValheimServer;

    private const string PERMISSION = 'ext.valheim-tools.settings';

    public function __construct(
        private readonly StartupVariables $variables,
        private readonly Worlds $worlds,
    ) {}

    public function show(Request $request, Server $server): JsonResponse
    {
        $this->authorizeSettings($request, $server);

        return new JsonResponse($this->payload($request, $server));
    }

    public function update(Request $request, Server $server): JsonResponse
    {
        $this->authorizeSettings($request, $server);
        $this->ensureCan($request, $server, 'startup.update');

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:200'],
            'password' => ['sometimes', 'string', 'max:200'],
            'public' => ['sometimes', 'boolean'],
            'crossplay' => ['sometimes', 'boolean'],
            'preset' => ['sometimes', 'nullable', 'string', 'max:30'],
        ]);

        $values = [];
        $errors = [];
        $world = (string) ($this->worlds->activeName($server) ?? '');
        $currentPassword = (string) $this->variables->get($server, 'PASSWORD');
        $password = $data['password'] ?? $currentPassword;
        $name = $data['name'] ?? (string) $this->variables->get($server, 'SERVER_NAME');

        if (array_key_exists('name', $data)) {
            $errors['name'] = ServerSettings::validateName($data['name']);
            $values['SERVER_NAME'] = $data['name'];
        }
        if (array_key_exists('password', $data)) {
            $errors['password'] = ServerSettings::validatePassword($data['password'], $world);
            $values['PASSWORD'] = $data['password'];
        }
        foreach (['public' => 'PUBLIC_SERVER', 'crossplay' => 'ENABLE_CROSSPLAY'] as $field => $env) {
            if (array_key_exists($field, $data)) {
                $values[$env] = ServerSettings::flag($data[$field]) ?? '0';
            }
        }
        if (array_key_exists('preset', $data)) {
            $variable = $this->presetVariable($server);
            if ($variable === null) {
                $errors['preset'] = 'This server has no world preset variable.';
            } else {
                $preset = strtolower(trim((string) $data['preset']));
                $errors['preset'] = ServerSettings::validatePreset($preset);
                $values[$variable] = $preset;
            }
        }

        $errors = array_filter($errors);
        if ($errors !== []) {
            throw new HttpException(422, implode(' ', $errors));
        }

        $this->variables->setMany($server, $values);

        return new JsonResponse(['warning' => ServerSettings::nameWarning($name, $password)] + $this->payload($request, $server));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request, Server $server): array
    {
        $field = fn (string $env): array => [
            'present' => $this->variables->exists($server, $env),
            'editable' => $this->variables->editable($server, $env),
            'value' => $this->variables->exists($server, $env) ? $this->variables->get($server, $env) : null,
        ];

        $preset = $this->presetVariable($server);
        $name = (string) $this->variables->get($server, 'SERVER_NAME');
        $password = (string) $this->variables->get($server, 'PASSWORD');

        return [
            'name' => $field('SERVER_NAME'),
            'password' => $field('PASSWORD'),
            'public' => $field('PUBLIC_SERVER'),
            'crossplay' => $field('ENABLE_CROSSPLAY'),
            'preset' => $preset === null ? ['present' => false, 'editable' => false, 'value' => null] : $field($preset),
            'presets' => ServerSettings::PRESETS,
            'world' => $this->worlds->activeName($server),
            'warning' => ServerSettings::nameWarning($name, $password),
            'limits' => ['name' => ServerSettings::NAME_MAX, 'password_min' => ServerSettings::PASSWORD_MIN, 'password_max' => ServerSettings::PASSWORD_MAX],
            'can_update' => $request->user()->can('startup.update', $server),
        ];
    }

    private function presetVariable(Server $server): ?string
    {
        foreach (ServerSettings::PRESET_VARIABLES as $env) {
            if ($this->variables->exists($server, $env)) {
                return $env;
            }
        }

        return null;
    }

    private function authorizeSettings(Request $request, Server $server): void
    {
        $this->ensureValheim($server);
        $this->ensureCan($request, $server, [self::PERMISSION, 'startup.read'], 'You do not have permission to see the server settings.');
    }
}
