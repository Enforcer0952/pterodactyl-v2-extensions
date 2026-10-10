<?php

namespace McPlayers\Support;

/**
 * Input rules shared by the controller. Everything that ends up in a console
 * command is checked here, so a name or reason can never smuggle in a second
 * command or a selector.
 */
final class Validates
{
    /** Java names are 3-16 of [A-Za-z0-9_]; Floodgate prefixes Bedrock players with a dot. */
    public const string NAME = '/^\.?[A-Za-z0-9_]{1,16}$/';

    public static function reason(?string $reason): ?string
    {
        if ($reason === null) {
            return null;
        }

        $clean = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $reason) ?? '');

        return $clean === '' ? null : mb_substr($clean, 0, 120);
    }

    /** The UUID a server in offline mode gives a player: v3 of "OfflinePlayer:<name>". */
    public static function offlineUuid(string $name): string
    {
        $hash = md5('OfflinePlayer:'.$name, true);
        $hash[6] = chr((ord($hash[6]) & 0x0F) | 0x30);
        $hash[8] = chr((ord($hash[8]) & 0x3F) | 0x80);

        return self::dashed(bin2hex($hash));
    }

    public static function dashed(string $hex): string
    {
        $hex = strtolower(str_replace('-', '', $hex));

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);
    }
}
