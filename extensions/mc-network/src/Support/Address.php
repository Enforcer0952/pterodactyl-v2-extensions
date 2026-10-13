<?php

namespace McNetwork\Support;

/**
 * A backend address as BungeeCord reads it: "host:port", where host is a name, an
 * IPv4 address or an IPv6 address in square brackets.
 */
final class Address
{
    private const string HOST = '/^[A-Za-z0-9_](?:[A-Za-z0-9._-]{0,251}[A-Za-z0-9_])?$/D';

    /**
     * @return array{host: string, port: int}|null null when the text is not a TCP address
     */
    public static function parse(string $address): ?array
    {
        $address = trim($address);
        if ($address === '' || strlen($address) > 260 || preg_match('/[\p{Cc}\p{Zl}\p{Zp}\s]/u', $address) === 1) {
            return null;
        }

        if (str_starts_with($address, '[')) {
            if (preg_match('/^\[([0-9A-Fa-f:.]+)\]:(\d{1,5})$/D', $address, $match) !== 1 || filter_var($match[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                return null;
            }
            $host = '['.strtolower($match[1]).']';
            $port = $match[2];
        } else {
            $position = strrpos($address, ':');
            if ($position === false) {
                return null;
            }
            $host = substr($address, 0, $position);
            $port = substr($address, $position + 1);
            if (preg_match(self::HOST, $host) !== 1 || preg_match('/^\d{1,5}$/D', $port) !== 1) {
                return null;
            }
        }

        $port = (int) $port;
        if ($port < 1 || $port > 65535) {
            return null;
        }

        return ['host' => $host, 'port' => $port];
    }

    public static function format(string $host, int $port): string
    {
        return $host.':'.$port;
    }

    /** A host name for forced hosts: letters, digits, dots, dashes and underscores. */
    public static function isHostName(string $host): bool
    {
        return strlen($host) <= 253 && preg_match(self::HOST, $host) === 1;
    }
}
