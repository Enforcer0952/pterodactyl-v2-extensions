<?php

namespace ValheimTools\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use ValheimTools\Support\Thunderstore;

/**
 * Thunderstore (https://thunderstore.io/c/valheim/), answers cached for a few minutes.
 * Search uses the listing the website itself uses; package details use the documented
 * experimental API. Thunderstore asks for a User-Agent that says who is calling.
 */
class ThunderstoreClient
{
    private const string BASE = 'https://thunderstore.io';

    private const string AGENT = 'Pterodactyl-Valheim-Tools/1.0 (panel extension by Anton van Zanten)';

    private const int TTL = 600;

    public const array ORDERINGS = ['most-downloaded', 'top-rated', 'newest', 'last-updated'];

    /**
     * @return array{results: list<array<string, mixed>>, total: int, has_more: bool}
     */
    public function search(string $query, string $ordering, int $page): array
    {
        $ordering = in_array($ordering, self::ORDERINGS, true) ? $ordering : 'most-downloaded';
        $query = mb_substr(trim($query), 0, 100);
        $page = max(1, min(50, $page));

        return Cache::remember('ext:valheim-tools:search:'.sha1($ordering.'|'.$page.'|'.$query), self::TTL, function () use ($query, $ordering, $page): array {
            $json = $this->get('/api/cyberstorm/listing/valheim/', [
                'deprecated' => 'False',
                'nsfw' => 'False',
                'ordering' => $ordering,
                'page' => $page,
                'q' => $query,
            ]);

            return Thunderstore::searchResults($json);
        });
    }

    /**
     * One package: the latest version, or a specific one.
     *
     * @return array{namespace: string, name: string, version: string, description: string, dependencies: list<array{namespace: string, name: string, version: string}>, deprecated: bool, url: string}
     */
    public function package(string $namespace, string $name, ?string $version = null): array
    {
        if (! Thunderstore::validName($namespace) || ! Thunderstore::validName($name) || ($version !== null && ! Thunderstore::validVersion($version))) {
            throw new HttpException(422, 'That is not a valid mod name.');
        }

        $path = '/api/experimental/package/'.$namespace.'/'.$name.'/'.($version !== null ? $version.'/' : '');
        $package = Cache::remember('ext:valheim-tools:package:'.sha1($path), self::TTL, function () use ($path): ?array {
            return Thunderstore::package($this->get($path));
        });

        return $package ?? throw new HttpException(502, 'Thunderstore sent an answer this extension does not understand.');
    }

    /**
     * @param  array<string, scalar>  $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query = []): array
    {
        try {
            $response = Http::withHeaders(['User-Agent' => self::AGENT])
                ->acceptJson()
                ->timeout(15)
                ->get(self::BASE.$path, $query);
        } catch (ConnectionException) {
            throw new HttpException(502, 'Could not reach Thunderstore. Try again in a moment.');
        }

        return $this->decode($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        if ($response->status() === 404) {
            throw new HttpException(404, 'Thunderstore has no such mod or version.');
        }
        if (! $response->successful()) {
            throw new HttpException(502, 'Thunderstore answered with an error ('.$response->status().'). Try again in a moment.');
        }
        $json = $response->json();
        if (! is_array($json)) {
            throw new HttpException(502, 'Thunderstore sent an answer this extension does not understand.');
        }

        return $json;
    }
}
