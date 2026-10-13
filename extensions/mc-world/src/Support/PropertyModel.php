<?php

namespace McWorld\Support;

/**
 * The server.properties keys the form edits: their group, type, range and options. The
 * same list is sent to the page, so the form and the checks here never disagree. Keys that
 * are not listed can still be edited as plain text in the "All properties" table.
 */
final class PropertyModel
{
    public const int MAX_VALUE = 2000;

    public const string KEY = '/^[A-Za-z0-9._-]{1,100}$/D';

    private const string LEVEL_TYPE = '/^[A-Za-z0-9_:.\/-]{1,100}$/D';

    /** @return list<array{id: string, label: string}> */
    public static function groups(): array
    {
        return [
            ['id' => 'general', 'label' => 'General'],
            ['id' => 'world', 'label' => 'World'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function settings(): array
    {
        $bool = fn (string $key, string $group, string $label, string $help, bool $default): array => [
            'key' => $key, 'group' => $group, 'type' => 'bool', 'label' => $label, 'help' => $help, 'default' => $default ? 'true' : 'false',
        ];

        return [
            ['key' => 'motd', 'group' => 'general', 'type' => 'text', 'label' => 'Message of the day', 'help' => 'Shown in the server list. Use \n for a second line and § for colour codes.', 'default' => 'A Minecraft Server'],
            ['key' => 'max-players', 'group' => 'general', 'type' => 'int', 'label' => 'Max players', 'help' => 'How many players can be online at once.', 'default' => '20', 'min' => 0, 'max' => 2147483647],
            ['key' => 'difficulty', 'group' => 'general', 'type' => 'select', 'label' => 'Difficulty', 'help' => '', 'default' => 'easy', 'options' => self::options(['peaceful', 'easy', 'normal', 'hard'])],
            ['key' => 'gamemode', 'group' => 'general', 'type' => 'select', 'label' => 'Default game mode', 'help' => 'For players who join for the first time.', 'default' => 'survival', 'options' => self::options(['survival', 'creative', 'adventure', 'spectator'])],
            $bool('hardcore', 'general', 'Hardcore', 'Players who die can only spectate. The difficulty is locked to hard.', false),
            $bool('pvp', 'general', 'PvP', 'Players can hurt each other.', true),
            $bool('white-list', 'general', 'Whitelist', 'Only whitelisted players can join.', false),
            $bool('enforce-whitelist', 'general', 'Enforce whitelist', 'Kick players who are not on the whitelist when it changes.', false),
            $bool('online-mode', 'general', 'Online mode', 'Check players against Mojang accounts. Turn off only behind a proxy or for offline play.', true),
            ['key' => 'spawn-protection', 'group' => 'general', 'type' => 'int', 'label' => 'Spawn protection', 'help' => 'Radius around spawn that only operators can change. 0 turns it off.', 'default' => '16', 'min' => 0, 'max' => 29999984],
            ['key' => 'view-distance', 'group' => 'general', 'type' => 'int', 'label' => 'View distance', 'help' => 'Chunks sent to each player. Lower is lighter on the server.', 'default' => '10', 'min' => 2, 'max' => 32],
            ['key' => 'simulation-distance', 'group' => 'general', 'type' => 'int', 'label' => 'Simulation distance', 'help' => 'Chunks around a player where mobs and crops are active.', 'default' => '10', 'min' => 3, 'max' => 32],
            $bool('allow-flight', 'general', 'Allow flight', 'Do not kick players for flying. Needed for some mods and plugins.', false),
            $bool('enable-command-block', 'general', 'Command blocks', 'Allow command blocks to run.', false),

            ['key' => 'level-name', 'group' => 'world', 'type' => 'string', 'label' => 'World folder', 'help' => 'The folder the server loads its world from. A new one is created if it does not exist.', 'default' => 'world'],
            ['key' => 'level-seed', 'group' => 'world', 'type' => 'string', 'label' => 'Seed', 'help' => 'Used when a new world is created. Empty means random.', 'default' => ''],
            ['key' => 'level-type', 'group' => 'world', 'type' => 'select', 'label' => 'World type', 'help' => 'Older versions use default, flat, largeBiomes and amplified.', 'default' => 'minecraft:normal', 'options' => [
                ['value' => 'minecraft:normal', 'label' => 'Normal'],
                ['value' => 'minecraft:flat', 'label' => 'Flat'],
                ['value' => 'minecraft:large_biomes', 'label' => 'Large biomes'],
                ['value' => 'minecraft:amplified', 'label' => 'Amplified'],
                ['value' => 'minecraft:single_biome_surface', 'label' => 'Single biome'],
            ]],
            $bool('generate-structures', 'world', 'Generate structures', 'Villages, strongholds and the like.', true),
            $bool('allow-nether', 'world', 'Allow the Nether', 'Players can enter the Nether.', true),
        ];
    }

    /** Keys the form has a field for. */
    public static function known(): array
    {
        return array_column(self::settings(), 'key');
    }

    /**
     * A problem with a new value for a key, or null when it is fine. Unlisted keys only
     * get the checks every value gets.
     */
    public static function validate(string $key, string $value): ?string
    {
        if (preg_match(self::KEY, $key) !== 1) {
            return 'Keys may only use letters, digits, dots, dashes and underscores.';
        }
        if (mb_strlen($value) > self::MAX_VALUE) {
            return 'That value is too long.';
        }
        // A line break is fine (the motd uses \n); other control characters are not.
        if (preg_match('/[^\P{Cc}\n]|[\p{Zl}\p{Zp}]/u', $value) === 1) {
            return 'That value has characters that cannot be stored.';
        }

        $setting = null;
        foreach (self::settings() as $candidate) {
            if ($candidate['key'] === $key) {
                $setting = $candidate;
            }
        }
        if ($setting === null) {
            return null;
        }

        switch ($setting['type']) {
            case 'bool':
                return $value === 'true' || $value === 'false' ? null : 'Must be true or false.';
            case 'int':
                if (preg_match('/^-?\d{1,10}$/D', $value) !== 1) {
                    return 'Enter a whole number.';
                }
                if ((int) $value < $setting['min']) {
                    return 'At least '.$setting['min'].'.';
                }

                return (int) $value > $setting['max'] ? 'At most '.$setting['max'].'.' : null;
            case 'select':
                if ($key === 'level-type') {
                    return preg_match(self::LEVEL_TYPE, $value) === 1 ? null : 'That is not a valid world type.';
                }

                return in_array($value, array_column($setting['options'], 'value'), true) ? null : 'Pick one of the listed values.';
        }

        if ($key === 'level-name' && ! Names::world($value)) {
            return 'Use letters, digits, spaces, dots, dashes and underscores (at most 100 characters).';
        }
        if ($key === 'level-seed') {
            return preg_match('/[\p{Cc}\p{Zl}\p{Zp}]/u', $value) === 1 || mb_strlen($value) > 100 ? 'The seed must be one line of at most 100 characters.' : null;
        }

        return null;
    }

    /**
     * @param  list<string>  $values
     * @return list<array{value: string, label: string}>
     */
    private static function options(array $values): array
    {
        return array_map(fn (string $value): array => ['value' => $value, 'label' => ucfirst($value)], $values);
    }
}
