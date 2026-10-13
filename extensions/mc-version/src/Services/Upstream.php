<?php

namespace McVersion\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use McVersion\Support\Software;
use McVersion\Support\Sources;
use McVersion\Support\Versions;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Version lists and download details from PaperMC, Purpur, Fabric and Mojang. The
 * panel asks on the user's behalf and keeps answers for ten minutes; Wings downloads
 * the jar itself.
 */
class Upstream
{
    public const string USER_AGENT = 'pterodactyl-mc-version/1.0.0 (+https://github.com/Enforcer0952/pterodactyl-v2-extensions)';

    private const int TTL = 600;

    /** Most entries sent to the browser; the lists with snapshots are long. */
    private const int MAX_VERSIONS = 400;

    /** Purpur builds tried, newest first, before giving up on a version. */
    private const int PURPUR_TRIES = 5;

    /**
     * @return list<array{id: string, stable: bool}>
     */
    public function versions(string $software, bool $experimental): array
    {
        $all = $this->allVersions($software);
        if (! $experimental) {
            $all = array_values(array_filter($all, fn (array $entry): bool => $entry['stable']));
        }

        return array_map(
            fn (array $entry): array => ['id' => $entry['id'], 'stable' => $entry['stable']],
            array_slice($all, 0, self::MAX_VERSIONS),
        );
    }

    /**
     * What to download for a version of a software.
     *
     * @return array{build: ?string, channel: string, stable: bool, url: string, filename: string, hash: ?array{algo: string, value: string}, size: ?int}
     */
    public function resolve(string $software, string $version, bool $experimental): array
    {
        if (! Versions::isValid($version)) {
            throw new HttpException(422, 'That is not a Minecraft version.');
        }

        $entry = null;
        foreach ($this->allVersions($software) as $candidate) {
            if ($candidate['id'] === $version) {
                $entry = $candidate;
                break;
            }
        }
        if ($entry === null) {
            throw new HttpException(404, Software::label($software).' has no version '.$version.'.');
        }
        if (! $entry['stable'] && ! $experimental) {
            throw new HttpException(422, $version.' is a snapshot or an experimental version. Turn on "Show snapshots and experimental builds" to use it.');
        }

        $build = match ($software) {
            'paper' => $this->paper($version, $experimental),
            'purpur' => $this->purpur($version),
            'fabric' => $this->fabric($version, $entry['stable'], $experimental),
            'vanilla' => $this->vanilla($entry),
            default => throw new HttpException(422, 'Unknown software.'),
        };

        if (! Sources::isAllowedDownload($build['url'])) {
            throw new HttpException(502, 'The download address from '.Software::label($software).' was not accepted.');
        }

        return $build;
    }

    /**
     * @return list<array{id: string, stable: bool, url?: string}>
     */
    private function allVersions(string $software): array
    {
        return match ($software) {
            'paper' => Sources::paperVersions($this->get(Sources::PAPER_FILL, 'paper:project', 'PaperMC')),
            'purpur' => Sources::purpurVersions($this->get(Sources::PURPUR, 'purpur:root', 'Purpur')),
            'fabric' => Sources::fabricVersions($this->get(Sources::FABRIC.'/versions/game', 'fabric:game', 'Fabric')),
            'vanilla' => Sources::mojangVersions($this->get(Sources::MOJANG_MANIFEST, 'mojang:manifest', 'Mojang')),
            default => throw new HttpException(422, 'Unknown software.'),
        };
    }

    /** @return array{build: ?string, channel: string, stable: bool, url: string, filename: string, hash: ?array{algo: string, value: string}, size: ?int} */
    private function paper(string $version, bool $experimental): array
    {
        $builds = $this->get(Sources::PAPER_FILL.'/versions/'.rawurlencode($version).'/builds', 'paper:builds:'.$version, 'PaperMC');
        $build = Sources::paperBuild($builds, $experimental);
        if ($build === null) {
            throw new HttpException(422, 'Paper has no stable build of '.$version.' yet.'.($experimental ? '' : ' Turn on "Show snapshots and experimental builds" to use its newest build.'));
        }

        return $build;
    }

    /** @return array{build: ?string, channel: string, stable: bool, url: string, filename: string, hash: ?array{algo: string, value: string}, size: ?int} */
    private function purpur(string $version): array
    {
        $detail = $this->get(Sources::PURPUR.'/'.rawurlencode($version), 'purpur:version:'.$version, 'Purpur');

        foreach (array_slice(Sources::purpurBuildIds($detail), 0, self::PURPUR_TRIES) as $id) {
            $build = Sources::purpurBuild($this->get(Sources::PURPUR.'/'.rawurlencode($version).'/'.$id, 'purpur:build:'.$version.':'.$id, 'Purpur'), $version);
            if ($build !== null) {
                return $build;
            }
        }

        throw new HttpException(422, 'Purpur has no working build of '.$version.' right now.');
    }

    /** @return array{build: ?string, channel: string, stable: bool, url: string, filename: string, hash: ?array{algo: string, value: string}, size: ?int} */
    private function fabric(string $version, bool $stable, bool $experimental): array
    {
        $loader = Sources::fabricLoader($this->get(Sources::FABRIC.'/versions/loader/'.rawurlencode($version), 'fabric:loader:'.$version, 'Fabric'), $experimental);
        $installer = Sources::fabricInstaller($this->get(Sources::FABRIC.'/versions/installer', 'fabric:installer', 'Fabric'));
        if ($loader === null || $installer === null) {
            throw new HttpException(422, 'Fabric has no stable loader for '.$version.' yet.'.($experimental ? '' : ' Turn on "Show snapshots and experimental builds" to use its newest loader.'));
        }

        return Sources::fabricBuild($version, $loader, $installer, $stable);
    }

    /**
     * @param  array{id: string, stable: bool, url?: string}  $entry
     * @return array{build: ?string, channel: string, stable: bool, url: string, filename: string, hash: ?array{algo: string, value: string}, size: ?int}
     */
    private function vanilla(array $entry): array
    {
        $server = Sources::mojangServer($this->get((string) ($entry['url'] ?? ''), 'mojang:version:'.$entry['id'], 'Mojang'));
        if ($server === null) {
            throw new HttpException(422, 'Mojang has no server download for '.$entry['id'].'.');
        }

        return $server;
    }

    /**
     * @return array<mixed>
     */
    private function get(string $url, string $key, string $name): array
    {
        if (! str_starts_with($url, 'https://')) {
            throw new HttpException(502, 'Refusing to contact an address that is not secure.');
        }

        try {
            $data = Cache::remember('ext:mc-version:upstream:'.$key, self::TTL, fn (): mixed => $this->request()->get($url)->throw()->json());
        } catch (RequestException $exception) {
            $status = $exception->response->status();
            if ($status === 404) {
                throw new HttpException(404, $name.' does not know that version.', $exception);
            }

            throw new HttpException(502, $name.' returned an error ('.$status.'). Try again in a moment.', $exception);
        } catch (ConnectionException $exception) {
            throw new HttpException(504, 'Could not reach '.$name.' from the panel.', $exception);
        }

        if (! is_array($data)) {
            Cache::forget('ext:mc-version:upstream:'.$key);
            throw new HttpException(502, $name.' sent an answer that could not be read.');
        }

        return $data;
    }

    private function request(): PendingRequest
    {
        return Http::withUserAgent(self::USER_AGENT)
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(20)
            ->retry(2, 250, fn (\Throwable $e): bool => $e instanceof ConnectionException
                || ($e instanceof RequestException && $e->response->serverError()), throw: false);
    }
}
