<?php

namespace ArkTools\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Workshop item details from Steam's public GetPublishedFileDetails endpoint,
 * which needs no API key. Details are cached for ten minutes per item.
 */
class SteamWorkshop
{
    private const string URL = 'https://api.steampowered.com/ISteamRemoteStorage/GetPublishedFileDetails/v1/';

    private const int TTL = 600;

    /**
     * @param  list<string>  $ids
     * @return array<string, array{id: string, found: bool, title: ?string, preview_url: ?string, updated_at: ?int, size: ?int, app_id: ?int, url: string}>
     */
    public function details(array $ids): array
    {
        $result = [];
        $missing = [];

        foreach ($ids as $id) {
            $cached = Cache::get($this->key($id));
            if (is_array($cached)) {
                $result[$id] = $cached;
            } else {
                $missing[] = $id;
            }
        }

        foreach (array_chunk($missing, 50) as $chunk) {
            $form = ['itemcount' => count($chunk)];
            foreach ($chunk as $index => $id) {
                $form["publishedfileids[$index]"] = $id;
            }

            try {
                $response = Http::asForm()->connectTimeout(5)->timeout(10)->post(self::URL, $form);
                $items = $response->successful() ? (array) $response->json('response.publishedfiledetails', []) : [];
            } catch (\Throwable) {
                $items = [];
            }

            foreach ($items as $item) {
                if (! is_array($item) || ! isset($item['publishedfileid'])) {
                    continue;
                }
                $id = (string) $item['publishedfileid'];
                $found = (int) ($item['result'] ?? 0) === 1;
                $details = [
                    'id' => $id,
                    'found' => $found,
                    'title' => $found ? (string) ($item['title'] ?? $id) : null,
                    'preview_url' => $found && is_string($item['preview_url'] ?? null) ? $item['preview_url'] : null,
                    'updated_at' => $found ? (int) ($item['time_updated'] ?? 0) : null,
                    'size' => $found ? (int) ($item['file_size'] ?? 0) : null,
                    'app_id' => $found ? (int) ($item['consumer_app_id'] ?? 0) : null,
                    'url' => 'https://steamcommunity.com/sharedfiles/filedetails/?id='.$id,
                ];
                Cache::put($this->key($id), $details, self::TTL);
                $result[$id] = $details;
            }
        }

        // Anything Steam did not answer for (network error) is reported as unknown, uncached.
        foreach ($ids as $id) {
            $result[$id] ??= [
                'id' => $id, 'found' => false, 'title' => null, 'preview_url' => null,
                'updated_at' => null, 'size' => null, 'app_id' => null,
                'url' => 'https://steamcommunity.com/sharedfiles/filedetails/?id='.$id,
            ];
        }

        return $result;
    }

    public function forget(string $id): void
    {
        Cache::forget($this->key($id));
    }

    private function key(string $id): string
    {
        return 'ext:ark-tools:workshop:'.$id;
    }
}
