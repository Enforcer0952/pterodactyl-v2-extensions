<?php

namespace ValheimTools\Support;

/**
 * Validation for the startup variables the Valheim eggs use. The eggs put them inside
 * double quotes in a shell command (-name "{{SERVER_NAME}}"), so the characters that
 * a shell reads inside double quotes never pass.
 *
 * Rules from Iron Gate's server guide: the password needs at least 5 characters and
 * must not be part of the world name.
 */
final class ServerSettings
{
    /** Variables in the order the page shows them. */
    public const array FIELDS = ['SERVER_NAME', 'PASSWORD', 'PUBLIC_SERVER', 'ENABLE_CROSSPLAY', 'PRESET'];

    /** Names an egg may use for a world preset, first match wins. */
    public const array PRESET_VARIABLES = ['PRESET', 'WORLD_PRESET', 'SERVER_PRESET'];

    /** -preset values from the guide. */
    public const array PRESETS = ['normal', 'casual', 'easy', 'hard', 'hardcore', 'immersive', 'hammer'];

    public const int NAME_MAX = 60;

    public const int PASSWORD_MIN = 5;

    public const int PASSWORD_MAX = 20;

    /** Characters a shell reads inside double quotes, plus braces (the panel's own placeholders). */
    private const string UNSAFE = '/["$`\\\\{}\p{Cc}\p{Zl}\p{Zp}]/u';

    public static function safeText(string $value): bool
    {
        return mb_check_encoding($value, 'UTF-8') && preg_match(self::UNSAFE, $value) !== 1;
    }

    /** @return string|null an error message, null when fine */
    public static function validateName(string $value): ?string
    {
        $length = mb_strlen($value);
        if ($length < 1 || $length > self::NAME_MAX) {
            return 'Use 1 to '.self::NAME_MAX.' characters for the server name.';
        }
        if (trim($value) !== $value) {
            return 'The server name cannot start or end with a space.';
        }
        if (! self::safeText($value)) {
            return 'The server name cannot contain quotes, backslashes, dollar signs, backticks or curly braces.';
        }

        return null;
    }

    /** @return string|null an error message, null when fine */
    public static function validatePassword(string $value, string $world): ?string
    {
        $length = mb_strlen($value);
        if ($length < self::PASSWORD_MIN || $length > self::PASSWORD_MAX) {
            return 'Use '.self::PASSWORD_MIN.' to '.self::PASSWORD_MAX.' characters for the password.';
        }
        if (preg_match('/\s/u', $value) === 1) {
            return 'The password cannot contain spaces.';
        }
        if (! self::safeText($value)) {
            return 'The password cannot contain quotes, backslashes, dollar signs, backticks or curly braces.';
        }
        if ($world !== '' && mb_stripos($world, $value) !== false) {
            return 'Valheim refuses a password that is part of the world name. Choose another password.';
        }

        return null;
    }

    /** A warning (not an error) when the password sits inside the server name. */
    public static function nameWarning(string $name, string $password): ?string
    {
        if ($password !== '' && mb_stripos($name, $password) !== false) {
            return 'The password is part of the server name, so everyone who sees the server can read it.';
        }

        return null;
    }

    /** "0" or "1", from a boolean or a 0/1/true/false value. Null when it is neither. */
    public static function flag(mixed $value): ?string
    {
        return match (true) {
            $value === true, $value === 1, $value === '1', $value === 'true' => '1',
            $value === false, $value === 0, $value === '0', $value === 'false' => '0',
            default => null,
        };
    }

    public static function validatePreset(string $value): ?string
    {
        return in_array(strtolower($value), ['', ...self::PRESETS], true) ? null : 'Choose one of: '.implode(', ', self::PRESETS).'.';
    }
}
