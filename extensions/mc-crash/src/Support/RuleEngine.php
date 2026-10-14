<?php

namespace McCrash\Support;

/**
 * The known-pattern rules in resources/rules.json. A rule matches when any of its regexes matches
 * (and its optional "also" regex does too); named groups become variables for the verdict and fix text.
 */
final class RuleEngine
{
    /** @var array<string, self> */
    private static array $loaded = [];

    /**
     * @param  list<array<string, mixed>>  $rules
     * @param  list<array<string, mixed>>  $noise
     */
    public function __construct(private readonly array $rules, private readonly array $noise = []) {}

    public static function fromFile(string $path): self
    {
        if (isset(self::$loaded[$path])) {
            return self::$loaded[$path];
        }

        $data = json_decode((string) @file_get_contents($path), true);
        $data = is_array($data) ? $data : [];

        return self::$loaded[$path] = new self(
            array_values((array) ($data['rules'] ?? [])),
            array_values((array) ($data['noise'] ?? [])),
        );
    }

    /** Changes with the rules; part of the cache key, so an update of the rules refreshes old verdicts. */
    public function version(): string
    {
        return substr(sha1((string) json_encode([$this->rules, $this->noise])), 0, 8);
    }

    /**
     * Every rule that matches, in rule order.
     *
     * @param  array<string, string>  $context  extra variables (the exception class and message)
     * @return list<array<string, mixed>>
     */
    public function match(string $text, array $context = []): array
    {
        $found = [];
        foreach ($this->rules as $rule) {
            $finding = $this->test($rule, $text, $context);
            if ($finding !== null) {
                $found[] = $finding;
            }
        }

        return $found;
    }

    /**
     * @param  array<string, string>  $context
     * @return array<string, mixed>|null
     */
    public function first(string $text, array $context = []): ?array
    {
        foreach ($this->rules as $rule) {
            $finding = $this->test($rule, $text, $context);
            if ($finding !== null) {
                return $finding;
            }
        }

        return null;
    }

    /**
     * Noise: lines that are harmless and repeat a lot. Matched against the first line of a log entry.
     *
     * @return array{id: string, label: string}|null
     */
    public function noise(string $text): ?array
    {
        foreach ($this->noise as $noise) {
            if (self::run(self::compile((string) $noise['pattern']), $text) !== null) {
                return ['id' => (string) $noise['id'], 'label' => (string) $noise['label']];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $rule
     * @param  array<string, string>  $context
     * @return array<string, mixed>|null
     */
    private function test(array $rule, string $text, array $context): ?array
    {
        $vars = [];
        $matched = false;
        $more = 0;

        foreach ((array) $rule['match'] as $pattern) {
            $regex = self::compile((string) $pattern);
            $m = self::run($regex, $text);
            if ($m === null) {
                continue;
            }

            $matched = true;
            self::collect($vars, $m);

            $more = max($more, self::distinct($regex, $text) - 1);
        }

        if (! $matched) {
            return null;
        }

        if (isset($rule['also']) && self::run(self::compile((string) $rule['also'], 'miu'), $text) === null) {
            return null;
        }

        foreach ((array) ($rule['extra'] ?? []) as $pattern) {
            $m = self::run(self::compile((string) $pattern), $text);
            if ($m !== null) {
                self::collect($vars, $m);
            }
        }

        if (isset($rule['derive'])) {
            $vars = Derive::apply((string) $rule['derive'], $vars);
        }

        $vars += $context;
        $vars['more'] = (string) $more;
        $vars['more_text'] = $more > 0 ? ' (and '.$more.' more)' : '';

        $stat = null;
        if (isset($rule['stat']['var'])) {
            $stat = ['value' => (int) ($vars[$rule['stat']['var']] ?? 0), 'label' => (string) $rule['stat']['label']];
        }

        $key = [];
        foreach ((array) ($rule['key'] ?? []) as $name) {
            $key[] = (string) ($vars[$name] ?? '');
        }

        return [
            'rule' => (string) $rule['id'],
            'severity' => (string) $rule['severity'],
            'crash' => ($rule['crash'] ?? true) !== false,
            'title' => (string) $rule['title'],
            'verdict' => self::render((string) $rule['verdict'], $vars),
            'fix' => self::render((string) $rule['fix'], $vars),
            'culprit' => array_values((array) ($rule['culprit'] ?? ['trace'])),
            'vars' => $vars,
            'key' => $rule['id'].($key === [] ? '' : ':'.implode('|', $key)),
            'stat' => $stat,
        ];
    }

    /** Fills {name} placeholders; unknown ones become empty and the spacing is tidied. */
    public static function render(string $template, array $vars): string
    {
        $text = (string) preg_replace_callback('/\{(\w+)\}/', fn (array $m): string => (string) ($vars[$m[1]] ?? ''), $template);
        $text = (string) preg_replace('/[ \t]{2,}/', ' ', $text);
        $text = (string) preg_replace('/ ([.,;:)])/', '$1', $text);

        return trim($text);
    }

    private static function compile(string $pattern, string $flags = 'mu'): string
    {
        return '~'.str_replace('~', '\~', $pattern).'~'.$flags;
    }

    /** @return array<array-key, string>|null */
    private static function run(string $regex, string $text): ?array
    {
        $result = @preg_match($regex, $text, $m);

        return $result === 1 ? $m : null;
    }

    private static function distinct(string $regex, string $text): int
    {
        if (@preg_match_all($regex, $text, $all, PREG_SET_ORDER) === false) {
            return 1;
        }

        return min(50, count(array_unique(array_map(fn (array $m): string => $m[0], $all))));
    }

    /**
     * @param  array<string, string>  $vars
     * @param  array<array-key, string>  $m
     */
    private static function collect(array &$vars, array $m): void
    {
        foreach ($m as $name => $value) {
            if (is_string($name) && $value !== '' && ! isset($vars[$name])) {
                $vars[$name] = $value;
            }
        }
    }
}
