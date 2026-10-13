<?php

namespace McVersion\Services;

use McVersion\Support\Backups;
use McVersion\Support\JavaImages;
use McVersion\Support\Mismatches;
use McVersion\Support\Software;
use Pterodactyl\Contracts\Servers\ReadsServerState;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;

/**
 * Everything the Version page shows about a server in one answer.
 */
class Overview
{
    public function __construct(
        private readonly Detector $detector,
        private readonly ServerFiles $files,
        private readonly Startup $startup,
        private readonly Copies $copies,
        private readonly ReadsServerState $state,
    ) {}

    /**
     * What the server runs now, or will run once started: the last start's files,
     * unless this page replaced the jar since.
     *
     * @return array{software: ?string, version: ?string, build: ?string, source: ?string, log_modified: ?int, pending: ?array{software: ?string, version: ?string, build: ?string, at: int}, copies: list<array<string, mixed>>, root: list<array<string, mixed>>, jar: string}
     */
    public function effective(Server $server, bool $fresh = false): array
    {
        $jar = $this->startup->jar($server);
        $detected = $this->detector->detect($server, $fresh);
        $root = $this->files->list($server, '/') ?? [];
        $copies = $this->copies->list($server, $jar, $root);
        $pending = Copies::pending($copies, $detected['log_modified']);

        return $detected + ['pending' => $pending, 'copies' => $copies, 'root' => $root, 'jar' => $jar];
    }

    /**
     * @return array<string, mixed>
     */
    public function build(Server $server, User $user): array
    {
        $now = $this->effective($server, fresh: true);
        $jar = $now['jar'];
        $jarEntry = ServerFiles::file($now['root'], $jar);

        $versionVariable = $this->startup->find($server, Startup::VERSION_VARIABLES);
        $buildVariable = $this->startup->find($server, Startup::BUILD_VARIABLES);
        $jarVariable = $this->startup->find($server, ['SERVER_JARFILE']);
        $variables = [];
        foreach ([$versionVariable, $buildVariable, $jarVariable] as $variable) {
            if ($variable !== null) {
                $variables[] = ['name' => $variable->env_variable, 'value' => $this->startup->value($variable), 'editable' => $this->startup->editable($variable)];
            }
        }

        $eggSoftware = Software::fromEgg($server->tagSlugs(), (string) ($server->egg?->name ?? ''));
        $mismatches = Mismatches::find(
            $eggSoftware,
            $now,
            array_column($variables, 'value', 'name'),
            $jarEntry !== null,
            $jar,
        );

        // The Java release has to fit the version that runs next, not the one that ran last.
        $target = $now['pending']['version'] ?? $now['version'];
        $images = $this->startup->images($server);
        $recommended = $target === null ? null : JavaImages::recommend($images, $target);
        $currentJava = JavaImages::major($server->image);

        $can = fn (string $permission): bool => $user->can($permission, $server);
        $apply = $can('file.update') && $can('file.create') && $can('startup.update');

        return [
            'state' => $this->state->read($server)->state,
            'jar' => ['name' => $jar, 'exists' => $jarEntry !== null, 'size' => $jarEntry === null ? null : (int) ($jarEntry['size'] ?? 0), 'modified' => $jarEntry['modified'] ?? null],
            'detected' => [
                'software' => $now['software'],
                'label' => Software::label($now['software']),
                'version' => $now['version'],
                'build' => $now['build'],
                'source' => $now['source'],
            ],
            'pending' => $now['pending'] === null ? null : $now['pending'] + ['label' => Software::label($now['pending']['software'])],
            'variables' => $variables,
            'egg' => ['name' => (string) ($server->egg?->name ?? ''), 'software' => $eggSoftware],
            'mismatches' => $mismatches,
            'java' => [
                'image' => $server->image,
                'java' => $currentJava,
                'images' => JavaImages::offered($images),
                'recommended' => $recommended,
                'for_version' => $target,
                'status' => $target === null ? 'unknown' : JavaImages::status($currentJava, $target, $recommended['java'] ?? null),
                'locked' => $this->startup->imageIsLocked($server),
            ],
            'copies' => array_map(fn (array $copy): array => [
                'name' => $copy['name'],
                'created' => Backups::time($copy['stamp']),
                'size' => $copy['size'],
                'software' => $copy['meta']['software'] ?? null,
                'version' => $copy['meta']['version'] ?? null,
            ], array_slice($now['copies'], 0, Backups::KEEP)),
            'software' => array_map(fn (string $id): array => ['id' => $id, 'label' => Software::label($id)], Software::INSTALLABLE),
            'can' => [
                'apply' => $apply,
                'java' => $can('startup.docker-image'),
                'rollback' => $apply,
                'stop' => $can('control.stop'),
                'delete' => $can('file.delete'),
            ],
        ];
    }
}
