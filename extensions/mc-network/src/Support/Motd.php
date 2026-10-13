<?php

namespace McNetwork\Support;

/**
 * Colour and format codes in a server description.
 */
final class Motd
{
    /**
     * The text without colour and format codes: the section sign or an ampersand
     * followed by a code character, "&x&r&r&g&g&b&b" hex colours and "&#rrggbb".
     */
    public static function plain(string $motd): string
    {
        $text = preg_replace('/[§&]#[0-9a-fA-F]{6}/u', '', $motd) ?? $motd;
        $text = preg_replace('/[§&][0-9a-fk-orxA-FK-ORX]/u', '', $text) ?? $text;
        // A section sign that is left over has no meaning outside a code.
        $text = str_replace('§', '', $text);
        $text = preg_replace('/[\p{Cc}\p{Zl}\p{Zp}\s]+/u', ' ', $text) ?? $text;

        return trim($text);
    }
}
