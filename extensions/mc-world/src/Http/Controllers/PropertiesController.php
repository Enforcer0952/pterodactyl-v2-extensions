<?php

namespace McWorld\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use McWorld\Http\Controllers\Concerns\McWorldServer;
use McWorld\Services\PropertiesFile;
use McWorld\Services\Worlds;
use McWorld\Support\Properties;
use McWorld\Support\PropertyModel;
use Pterodactyl\Models\Server;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** The Properties card: server.properties as a form and a key/value table. */
class PropertiesController extends Controller
{
    use McWorldServer;

    public function __construct(
        private readonly PropertiesFile $file,
        private readonly Worlds $worlds,
    ) {}

    public function show(Request $request, Server $server): JsonResponse
    {
        $this->ensureJava($server);
        $this->ensureCan($request, $server, ['file.read', 'file.read-content'], 'You do not have permission to read this server\'s files.');

        return new JsonResponse($this->guard(fn (): array => $this->payload($server)));
    }

    public function update(Request $request, Server $server): JsonResponse
    {
        $this->ensureJava($server);
        $this->ensureCan($request, $server, ['file.read', 'file.read-content', 'file.update', 'file.create'], 'You do not have permission to change server.properties.');

        $data = $request->validate([
            'hash' => ['required', 'string', 'size:40'],
            'edits' => ['required', 'array', 'min:1', 'max:300'],
            'edits.*.key' => ['required', 'string', 'max:100'],
            // Values arrive base64-encoded: the panel trims request strings and turns
            // an empty one into null, which would change a motd or empty a seed.
            'edits.*.value' => ['nullable', 'string', 'max:12000'],
            'edits.*.remove' => ['sometimes', 'boolean'],
        ]);

        $changes = [];
        foreach ($data['edits'] as $edit) {
            if (preg_match(PropertyModel::KEY, $edit['key']) !== 1) {
                throw new HttpException(422, 'Keys may only use letters, digits, dots, dashes and underscores.');
            }
            if (! empty($edit['remove'])) {
                $changes[$edit['key']] = null;

                continue;
            }
            $decoded = base64_decode((string) ($edit['value'] ?? ''), true);
            if ($decoded === false || ! mb_check_encoding($decoded, 'UTF-8')) {
                throw new HttpException(422, $edit['key'].' could not be read. Reload the page and try again.');
            }
            $changes[$edit['key']] = str_replace(["\r\n", "\r"], "\n", $decoded);
        }

        $result = $this->guard(fn (): array => $this->file->change($server, $changes, $data['hash']));

        return new JsonResponse(['changed' => $result['changed']] + $this->guard(fn (): array => $this->payload($server)));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Server $server): array
    {
        $file = $this->file->read($server);
        $entries = [];
        foreach (Properties::parse($file['raw'])->all() as $key => $value) {
            $entries[] = ['key' => (string) $key, 'value' => $value];
        }

        return [
            'state' => $this->worlds->serverState($server),
            'exists' => $file['exists'],
            'hash' => $file['hash'],
            'entries' => $entries,
            'schema' => ['groups' => PropertyModel::groups(), 'settings' => PropertyModel::settings()],
        ];
    }
}
