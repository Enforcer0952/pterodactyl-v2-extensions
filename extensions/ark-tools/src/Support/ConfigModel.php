<?php

namespace ArkTools\Support;

use InvalidArgumentException;

/**
 * Everything the Config page does that does not touch a server: the settings form,
 * the problems check, masking passwords and applying edits. It works on file
 * contents only, so it is fully testable without a panel.
 *
 * @phpstan-type Setting array{key: string, file: string, section: string, type: string, default: string, min?: float|int, max?: float|int, group: string, label: string, help: string}
 * @phpstan-type Edit array{file: string, section: string, key: string, value: ?string}
 */
final class ConfigModel
{
    public const string MASK = '********';

    /** @var array<string, mixed> */
    private array $schema;

    /** @var array<string, Setting> lower-case key => setting */
    private array $byKey = [];

    /** @var list<string> lower-case real ARK keys the form does not cover */
    private array $known = [];

    /**
     * @param  array<string, mixed>|null  $schema
     */
    public function __construct(?array $schema = null)
    {
        $this->schema = $schema ?? self::loadSchema();
        foreach ($this->schema['settings'] as $setting) {
            $this->byKey[strtolower($setting['key'])] = $setting;
        }
        $this->known = array_map('strtolower', [...($this->schema['known_keys'] ?? []), ...$this->schema['secrets']]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function loadSchema(): array
    {
        $path = dirname(__DIR__, 2).'/resources/config-schema.json';

        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, string> file name => path on the server
     */
    public function files(): array
    {
        return $this->schema['files'];
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(): array
    {
        return $this->schema;
    }

    public function setting(string $key): ?array
    {
        return $this->byKey[strtolower($key)] ?? null;
    }

    public function isSecret(string $key): bool
    {
        foreach ($this->schema['secrets'] as $secret) {
            if (Ini::same($secret, $key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Keys a startup command sets with "?Key=value". ARK lets those win over the INI.
     *
     * @return list<string>
     */
    public static function startupKeys(string ...$commands): array
    {
        $keys = [];
        foreach ($commands as $command) {
            preg_match_all('/\?([A-Za-z][A-Za-z0-9_]*)=/', $command, $matches);
            foreach ($matches[1] as $key) {
                if (! in_array(strtolower($key), array_map('strtolower', $keys), true)) {
                    $keys[] = $key;
                }
            }
        }

        return $keys;
    }

    /**
     * The value of every known setting, where it lives and whether startup overrides it.
     *
     * @param  array<string, ?string>  $contents  file name => contents (null when missing)
     * @param  list<string>  $startupKeys
     * @return list<array<string, mixed>>
     */
    public function fields(array $contents, array $startupKeys): array
    {
        $inis = $this->parseAll($contents);
        $fields = [];
        foreach ($this->schema['settings'] as $setting) {
            $value = $inis[$setting['file']]?->get($setting['section'], $setting['key']);
            $fields[] = [
                'key' => $setting['key'],
                'value' => $value === null ? null : trim($value),
                'overridden' => self::inList($startupKeys, $setting['key']),
            ];
        }

        return $fields;
    }

    /**
     * Problems worth fixing: settings in a file or section where ARK never reads them,
     * likely typos of known settings and duplicates. Each problem carries the edits
     * that fix it. Startup overrides are shown on the fields instead.
     *
     * @param  array<string, ?string>  $contents
     * @return list<array<string, mixed>>
     */
    public function issues(array $contents): array
    {
        $inis = $this->parseAll($contents);
        $issues = [];

        foreach ($inis as $file => $ini) {
            if ($ini === null) {
                continue;
            }

            $seen = [];
            foreach ($ini->entries() as $entry) {
                if (! $this->isCoreSection($file, $entry['section'])) {
                    continue;
                }

                $key = trim($entry['key']);
                $value = trim($entry['value']);
                $here = ['file' => $file, 'section' => $entry['section'], 'key' => $key, 'line' => $entry['line']];

                $setting = $this->setting($key);
                $typo = false;
                if ($setting === null) {
                    $setting = $this->closest($key);
                    $typo = $setting !== null;
                }
                if ($setting === null) {
                    continue;
                }

                $correctPlace = Ini::same($setting['file'], $file) && Ini::same($setting['section'], $entry['section']);

                if (! $typo && $correctPlace) {
                    $id = strtolower($key);
                    $seen[$id] = ($seen[$id] ?? 0) + 1;
                    if ($seen[$id] === 2) {
                        $values = array_map('trim', $ini->all($setting['section'], $setting['key']));
                        $last = $values[array_key_last($values)];
                        $issues[] = $here + [
                            'kind' => 'duplicate',
                            'level' => 'warning',
                            'message' => $setting['key'].' is set '.count($values).' times ('.implode(', ', $values).'). Only the last one, '.$last.', counts.',
                            'fixes' => [[
                                'label' => 'Keep '.$last,
                                'edits' => [$this->edit($setting['file'], $setting['section'], $setting['key'], $last)],
                            ]],
                        ];
                    }

                    continue;
                }

                $active = $inis[$setting['file']]?->get($setting['section'], $setting['key']);
                $active = $active === null ? null : trim($active);
                $remove = $this->edit($file, $entry['section'], $key, null);
                $target = $setting['file'].' ['.$setting['section'].']';

                if ($typo) {
                    $message = $key.' is not an ARK setting. Did you mean '.$setting['key'].' in '.$target.'?';
                } else {
                    $message = $setting['key'].' only works in '.$target.'. ARK ignores it here.';
                }

                if ($active === null) {
                    $message .= ' Right now the server uses the default ('.$setting['default'].').';
                    $fixes = [[
                        'label' => $typo ? 'Rename to '.$setting['key'] : 'Move it there',
                        'edits' => [$this->edit($setting['file'], $setting['section'], $setting['key'], $value), $remove],
                    ]];
                } elseif (self::sameValue($active, $value)) {
                    $message .= ' It is already set there to the same value, so this line does nothing.';
                    $fixes = [['label' => 'Remove this line', 'edits' => [$remove]]];
                } else {
                    $message .= ' The server uses '.$active.' from '.$target.', not '.$value.'.';
                    $fixes = [
                        ['label' => 'Keep '.$active, 'edits' => [$remove]],
                        ['label' => 'Use '.$value, 'edits' => [$this->edit($setting['file'], $setting['section'], $setting['key'], $value), $remove]],
                    ];
                }

                $issues[] = $here + [
                    'kind' => $typo ? 'typo' : 'misplaced',
                    'level' => 'warning',
                    'message' => $message,
                    'fixes' => $fixes,
                ];
            }
        }

        return $issues;
    }

    /** Hide passwords: the admin password gives full RCON control of the server. */
    public function mask(string $contents): string
    {
        $secrets = implode('|', array_map(fn (string $key): string => preg_quote($key, '/'), $this->schema['secrets']));

        return (string) preg_replace_callback(
            // [^\r\n] so a CRLF file keeps its \r after the mask.
            '/^(\h*(?:'.$secrets.')\h*=)(\h*\S[^\r\n]*?)(\h*)(?=\r?$)/mi',
            fn (array $match): string => $match[1].self::MASK.$match[3],
            $contents,
        );
    }

    /**
     * Apply a save to the current files: whole-file text from the raw editor first,
     * then single-setting edits. Masked passwords in raw text are put back from the
     * current file, so saving the raw view never wipes or reveals them.
     *
     * @param  array<string, ?string>  $current
     * @param  array<string, string>  $raw  file name => full text
     * @param  list<Edit>  $edits
     * @return array<string, string> changed files only
     */
    public function apply(array $current, array $raw, array $edits): array
    {
        $result = [];

        foreach ($raw as $file => $text) {
            $this->assertFile($file);
            $original = (string) ($current[$file] ?? '');
            $result[$file] = $this->unmask($text, $original);
            $this->assertSecretsUnchanged($file, $original, $result[$file]);
        }

        $inis = [];
        foreach ($edits as $edit) {
            $this->assertEdit($edit);
            $file = $edit['file'];
            $inis[$file] ??= Ini::parse($result[$file] ?? (string) ($current[$file] ?? ''));
            if ($edit['value'] === null) {
                $inis[$file]->remove($edit['section'], $edit['key']);
            } else {
                $inis[$file]->set($edit['section'], $edit['key'], $edit['value']);
            }
        }
        foreach ($inis as $file => $ini) {
            $result[$file] = $ini->render();
        }

        return array_filter($result, fn (string $text, string $file): bool => $text !== (string) ($current[$file] ?? ''), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * @param  Edit  $edit
     */
    private function assertEdit(array $edit): void
    {
        $this->assertFile($edit['file']);

        if (! $this->isCoreSection($edit['file'], $edit['section'])) {
            throw new InvalidArgumentException('Only the server settings sections can be edited here. Use the raw editor for '.$edit['section'].'.');
        }
        if (preg_match('/^[A-Za-z][A-Za-z0-9_.]{0,127}$/', $edit['key']) !== 1) {
            throw new InvalidArgumentException('"'.$edit['key'].'" is not a valid setting name.');
        }
        if ($this->isSecret($edit['key'])) {
            throw new InvalidArgumentException('Passwords are set on the Startup page.');
        }
        if ($edit['value'] === null) {
            return;
        }

        $setting = $this->setting($edit['key']);
        if ($setting === null || ! Ini::same($setting['file'], $edit['file']) || ! Ini::same($setting['section'], $edit['section'])) {
            throw new InvalidArgumentException($edit['key'].' cannot be set in '.$edit['file'].' ['.$edit['section'].'].');
        }

        $value = trim($edit['value']);
        $label = $setting['label'];
        switch ($setting['type']) {
            case 'bool':
                if (! in_array(strtolower($value), ['true', 'false'], true)) {
                    throw new InvalidArgumentException($label.' must be True or False.');
                }
                break;
            case 'int':
            case 'float':
                if (! is_numeric($value) || ($setting['type'] === 'int' && preg_match('/^-?\d+$/', $value) !== 1)) {
                    throw new InvalidArgumentException($label.' must be a '.($setting['type'] === 'int' ? 'whole number' : 'number').'.');
                }
                if (isset($setting['min']) && (float) $value < $setting['min']) {
                    throw new InvalidArgumentException($label.' must be at least '.$setting['min'].'.');
                }
                if (isset($setting['max']) && (float) $value > $setting['max']) {
                    throw new InvalidArgumentException($label.' must be at most '.$setting['max'].'.');
                }
                break;
            default:
                if (mb_strlen($value) > 500) {
                    throw new InvalidArgumentException($label.' is too long.');
                }
        }
    }

    private function assertFile(string $file): void
    {
        if (! array_key_exists($file, $this->schema['files'])) {
            throw new InvalidArgumentException('Unknown config file '.$file.'.');
        }
    }

    /**
     * Passwords are managed on the Startup page; the raw editor may move or keep
     * their lines but never change, add or remove a value.
     */
    private function assertSecretsUnchanged(string $file, string $original, string $updated): void
    {
        $values = function (string $contents): array {
            $found = [];
            foreach (Ini::parse($contents)->entries() as $entry) {
                if ($this->isSecret($entry['key'])) {
                    $found[strtolower(trim($entry['key']))][] = trim($entry['value']);
                }
            }
            ksort($found);

            return $found;
        };

        if ($values($original) !== $values($updated)) {
            throw new InvalidArgumentException('Passwords in '.$file.' cannot be changed here. Change them on the Startup page.');
        }
    }

    private function unmask(string $text, string $original): string
    {
        $old = Ini::parse($original);
        $secrets = implode('|', array_map(fn (string $key): string => preg_quote($key, '/'), $this->schema['secrets']));

        return (string) preg_replace_callback(
            '/^(\h*('.$secrets.')\h*=)\h*'.preg_quote(self::MASK, '/').'(\h*)(?=\r?$)/mi',
            function (array $match) use ($old): string {
                $value = null;
                foreach ($old->entries() as $entry) {
                    if (Ini::same($entry['key'], $match[2])) {
                        $value = $entry['value'];
                    }
                }

                return $match[1].($value ?? '').$match[3];
            },
            $text,
        );
    }

    /**
     * A known setting this unknown key is most likely a typo of: one or two letters
     * off, or a "b" prefix added or missing.
     *
     * @return Setting|null
     */
    private function closest(string $key): ?array
    {
        $lower = strtolower($key);
        if (strlen($lower) < 10 || in_array($lower, $this->known, true)) {
            return null;
        }

        $best = null;
        $bestDistance = 3;
        foreach ($this->byKey as $known => $setting) {
            if ($lower === 'b'.$known || 'b'.$lower === $known) {
                return $setting;
            }
            $distance = levenshtein($lower, $known);
            if ($distance < $bestDistance) {
                $best = $setting;
                $bestDistance = $distance;
            }
        }

        return $best;
    }

    private function isCoreSection(string $file, string $section): bool
    {
        foreach ($this->schema['core_sections'][$file] ?? [] as $core) {
            if (Ini::same($core, $section)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return Edit
     */
    private function edit(string $file, string $section, string $key, ?string $value): array
    {
        return ['file' => $file, 'section' => $section, 'key' => $key, 'value' => $value];
    }

    /**
     * @param  array<string, ?string>  $contents
     * @return array<string, ?Ini>
     */
    private function parseAll(array $contents): array
    {
        $inis = [];
        foreach (array_keys($this->schema['files']) as $file) {
            $text = $contents[$file] ?? null;
            $inis[$file] = $text === null ? null : Ini::parse($text);
        }

        return $inis;
    }

    private static function sameValue(string $a, string $b): bool
    {
        if (is_numeric($a) && is_numeric($b)) {
            return abs((float) $a - (float) $b) < 1e-9;
        }

        return strcasecmp($a, $b) === 0;
    }

    /**
     * @param  list<string>  $list
     */
    private static function inList(array $list, string $key): bool
    {
        foreach ($list as $item) {
            if (Ini::same($item, $key)) {
                return true;
            }
        }

        return false;
    }
}
