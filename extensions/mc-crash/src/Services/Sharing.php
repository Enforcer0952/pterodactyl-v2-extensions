<?php

namespace McCrash\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Uploads text to mclo.gs, which keeps it under a public link. */
class Sharing
{
    private const string ENDPOINT = 'https://api.mclo.gs/1/log';

    /** mclo.gs accepts 10 MiB and 25,000 lines. */
    public const int MAX_BYTES = 5 * 1024 * 1024;

    public const int MAX_LINES = 25000;

    /**
     * @return string the public link
     */
    public function upload(string $text): string
    {
        if (trim($text) === '') {
            throw new HttpException(422, 'There is nothing to share.');
        }

        try {
            $response = Http::asForm()
                ->withUserAgent('pterodactyl-mc-crash/1.0 (Pterodactyl Panel extension)')
                ->connectTimeout(5)
                ->timeout(20)
                ->post(self::ENDPOINT, ['content' => $text]);
        } catch (ConnectionException) {
            throw new HttpException(502, 'Could not reach mclo.gs.');
        }

        $data = $response->json();
        $url = is_array($data) ? ($data['url'] ?? null) : null;

        if (! $response->successful() || ! is_array($data) || ($data['success'] ?? false) !== true || ! is_string($url) || preg_match('~^https://mclo\.gs/[A-Za-z0-9]+$~D', $url) !== 1) {
            $error = is_array($data) && is_string($data['error'] ?? null) ? mb_substr($data['error'], 0, 200) : 'mclo.gs did not accept the upload.';

            throw new HttpException(502, $error);
        }

        return $url;
    }
}
