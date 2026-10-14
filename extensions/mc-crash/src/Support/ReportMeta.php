<?php

namespace McCrash\Support;

/** The facts a crash report or hs_err file states about itself. */
final class ReportMeta
{
    /**
     * @return array{kind: string, time: ?string, description: ?string, minecraft: ?string, java: ?string, suspected: list<string>}
     */
    public static function parse(string $text): array
    {
        $kind = 'log';
        if (str_contains($text, '---- Minecraft Crash Report ----') || preg_match('/^Description: /m', $text) === 1 && str_contains($text, '-- System Details --')) {
            $kind = 'crash';
        } elseif (str_contains($text, 'A fatal error has been detected by the Java Runtime Environment') || preg_match('/^# JRE version:/m', $text) === 1) {
            $kind = 'jvm';
        }

        $java = null;
        if (preg_match('/^\s*Java Version: ([^\n]+)/m', $text, $m) === 1) {
            $java = trim($m[1]);
        } elseif (preg_match('/^# JRE version: ([^\n]+)/m', $text, $m) === 1) {
            $java = trim($m[1]);
        }

        return [
            'kind' => $kind,
            'time' => Times::fromReport($text),
            'description' => preg_match('/^Description: ([^\n]+)/m', $text, $m) === 1 ? Text::oneLine($m[1], 200) : null,
            'minecraft' => preg_match('/^\s*Minecraft Version: (\S+)/m', $text, $m) === 1 ? $m[1] : null,
            'java' => $java !== null ? Text::oneLine($java, 120) : null,
            'suspected' => self::suspected($text),
        ];
    }

    /**
     * Forge and NeoForge list "Suspected Mods" in crash reports: a guess of the loader, not a fact.
     *
     * @return list<string> mod ids
     */
    private static function suspected(string $text): array
    {
        if (preg_match('/^[ \t]*Suspected Mods:[ \t]*([^\n]*)\n((?:[ \t]+[^\n]*\n?)*)/m', $text, $m) !== 1) {
            return [];
        }

        $block = $m[1]."\n".$m[2];
        if (preg_match_all('/\(([A-Za-z0-9_\-.]+)\)/', $block, $ids) < 1) {
            return [];
        }

        return array_slice(array_values(array_unique($ids[1])), 0, 5);
    }
}
