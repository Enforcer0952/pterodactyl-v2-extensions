<?php

namespace McVersion\Support;

/**
 * Naming and rotation of the jar copies kept next to the server jar:
 * server.jar.bak-20261013153000, with server.jar.bak-20261013153000.json holding
 * what was in place before (software, version, startup variables, Java image).
 */
final class Backups
{
    /** How many copies are kept. */
    public const int KEEP = 2;

    public static function name(string $jar, string $stamp): string
    {
        return $jar.'.bak-'.$stamp;
    }

    public static function metaName(string $backup): string
    {
        return $backup.'.json';
    }

    /** The 14 digit stamp of a copy of this jar, or null for any other name. */
    public static function stamp(string $jar, string $name): ?string
    {
        $prefix = $jar.'.bak-';

        return str_starts_with($name, $prefix) && preg_match('/^\d{14}$/D', substr($name, strlen($prefix))) === 1
            ? substr($name, strlen($prefix))
            : null;
    }

    /**
     * Copies of the jar among a folder's file names, newest first.
     *
     * @param  list<string>  $names
     * @return list<array{name: string, stamp: string}>
     */
    public static function find(string $jar, array $names): array
    {
        $found = [];
        foreach ($names as $name) {
            $stamp = self::stamp($jar, $name);
            if ($stamp !== null) {
                $found[] = ['name' => $name, 'stamp' => $stamp];
            }
        }
        usort($found, fn (array $a, array $b): int => strcmp($b['stamp'], $a['stamp']));

        return $found;
    }

    /**
     * A stamp for "now" that no existing copy uses, moving forward by seconds if needed.
     *
     * @param  list<string>  $names
     */
    public static function freshStamp(string $jar, array $names, int $now): string
    {
        $taken = array_column(self::find($jar, $names), 'stamp');
        $stamp = gmdate('YmdHis', $now);
        while (in_array($stamp, $taken, true)) {
            $stamp = gmdate('YmdHis', ++$now);
        }

        return $stamp;
    }

    /**
     * File names to delete so only the newest copies remain: the older copies and their
     * .json files, and .json files whose copy is gone.
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    public static function prune(string $jar, array $names, int $keep = self::KEEP): array
    {
        $copies = self::find($jar, $names);
        $kept = array_column(array_slice($copies, 0, $keep), 'name');
        $delete = [];

        foreach (array_slice($copies, $keep) as $copy) {
            $delete[] = $copy['name'];
        }

        foreach ($names as $name) {
            if (str_ends_with($name, '.json') && self::stamp($jar, substr($name, 0, -5)) !== null && ! in_array(substr($name, 0, -5), $kept, true)) {
                $delete[] = $name;
            }
        }

        return array_values(array_unique($delete));
    }

    /** UTC time of a stamp as an ISO 8601 string. */
    public static function time(string $stamp): ?string
    {
        $date = \DateTimeImmutable::createFromFormat('YmdHis', $stamp, new \DateTimeZone('UTC'));

        return $date === false ? null : $date->format('c');
    }

    /**
     * The text of a copy's .json file: what was in place before the switch (software,
     * version, the startup variables and Java image the switch changed) and what the
     * switch put in place.
     *
     * @param  array{software: ?string, version: ?string, variables: array<string, string>, image: ?string}  $before
     * @param  array{software: ?string, version: ?string, build: ?string}  $after
     */
    public static function writeMeta(array $before, array $after, int $at): string
    {
        return json_encode([
            'format' => 1,
            'software' => $before['software'],
            'version' => $before['version'],
            'variables' => (object) $before['variables'],
            'image' => $before['image'],
            'to' => $after,
            'at' => $at,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    }

    /**
     * The saved description, tolerant of anything a user may have done to the file.
     *
     * @return array{software: ?string, version: ?string, variables: array<string, string>, image: ?string, to: array{software: ?string, version: ?string, build: ?string}, at: ?int}
     */
    public static function readMeta(?string $json): array
    {
        $data = $json === null ? null : json_decode($json, true);
        $data = is_array($data) ? $data : [];

        $variables = [];
        foreach (is_array($data['variables'] ?? null) ? $data['variables'] : [] as $key => $value) {
            if (is_string($key) && preg_match('/^[A-Z][A-Z0-9_]{0,63}$/D', $key) === 1 && is_string($value) && strlen($value) <= 100) {
                $variables[$key] = $value;
            }
        }

        $software = fn (mixed $value): ?string => is_string($value) && preg_match('/^[a-z]{2,20}$/D', $value) === 1 ? $value : null;
        $version = fn (mixed $value): ?string => is_string($value) && Versions::isValid($value) ? $value : null;
        $to = is_array($data['to'] ?? null) ? $data['to'] : [];

        return [
            'software' => $software($data['software'] ?? null),
            'version' => $version($data['version'] ?? null),
            'variables' => $variables,
            'image' => is_string($data['image'] ?? null) && strlen($data['image']) <= 255 ? $data['image'] : null,
            'to' => [
                'software' => $software($to['software'] ?? null),
                'version' => $version($to['version'] ?? null),
                'build' => is_string($to['build'] ?? null) && preg_match('/^[0-9A-Za-z.+\-]{1,40}$/D', $to['build']) === 1 ? $to['build'] : null,
            ],
            'at' => is_int($data['at'] ?? null) && $data['at'] > 0 ? $data['at'] : null,
        ];
    }
}
