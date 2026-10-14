<?php

namespace McCrash\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use McCrash\Support\Times;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;

/**
 * "A crash since you last looked": counts crash reports and JVM crashes newer than the moment the user
 * opened the Crashes page. Only file listings are used, so it is cheap enough for the console page.
 */
class Badge
{
    /** A user who never opened the page only hears about crashes of the last days, not of last year. */
    private const int FIRST_LOOK_DAYS = 3;

    private const int LISTING_SECONDS = 20;

    public function __construct(private readonly Files $files) {}

    /**
     * @return array{new: int, latest: ?string, viewed_at: ?string}
     */
    public function status(Server $server, User $user): array
    {
        $viewed = $this->viewedAt($server, $user);
        $since = $viewed ?? CarbonImmutable::now()->subDays(self::FIRST_LOOK_DAYS);

        $new = 0;
        $latest = null;
        foreach ($this->crashes($server) as $crash) {
            if ($latest === null || strcmp($crash['time'], $latest) > 0) {
                $latest = $crash['time'];
            }
            if ($crash['at'] > $since->getTimestamp()) {
                $new++;
            }
        }

        return ['new' => min($new, 99), 'latest' => $latest, 'viewed_at' => $viewed?->toIso8601String()];
    }

    public function markViewed(Server $server, User $user): void
    {
        $now = now();
        DB::table('ext_mc_crash_views')->upsert(
            [['server_id' => $server->id, 'user_id' => $user->id, 'viewed_at' => $now, 'created_at' => $now, 'updated_at' => $now]],
            ['server_id', 'user_id'],
            ['viewed_at', 'updated_at'],
        );
    }

    private function viewedAt(Server $server, User $user): ?CarbonImmutable
    {
        $value = DB::table('ext_mc_crash_views')->where('server_id', $server->id)->where('user_id', $user->id)->value('viewed_at');

        return $value === null ? null : CarbonImmutable::parse($value);
    }

    /**
     * Crash files with the moment they were written. Shared by everyone who looks at the server for a moment.
     *
     * @return list<array{time: string, at: int}>
     */
    private function crashes(Server $server): array
    {
        return Cache::remember('ext:mc-crash:badge:'.$server->uuid, self::LISTING_SECONDS, function () use ($server): array {
            $found = [];

            foreach ($this->files->root($server) as $entry) {
                $name = (string) ($entry['name'] ?? '');
                if (($entry['file'] ?? true) !== false && preg_match('/^hs_err_pid\d{1,10}\.log$/D', $name) === 1) {
                    $found[] = $this->moment($entry);
                }
            }
            foreach ($this->files->folder($server, 'crash-reports') as $entry) {
                if (($entry['file'] ?? true) !== false && str_ends_with((string) ($entry['name'] ?? ''), '.txt')) {
                    $found[] = $this->moment($entry);
                }
            }

            return array_values(array_filter($found));
        });
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array{time: string, at: int}|null
     */
    private function moment(array $entry): ?array
    {
        $modified = $entry['modified'] ?? null;
        $time = Times::fromListing($modified);
        $at = is_string($modified) ? strtotime($modified) : false;

        return $time === null || $at === false ? null : ['time' => $time, 'at' => $at];
    }
}
