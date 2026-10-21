<?php

namespace McNetwork\Support;

/**
 * Checks what the editor sends before it goes into config.yml. The YAML writer
 * quotes everything it writes, so these checks are about BungeeCord accepting the
 * result, not about breaking out of the file.
 */
final class Validate
{
    public const int MAX_SERVERS = 200;

    public const int MAX_FORCED_HOSTS = 200;

    private const string CONTROL = '/[\p{Cc}\p{Zl}\p{Zp}]/u';

    public static function serverName(string $name): bool
    {
        // A name of digits only would become a number key in the YAML and in PHP arrays.
        return preg_match('/^[A-Za-z0-9_-]{1,32}$/D', $name) === 1 && preg_match('/\D/', $name) === 1;
    }

    /**
     * Every problem with a draft, as readable messages. Names and addresses that are
     * unchanged from the current file are accepted as they are, so an old entry that
     * does not follow today's rules never blocks an unrelated edit.
     *
     * @param  array{servers: list<array<string, mixed>>, priorities: list<mixed>, forced_hosts: list<array<string, mixed>>}  $draft
     * @param  list<array{name: string, address: string, motd: string, restricted: bool}>  $current
     * @return list<string>
     */
    public static function draft(array $draft, array $current): array
    {
        $errors = [];
        $known = [];
        foreach ($current as $server) {
            $known[$server['name']] = $server;
        }

        if (count($draft['servers']) > self::MAX_SERVERS) {
            $errors[] = 'A network can have at most '.self::MAX_SERVERS.' servers.';
        }

        $names = [];
        foreach ($draft['servers'] as $server) {
            $name = (string) ($server['name'] ?? '');
            $label = $name === '' ? 'A server' : '"'.self::shown($name).'"';
            $was = isset($server['was']) && is_string($server['was']) ? $server['was'] : null;
            $original = $was !== null ? ($known[$was] ?? null) : null;

            if (isset($names[$name])) {
                $errors[] = $label.' is listed twice.';
            }
            $names[$name] = true;

            $unchangedName = $original !== null && $was === $name && ! preg_match(self::CONTROL, $name) && strlen($name) <= 64 && $name !== '';
            if (! self::serverName($name) && ! $unchangedName) {
                $errors[] = $label.' is not a valid name. Use letters, numbers, dashes and underscores, up to 32 characters, with at least one that is not a digit.';
            }

            $address = (string) ($server['address'] ?? '');
            if (Address::parse($address) === null && ! ($original !== null && $original['address'] === $address && ! preg_match(self::CONTROL, $address))) {
                $errors[] = $label.' needs an address like localhost:25566, with a port from 1 to 65535.';
            }

            $motd = (string) ($server['motd'] ?? '');
            if (preg_match(self::CONTROL, $motd) === 1) {
                $errors[] = $label.' has a description with control characters.';
            } elseif (mb_strlen($motd) > 256) {
                $errors[] = $label.' has a description longer than 256 characters.';
            }
        }

        $priorities = [];
        foreach ($draft['priorities'] as $name) {
            if (! is_string($name) || preg_match(self::CONTROL, $name) === 1 || $name === '' || strlen($name) > 64) {
                $errors[] = 'The priority list has a name that is not valid.';

                continue;
            }
            if (isset($priorities[$name])) {
                $errors[] = '"'.self::shown($name).'" is in the priority list twice.';
            }
            $priorities[$name] = true;
        }

        if (count($draft['forced_hosts']) > self::MAX_FORCED_HOSTS) {
            $errors[] = 'There can be at most '.self::MAX_FORCED_HOSTS.' forced hosts.';
        }
        $hosts = [];
        foreach ($draft['forced_hosts'] as $forced) {
            $host = (string) ($forced['host'] ?? '');
            $server = (string) ($forced['server'] ?? '');
            if (! Address::isHostName($host)) {
                $errors[] = '"'.self::shown($host).'" is not a valid host name.';
            } elseif (isset($hosts[strtolower($host)])) {
                $errors[] = 'The host "'.self::shown($host).'" is listed twice.';
            }
            $hosts[strtolower($host)] = true;
            if ($server === '' || strlen($server) > 64 || preg_match(self::CONTROL, $server) === 1) {
                $errors[] = 'The forced host "'.self::shown($host).'" needs a server.';
            }
        }

        return array_values(array_unique($errors));
    }

    /** A value for an error message: no control characters, not too long. */
    private static function shown(string $value): string
    {
        $value = preg_replace(self::CONTROL, '', $value) ?? '';

        return mb_strlen($value) > 40 ? mb_substr($value, 0, 40).'...' : $value;
    }
}
