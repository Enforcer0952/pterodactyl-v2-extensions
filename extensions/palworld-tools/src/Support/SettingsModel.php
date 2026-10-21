<?php

namespace PalworldTools\Support;

use InvalidArgumentException;

/**
 * Everything the Settings page does that does not touch a server: the schema, reading
 * values out of PalWorldSettings.ini, checking and formatting new values, masking the
 * passwords and applying edits. It works on file text only, so it is testable without
 * a panel.
 *
 * Value formats follow what Palworld writes itself: floats with six decimals
 * (1.000000), booleans as True/False, strings in double quotes, lists in brackets.
 *
 * @phpstan-type Setting array{key: string, group: string, type: string, default: string, label: string, help: string, min?: int|float, max?: int|float, options?: list<string>, secret?: bool, env?: string}
 */
final class SettingsModel
{
    public const string MASK = '********';

    /** Error code for an edit the user is not allowed to make. */
    public const int FORBIDDEN = 403;

    /** @var array<string, mixed> */
    private array $schema;

    /** @var array<string, Setting> lower-case key => setting */
    private array $byKey = [];

    /**
     * @param  array<string, mixed>|null  $schema
     */
    public function __construct(?array $schema = null)
    {
        $this->schema = $schema ?? self::loadSchema();
        foreach ($this->schema['settings'] as $setting) {
            $this->byKey[strtolower($setting['key'])] = $setting;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function loadSchema(): array
    {
        $path = dirname(__DIR__, 2).'/resources/settings-schema.json';

        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(): array
    {
        return $this->schema;
    }

    /** @return Setting|null */
    public function setting(string $key): ?array
    {
        return $this->byKey[strtolower($key)] ?? null;
    }

    public function isSecret(string $key): bool
    {
        foreach ($this->schema['secrets'] as $secret) {
            if (strcasecmp($secret, $key) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * The file to start from: the current one, or the game's default template when the
     * current one is missing or has no settings in it, or a bare section as a last resort.
     */
    public function base(?string $current, ?string $template): string
    {
        foreach ([$current, $template] as $candidate) {
            if ($candidate !== null && OptionSettings::exists($candidate)) {
                try {
                    if (OptionSettings::parse($candidate)->keys() !== []) {
                        return $candidate;
                    }
                } catch (InvalidArgumentException) {
                    // A broken file is not a base; the page reports it.
                    if ($candidate === $current) {
                        return $candidate;
                    }
                }
            }
        }

        return '['.$this->schema['section']."]\nOptionSettings=()\n";
    }

    /**
     * The value of every known setting. A password is never returned, only whether one is set.
     *
     * @return list<array{key: string, value: ?string, set: ?bool}>
     */
    public function fields(string $text): array
    {
        try {
            $options = OptionSettings::parse($text);
        } catch (InvalidArgumentException) {
            $options = null;
        }

        $fields = [];
        foreach ($this->schema['settings'] as $setting) {
            $value = $options?->get($setting['key']);
            if ($this->isSecret($setting['key'])) {
                $fields[] = ['key' => $setting['key'], 'value' => null, 'set' => $value !== null && $value !== ''];

                continue;
            }
            $fields[] = ['key' => $setting['key'], 'value' => $value, 'set' => null];
        }

        return $fields;
    }

    /**
     * The file text with every password replaced by asterisks. Works on the text itself,
     * so a file that does not parse, or one with more than one OptionSettings line, never
     * shows a password either.
     */
    public function mask(string $text): string
    {
        $keys = implode('|', array_map(fn (string $key): string => preg_quote($key, '/'), $this->schema['secrets']));
        if ($keys === '') {
            return $text;
        }

        return (string) preg_replace_callback(
            '/(?<![A-Za-z0-9_])('.$keys.')(\s*=\s*)("(?:[^"\\\\]|\\\\.)*"?|[^,)\r\n]*)/i',
            function (array $m): string {
                $value = trim($m[3]);
                if ($value === '' || $value === '""') {
                    return $m[0];
                }

                return $m[1].$m[2].OptionSettings::quote(self::MASK);
            },
            $text,
        );
    }

    /**
     * Turn a value typed by a person into what goes after the "=": checked against the
     * setting's type and range, and formatted the way Palworld writes it.
     *
     * @throws InvalidArgumentException
     */
    public function format(string $key, string $value): string
    {
        $setting = $this->setting($key) ?? throw new InvalidArgumentException('Unknown setting '.$key.'.');
        $label = $setting['label'];
        $value = trim($value);

        switch ($setting['type']) {
            case 'bool':
                if (preg_match('/^(true|false)$/i', $value) !== 1) {
                    throw new InvalidArgumentException($label.' must be True or False.');
                }

                return ucfirst(strtolower($value));
            case 'int':
                if (preg_match('/^-?\d{1,12}$/D', $value) !== 1) {
                    throw new InvalidArgumentException($label.' must be a whole number.');
                }
                $this->checkRange($setting, (float) $value);

                return (string) (int) $value;
            case 'float':
                if (preg_match('/^-?\d{1,12}(\.\d{1,12})?$/D', $value) !== 1) {
                    throw new InvalidArgumentException($label.' must be a number.');
                }
                $this->checkRange($setting, (float) $value);

                return number_format((float) $value, 6, '.', '');
            case 'enum':
                foreach ($setting['options'] ?? [] as $option) {
                    if (strcasecmp($option, $value) === 0) {
                        return $option;
                    }
                }
                throw new InvalidArgumentException($label.' must be one of '.implode(', ', $setting['options'] ?? []).'.');
            case 'list':
                $chosen = [];
                foreach (preg_split('/\s*,\s*/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $item) {
                    $match = null;
                    foreach ($setting['options'] ?? [] as $option) {
                        if (strcasecmp($option, $item) === 0) {
                            $match = $option;
                        }
                    }
                    if ($match === null) {
                        throw new InvalidArgumentException($label.' can only contain '.implode(', ', $setting['options'] ?? []).'.');
                    }
                    if (! in_array($match, $chosen, true)) {
                        $chosen[] = $match;
                    }
                }

                return '('.implode(',', $chosen).')';
            default:
                if (isset($setting['max']) && mb_strlen($value) > $setting['max']) {
                    throw new InvalidArgumentException($label.' can be at most '.$setting['max'].' characters.');
                }
                if (mb_strlen($value) > 500) {
                    throw new InvalidArgumentException($label.' is too long.');
                }

                return OptionSettings::quote($value);
        }
    }

    /**
     * Apply a raw replacement and/or single edits to the file text.
     *
     * Passwords: the raw text shows them as asterisks, which means "keep". Without
     * permission to change them they always stay as they are.
     *
     * @param  list<array{key: string, value: ?string}>  $edits  a null value removes the setting so the game uses its default
     *
     * @throws InvalidArgumentException code FORBIDDEN for a password edit without permission
     */
    public function apply(string $current, ?string $raw, array $edits, bool $canSecrets): string
    {
        $text = $current;
        if ($raw !== null) {
            $text = $this->fromRaw($current, $raw, $canSecrets);
        }

        if ($edits === []) {
            return $text;
        }

        $options = OptionSettings::parse($text);
        foreach ($edits as $edit) {
            $setting = $this->setting($edit['key']) ?? throw new InvalidArgumentException('Unknown setting '.$edit['key'].'.');
            if ($this->isSecret($setting['key']) && ! $canSecrets) {
                throw new InvalidArgumentException('You may not change the '.$setting['label'].'.', self::FORBIDDEN);
            }
            if ($edit['value'] === null) {
                $options->remove($setting['key']);
            } else {
                $options->set($setting['key'], $this->format($setting['key'], $edit['value']));
            }
        }

        return $options->render();
    }

    /**
     * Problems worth fixing, each with the edits that fix it.
     *
     * @return list<array{key: string, kind: string, level: string, message: string, fixes: list<array{label: string, edits: list<array{key: string, value: ?string}>}>}>
     */
    public function issues(string $text): array
    {
        try {
            $options = OptionSettings::parse($text);
        } catch (InvalidArgumentException $exception) {
            return [[
                'key' => '',
                'kind' => 'broken',
                'level' => 'warning',
                'message' => $exception->getMessage().' Fix it in the raw editor.',
                'fixes' => [],
            ]];
        }

        $issues = [];
        $seen = [];
        foreach ($options->entries() as $entry) {
            $id = strtolower($entry['key']);
            $seen[$id] = ($seen[$id] ?? 0) + 1;
        }
        foreach ($seen as $id => $count) {
            if ($count < 2) {
                continue;
            }
            $key = $this->setting($id)['key'] ?? $id;
            $last = (string) $options->get($key);
            $issues[] = [
                'key' => $key,
                'kind' => 'duplicate',
                'level' => 'warning',
                'message' => $key.' is set '.$count.' times. Only the last one counts.',
                'fixes' => $this->isSecret($key) ? [] : [[
                    'label' => 'Keep '.($last === '' ? 'the last one' : $last),
                    'edits' => [['key' => $key, 'value' => $last]],
                ]],
            ];
        }

        foreach ($this->schema['settings'] as $setting) {
            $value = $options->get($setting['key']);
            if ($value === null || $setting['type'] === 'string' || $this->isSecret($setting['key'])) {
                continue;
            }
            try {
                $this->format($setting['key'], $value);
            } catch (InvalidArgumentException $exception) {
                $issues[] = [
                    'key' => $setting['key'],
                    'kind' => 'invalid',
                    'level' => 'warning',
                    'message' => $exception->getMessage().' Now it is '.$value.'.',
                    'fixes' => [[
                        'label' => 'Use the default ('.OptionSettings::display($setting['default']).')',
                        'edits' => [['key' => $setting['key'], 'value' => null]],
                    ]],
                ];
            }
        }

        return $issues;
    }

    /**
     * @throws InvalidArgumentException
     */
    private function fromRaw(string $current, string $raw, bool $canSecrets): string
    {
        if (str_contains($raw, "\0")) {
            throw new InvalidArgumentException('The text contains a null byte.');
        }
        $new = OptionSettings::parse($raw);
        $old = OptionSettings::exists($current) ? $this->tryParse($current) : null;

        foreach ($this->schema['secrets'] as $secret) {
            $keep = ! $canSecrets || $new->get($secret) === self::MASK;
            if (! $keep) {
                continue;
            }
            $token = $old?->raw($secret);
            if ($token === null) {
                $new->remove($secret);
            } else {
                $new->set($secret, $token);
            }
        }

        // A value changed by hand must be as valid as one typed in the form.
        foreach ($this->schema['settings'] as $setting) {
            $value = $new->get($setting['key']);
            if ($value === null || $this->isSecret($setting['key']) || $value === $old?->get($setting['key'])) {
                continue;
            }
            $this->format($setting['key'], $value);
        }

        return $new->render();
    }

    private function tryParse(string $text): ?OptionSettings
    {
        try {
            return OptionSettings::parse($text);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @param  Setting  $setting
     */
    private function checkRange(array $setting, float $number): void
    {
        if (isset($setting['min']) && $number < $setting['min']) {
            throw new InvalidArgumentException($setting['label'].' must be at least '.$setting['min'].'.');
        }
        if (isset($setting['max']) && $number > $setting['max']) {
            throw new InvalidArgumentException($setting['label'].' must be at most '.$setting['max'].'.');
        }
    }
}
