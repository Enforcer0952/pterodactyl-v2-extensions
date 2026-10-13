<?php

namespace McPlayers\Support;

use JsonException;

/**
 * The JSON list files a Minecraft server keeps (whitelist, ops, bans, usercache).
 * Pure functions over file contents, so the editing rules are testable without a
 * panel. A file that exists but is not a valid list is never treated as empty:
 * writing to it would wipe every entry the server still has.
 */
final class ListFile
{
    /**
     * The rows of a list file. A missing file is an empty list.
     *
     * @return list<array<string, mixed>>
     *
     * @throws BrokenListFile when the file exists but is not a JSON list
     */
    public static function decode(?string $contents, string $file): array
    {
        if ($contents === null || trim($contents) === '') {
            return [];
        }

        try {
            $decoded = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new BrokenListFile($file, $exception->getMessage());
        }

        if (! is_array($decoded) || ! array_is_list($decoded)) {
            throw new BrokenListFile($file, 'it is not a list');
        }

        return array_values(array_filter($decoded, 'is_array'));
    }

    /**
     * Add or replace an entry. Rows match on $key, and for player entries also on
     * the name, so a player whose UUID changed is not listed twice.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $entry
     * @param  list<string>  $keep  fields of an existing row to keep (e.g. an op's level)
     * @return list<array<string, mixed>>
     */
    public static function upsert(array $rows, array $entry, string $key = 'uuid', array $keep = []): array
    {
        $result = [];
        foreach ($rows as $row) {
            $same = strcasecmp((string) ($row[$key] ?? ''), (string) ($entry[$key] ?? '')) === 0
                || ($key === 'uuid' && strcasecmp((string) ($row['name'] ?? ''), (string) ($entry['name'] ?? '')) === 0);

            if (! $same) {
                $result[] = $row;

                continue;
            }

            foreach ($keep as $field) {
                if (array_key_exists($field, $row)) {
                    $entry[$field] = $row[$field];
                }
            }
        }
        $result[] = $entry;

        return $result;
    }

    /**
     * Remove every row whose $key matches $value, case-insensitively.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>|null null when nothing matched
     */
    public static function remove(array $rows, string $key, string $value): ?array
    {
        $kept = array_values(array_filter($rows, fn (array $row): bool => strcasecmp((string) ($row[$key] ?? ''), $value) !== 0));

        return count($kept) === count($rows) ? null : $kept;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public static function encode(array $rows): string
    {
        return json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    }

    /**
     * Whether $name is already on the list, so removals also work for entries whose
     * names no longer pass today's username rules.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public static function has(array $rows, string $key, string $value): bool
    {
        foreach ($rows as $row) {
            if (strcasecmp((string) ($row[$key] ?? ''), $value) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * The UUID usercache.json has for a name: the one the server itself uses, which
     * is right for offline mode, proxies and Floodgate alike.
     *
     * @param  list<array<string, mixed>>  $usercache
     * @return array{uuid: string, name: string}|null
     */
    public static function cachedProfile(array $usercache, string $name): ?array
    {
        foreach ($usercache as $row) {
            if (strcasecmp((string) ($row['name'] ?? ''), $name) === 0 && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', (string) ($row['uuid'] ?? '')) === 1) {
                return ['uuid' => strtolower((string) $row['uuid']), 'name' => (string) $row['name']];
            }
        }

        return null;
    }
}
