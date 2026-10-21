<?php

namespace McPlayerData\Support;

/**
 * Who a player is: a name or a UUID from the address, matched against the
 * server's usercache.json (everyone who has joined, with the UUID the server saw).
 */
final class Players
{
    /** Java names are [A-Za-z0-9_] up to 16 long; Floodgate puts a dot in front of Bedrock names. */
    public const string NAME = '/^\.?[A-Za-z0-9_]{1,16}$/D';

    private const string UUID = '/^[0-9a-f]{8}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{12}$/Di';

    /**
     * A name or a UUID, or null when it is neither. Nothing else gets as far as a file path.
     *
     * @return array{uuid: string}|array{name: string}|null
     */
    public static function parse(string $input): ?array
    {
        $input = trim($input);
        if (preg_match(self::UUID, $input) === 1) {
            return ['uuid' => self::dashed($input)];
        }

        return preg_match(self::NAME, $input) === 1 ? ['name' => $input] : null;
    }

    public static function dashed(string $hex): string
    {
        $hex = strtolower(str_replace('-', '', $hex));

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);
    }

    /** The UUID a server in offline mode gives a player: version 3 of "OfflinePlayer:<name>". */
    public static function offlineUuid(string $name): string
    {
        $hash = md5('OfflinePlayer:'.$name, true);
        $hash[6] = chr((ord($hash[6]) & 0x0F) | 0x30);
        $hash[8] = chr((ord($hash[8]) & 0x3F) | 0x80);

        return self::dashed(bin2hex($hash));
    }

    /**
     * usercache.json as a list of players, most recently seen first. Names that
     * cannot be real are dropped. A name that was used by two accounts shows up
     * once per account.
     *
     * @return list<array{name: string, uuid: string, last_seen: ?string}>
     */
    public static function known(?string $json): array
    {
        $rows = $json === null ? null : json_decode($json, true);
        if (! is_array($rows)) {
            return [];
        }

        $players = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row['name'] ?? null) || ! is_string($row['uuid'] ?? null)) {
                continue;
            }
            if (preg_match(self::NAME, $row['name']) !== 1 || preg_match(self::UUID, $row['uuid']) !== 1) {
                continue;
            }

            $uuid = self::dashed($row['uuid']);
            $players[$uuid] = [
                'name' => $row['name'],
                'uuid' => $uuid,
                // The server writes expiresOn as the last time it saw them plus one month.
                'last_seen' => self::lastSeen($row['expiresOn'] ?? null),
            ];
        }

        $players = array_values($players);
        usort($players, fn (array $a, array $b): int => strcmp((string) $b['last_seen'], (string) $a['last_seen']) ?: strcasecmp($a['name'], $b['name']));

        return $players;
    }

    /**
     * Find a player in the usercache by name (any case) or UUID.
     *
     * @param  list<array{name: string, uuid: string, last_seen: ?string}>  $known
     * @param  array{uuid: string}|array{name: string}  $ref
     * @return array{name: string, uuid: string, last_seen: ?string}|null
     */
    public static function find(array $known, array $ref): ?array
    {
        foreach ($known as $player) {
            if (isset($ref['uuid']) ? $player['uuid'] === $ref['uuid'] : strcasecmp($player['name'], $ref['name']) === 0) {
                return $player;
            }
        }

        return null;
    }

    private static function lastSeen(mixed $expires): ?string
    {
        if (! is_string($expires)) {
            return null;
        }

        try {
            return (new \DateTimeImmutable($expires))->modify('-1 month')->format(\DATE_ATOM);
        } catch (\Throwable) {
            return null;
        }
    }
}
