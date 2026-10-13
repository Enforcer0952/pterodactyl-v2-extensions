<?php

namespace McPlayers\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use McPlayers\Support\McBansRecord;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Global ban history from MCBans (www.mcbans.com), read from its public website
 * API. Only account UUIDs are looked up: an offline-mode name says nothing about
 * who is behind it.
 */
class McBans
{
    private const string BASE = 'https://www.mcbans.com/api';

    private const int CACHE_SECONDS = 1800;

    /**
     * @return array<string, mixed>
     */
    public function lookup(string $uuid): array
    {
        $uuid = strtolower(str_replace('-', '', $uuid));
        if (preg_match('/^[0-9a-f]{12}4[0-9a-f]{3}[89ab][0-9a-f]{15}$/', $uuid) !== 1) {
            throw new HttpException(422, 'MCBans only knows Mojang accounts; this is not an account UUID.');
        }

        return Cache::remember('ext:mc-players:mcbans:'.$uuid, self::CACHE_SECONDS, function () use ($uuid): array {
            try {
                $player = $this->request()->get('/players/'.$uuid);
                if ($player->status() === 404) {
                    return McBansRecord::from($uuid, null, null);
                }
                if (! $player->successful() || ! is_array($player->json())) {
                    throw new HttpException(502, 'MCBans did not answer properly. Try again later.');
                }

                $bans = null;
                if (($player->json('bansTotal') ?? 0) > 0) {
                    $response = $this->request()->get('/players/'.$uuid.'/bans', ['page' => 0]);
                    $bans = $response->successful() && is_array($response->json()) ? $response->json() : null;
                }

                return McBansRecord::from($uuid, $player->json(), $bans);
            } catch (ConnectionException) {
                throw new HttpException(504, 'Could not reach MCBans.');
            }
        });
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(self::BASE)
            ->acceptJson()
            ->timeout(6)
            ->connectTimeout(4)
            ->withUserAgent('Enforcer0952/pterodactyl-v2-extensions mc-players (Pterodactyl panel)');
    }
}
