<?php

namespace McPlayers\Support;

/**
 * Input rules shared by the controller. Everything that ends up in a console
 * command is checked here, so a name or reason can never smuggle in a second
 * command or a selector.
 */
final class Validates
{
    /**
     * Java names are [A-Za-z0-9_], up to 16 long (a few very old accounts are shorter
     * than 3); Floodgate prefixes Bedrock players with a dot. "D" stops "$" from
     * accepting a trailing newline.
     */
    public const string NAME = '/^\.?[A-Za-z0-9_]{1,16}$/D';

    public static function reason(?string $reason): ?string
    {
        if ($reason === null) {
            return null;
        }

        if (! mb_check_encoding($reason, 'UTF-8')) {
            throw new \InvalidArgumentException('The reason contains invalid characters.');
        }

        // Control characters, including C1 and Unicode line breaks, could end the command early.
        $clean = trim(preg_replace('/[\p{Cc}\p{Zl}\p{Zp}]+/u', ' ', $reason) ?? '');

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
