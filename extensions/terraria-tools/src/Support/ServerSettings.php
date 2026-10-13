<?php

namespace TerrariaTools\Support;

use InvalidArgumentException;

/**
 * The serverconfig.txt settings the Settings page has a form for: what each key
 * accepts and which startup variable the egg fills it from.
 */
final class ServerSettings
{
    public const string MASK = '********';

    /**
     * key => label, kind, limits and the egg's startup variable.
     *
     * @var array<string, array{label: string, kind: 'int'|'text'|'choice'|'secret', env: string, min?: int, max?: int, choices?: array<string, string>, help: string}>
     */
    private const array FIELDS = [
        'maxplayers' => ['label' => 'Max players', 'kind' => 'int', 'env' => 'MAX_PLAYERS', 'min' => 1, 'max' => 255, 'help' => 'From 1 to 255.'],
        'motd' => ['label' => 'Message of the day', 'kind' => 'text', 'env' => 'SERVER_MOTD', 'max' => 200, 'help' => 'Shown to players when they join.'],
        'password' => ['label' => 'Server password', 'kind' => 'secret', 'env' => 'PASSWORD', 'max' => 100, 'help' => 'Leave empty for no password.'],
        'difficulty' => [
            'label' => 'Difficulty for a new world',
            'kind' => 'choice',
            'env' => 'WORLD_DIFFICULTY',
            'choices' => ['0' => 'Classic', '1' => 'Expert', '2' => 'Master', '3' => 'Journey'],
            'help' => 'Only used when the server creates a world.',
        ],
        'autocreate' => [
            'label' => 'Create a missing world',
            'kind' => 'choice',
            'env' => 'WORLD_SIZE',
            'choices' => ['0' => 'Never', '1' => 'Small', '2' => 'Medium', '3' => 'Large'],
            'help' => 'When the world file is missing, the server generates one of this size.',
        ],
        'npcstream' => ['label' => 'NPC stream', 'kind' => 'int', 'env' => 'NPCSTREAM', 'min' => 0, 'max' => 255, 'help' => 'Less enemy skipping for more bandwidth. 0 turns it off.'],
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::FIELDS);
    }

    /** The startup variable the egg fills a key from. */
    public static function variableFor(string $key): ?string
    {
        return self::FIELDS[strtolower($key)]['env'] ?? null;
    }

    public static function isSecret(string $key): bool
    {
        return strtolower($key) === 'password';
    }

    /**
     * What the form shows for each setting. A secret's value is only included when
     * $reveal is true; otherwise just whether one is set.
     *
     * @return list<array<string, mixed>>
     */
    public static function fields(ServerConfig $config, bool $reveal): array
    {
        $fields = [];
        foreach (self::FIELDS as $key => $field) {
            $value = $config->get($key);
            $secret = $field['kind'] === 'secret';
            $fields[] = [
                'key' => $key,
                'label' => $field['label'],
                'kind' => $field['kind'],
                'help' => $field['help'],
                'value' => $secret && ! $reveal ? null : $value,
                'set' => $value !== null && $value !== '',
                'present' => $value !== null,
                'min' => $field['min'] ?? null,
                'max' => $field['max'] ?? null,
                'choices' => isset($field['choices']) ? array_map(
                    fn (string $value, string $label): array => ['value' => $value, 'label' => $label],
                    array_keys($field['choices']),
                    array_values($field['choices']),
                ) : null,
            ];
        }

        return $fields;
    }

    /** Null when the value is fine, else a message. */
    public static function validate(string $key, string $value): ?string
    {
        $field = self::FIELDS[strtolower($key)] ?? null;
        if ($field === null) {
            return 'Unknown setting '.$key.'.';
        }

        if (preg_match('/[\p{Cc}\p{Zl}\p{Zp}]/u', $value) === 1) {
            return $field['label'].' must be a single line of text.';
        }

        return match ($field['kind']) {
            'int' => preg_match('/^\d{1,4}$/D', $value) === 1 && (int) $value >= $field['min'] && (int) $value <= $field['max']
                ? null
                : $field['label'].' must be a number from '.$field['min'].' to '.$field['max'].'.',
            'choice' => isset($field['choices'][$value]) ? null : $field['label'].' must be one of: '.implode(', ', array_keys($field['choices'])).'.',
            default => mb_strlen($value) <= $field['max'] ? null : $field['label'].' can be at most '.$field['max'].' characters.',
        };
    }

    /**
     * Apply form edits to a config, validated.
     *
     * @param  array<string, string>  $edits  key => value
     *
     * @throws InvalidArgumentException
     */
    public static function apply(ServerConfig $config, array $edits): void
    {
        foreach ($edits as $key => $value) {
            $error = self::validate((string) $key, (string) $value);
            if ($error !== null) {
                throw new InvalidArgumentException($error);
            }
        }
        foreach ($edits as $key => $value) {
            $config->set((string) $key, (string) $value);
        }
    }
}
