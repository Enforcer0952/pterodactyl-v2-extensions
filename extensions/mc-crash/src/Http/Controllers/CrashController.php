<?php

namespace McCrash\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use McCrash\Http\Controllers\Concerns\CrashServer;
use McCrash\Services\Badge;
use McCrash\Services\CrashReports;
use McCrash\Services\JarIndex;
use McCrash\Services\LogScans;
use McCrash\Services\Sharing;
use McCrash\Support\Redact;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Facades\Extensions;
use Pterodactyl\Models\Server;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Crash reports and log errors with a verdict for each: what broke, which plugin or mod did it, and how to fix it.
 */
class CrashController extends Controller
{
    use CrashServer;

    /** Raw text sent to the browser. */
    private const int MAX_RAW_BYTES = 1024 * 1024;

    /** The part of the redacted text shown in the confirmation; the summary covers all of it. */
    private const int MAX_PREVIEW_BYTES = 200 * 1024;

    public function __construct(
        private readonly JarIndex $index,
        private readonly CrashReports $reports,
        private readonly LogScans $logs,
        private readonly Badge $badge,
        private readonly Sharing $sharing,
    ) {}

    /** The count behind the badge on the console page. */
    public function status(Request $request, Server $server): JsonResponse
    {
        $this->authorizeView($request, $server);

        return new JsonResponse($this->node(fn (): array => $this->badge->status($server, $request->user())));
    }

    /** The user has opened the Crashes page: the badge starts counting from now. */
    public function viewed(Request $request, Server $server): JsonResponse
    {
        $this->authorizeView($request, $server);
        $this->badge->markViewed($server, $request->user());

        return new JsonResponse(['new' => 0]);
    }

    public function reports(Request $request, Server $server): JsonResponse
    {
        $this->authorizeView($request, $server);

        return new JsonResponse($this->node(function () use ($server): array {
            $index = $this->index->forServer($server);
            $list = $this->reports->list($server, $index);

            return $list + ['index' => $this->progress($index), 'links' => $this->links(), 'sharing' => $this->sharingOn()];
        }));
    }

    public function logs(Request $request, Server $server): JsonResponse
    {
        $this->authorizeView($request, $server);

        return new JsonResponse($this->node(function () use ($server): array {
            $index = $this->index->forServer($server);
            $scan = $this->logs->scan($server, $index);

            return $scan + ['index' => $this->progress($index), 'links' => $this->links(), 'sharing' => $this->sharingOn()];
        }));
    }

    public function raw(Request $request, Server $server): JsonResponse
    {
        $this->authorizeView($request, $server);
        $path = (string) $request->validate(['path' => ['required', 'string', 'max:200']])['path'];

        $raw = $this->node(fn (): ?array => $this->reports->raw($server, $path, self::MAX_RAW_BYTES));
        abort_if($raw === null, 404, 'That report is not there any more.');

        return new JsonResponse($raw);
    }

    /**
     * What an upload would send: the file with IP addresses, login commands and JVM option values removed.
     * The hash identifies this exact text, so the upload can refuse a file that changed after the preview.
     */
    public function preview(Request $request, Server $server): JsonResponse
    {
        $this->authorizeShare($request, $server);
        $path = (string) $request->validate(['path' => ['required', 'string', 'max:200']])['path'];

        $prepared = $this->prepared($server, $path);
        $text = $prepared['text'];

        return new JsonResponse([
            'text' => mb_strcut($text, 0, self::MAX_PREVIEW_BYTES, 'UTF-8'),
            'shown_all' => strlen($text) <= self::MAX_PREVIEW_BYTES,
            'bytes' => strlen($text),
            'lines' => substr_count($text, "\n") + 1,
            'hash' => sha1($text),
            'hidden' => ['ips' => $prepared['ips'], 'commands' => $prepared['commands'], 'jvm_args' => $prepared['jvm_args']],
        ]);
    }

    public function share(Request $request, Server $server): JsonResponse
    {
        $this->authorizeShare($request, $server);

        $data = $request->validate(['path' => ['required', 'string', 'max:200'], 'hash' => ['required', 'string', 'size:40']]);
        $path = (string) $data['path'];

        $text = $this->prepared($server, $path)['text'];
        if (! hash_equals(sha1($text), (string) $data['hash'])) {
            throw new HttpException(409, 'The file changed after the preview. Open the preview again before uploading.');
        }

        $url = $this->sharing->upload($text);

        Activity::event('server:crash.share')->subject($server)->property(['file' => $path, 'url' => $url])->log();

        return new JsonResponse(['url' => $url]);
    }

    private function authorizeShare(Request $request, Server $server): void
    {
        $this->authorizeView($request, $server);
        abort_unless($this->sharingOn(), 403, 'Sharing is turned off on this panel.');
        abort_unless($request->user()->can('ext.mc-crash.share', $server), 403, 'You do not have permission to share crash reports.');
    }

    /**
     * The text of a listed report or log, cleaned of private details.
     *
     * @return array{text: string, ips: int, commands: int, jvm_args: int}
     */
    private function prepared(Server $server, string $path): array
    {
        $text = $this->node(function () use ($server, $path): ?string {
            $report = $this->reports->raw($server, $path, Sharing::MAX_BYTES);
            if ($report !== null) {
                return $report['text'];
            }

            return $this->logs->tailOf($server, $path, 4 * 1024 * 1024, Sharing::MAX_LINES)['text'] ?? null;
        });
        if ($text === null) {
            throw new HttpException(404, 'That file is not there any more.');
        }

        return Redact::apply($text, Redact::isJvmLog($path));
    }

    /**
     * @param  array<string, mixed>  $index
     * @return array{total: int, pending: int}
     */
    private function progress(array $index): array
    {
        return ['total' => $index['total'], 'pending' => $index['pending']];
    }

    /** Whether the Plugins and Mods pages of mc-content exist, so the culprit can link to them. */
    private function links(): bool
    {
        try {
            return Extensions::isAvailable('mc-content');
        } catch (\Throwable) {
            return false;
        }
    }

    private function sharingOn(): bool
    {
        return app('ext.mc-crash.settings')->get('sharing', 'on') === 'on';
    }
}
