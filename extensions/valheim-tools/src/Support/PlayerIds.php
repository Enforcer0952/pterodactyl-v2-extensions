<?php

namespace ValheimTools\Support;

/**
 * Player IDs as Valheim's access lists use them.
 *
 * The official guide says an ID is "[Platform]_[User ID]" and case sensitive. Since
 * Valheim 1.0 Steam players are written "V_<SteamID64>"; hotfix 1.0.12 made the old
 * "Steam_<SteamID64>" work again, and a plain SteamID64 has always been accepted
 * for Steam players. All three mean the same person here.
 */
final class PlayerIds
{
    /** A SteamID64 of an individual account. */
    public const string STEAM64 = '/^7656119\d{10}$/D';

    /** Platform-prefixed IDs: letters, an underscore, then letters and digits. */
    private const string PREFIXED = '/^[A-Za-z]{1,16}_[A-Za-z0-9]{3,64}$/D';

    public const array STYLES = ['v', 'steam', 'plain'];

    public static function isSteam64(string $id): bool
    {
        return preg_match(self::STEAM64, $id) === 1;
    }

    /** Whether Valheim would take this as an ID: a SteamID64 or a platform-prefixed ID. */
    public static function isValid(string $id): bool
    {
        return self::isSteam64($id) || preg_match(self::PREFIXED, $id) === 1;
    }

    /** The SteamID64 inside "V_...", "Steam_..." or a plain id, else null. */
    public static function steamId(string $id): ?string
    {
        if (self::isSteam64($id)) {
            return $id;
        }
        if (preg_match('/^(?:V|Steam)_(7656119\d{10})$/D', $id, $match) === 1) {
            return $match[1];
        }

        return null;
    }

    /** The same person gets the same key in every spelling. */
    public static function key(string $id): string
    {
        return self::steamId($id) ?? $id;
    }

    /** Write a Steam player in the configured style: v (V_), steam (Steam_) or plain. */
    public static function format(string $steamId, string $style): string
    {
        return match ($style) {
            'steam' => 'Steam_'.$steamId,
            'plain' => $steamId,
            default => 'V_'.$steamId,
        };
    }

    /**
     * Turn what a person typed into an ID for the lists: a profile link or any spelling of a
     * Steam ID becomes the configured style; other platform IDs are kept as typed.
     */
    public static function fromInput(string $input, string $style): ?string
    {
        $text = trim($input);
        if ($text === '') {
            return null;
        }
        if (preg_match('#steamcommunity\.com/profiles/(7656119\d{10})#i', $text, $match) === 1) {
            return self::format($match[1], $style);
        }
        $steam = self::steamId($text);
        if ($steam !== null) {
            return self::format($steam, $style);
        }

        return self::isValid($text) ? $text : null;
    }
}
