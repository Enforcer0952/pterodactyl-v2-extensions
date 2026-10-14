<?php

namespace McCrash\Support;

/**
 * Turns the text of a crash report, hs_err file or log entry into one verdict:
 * what broke, which plugin or mod did it, and how to fix it.
 */
final class Analyzer
{
    /** A crash report is read up to this size; the rest is cut off. */
    public const MAX_TEXT = 2 * 1024 * 1024;

    public function __construct(private readonly RuleEngine $rules) {}

    /**
     * @param  array{tag?: ?string}  $options
     * @return array<string, mixed>
     */
    public function analyze(string $text, JarMap $map, array $options = []): array
    {
        $text = Text::clean($text, self::MAX_TEXT);
        $meta = ReportMeta::parse($text);
        $chains = TraceParser::parse($text);

        $context = [];
        if ($chains !== []) {
            $root = TraceParser::root($chains[0]);
            $context['exception'] = Text::simpleName((string) $root['exception']);
            $context['message'] = Text::oneLine((string) $root['message'], 160);
        }

        $findings = $this->rules->match($text, $context);
        $primary = $findings[0] ?? null;

        $culprit = Culprit::resolve(
            $primary['culprit'] ?? ['trace'],
            $primary['vars'] ?? [],
            $chains,
            $map,
            ['suspected' => $meta['suspected'], 'tag' => $options['tag'] ?? null],
        );

        $verdict = $primary !== null ? $this->known($primary, $meta['kind']) : $this->generic($meta, $chains, $culprit);

        $verdict['culprit'] = $culprit;
        $verdict['exception'] = $chains === [] ? null : (string) TraceParser::root($chains[0])['exception'];
        $verdict['message'] = $context['message'] ?? null;
        $verdict['also'] = array_map(
            fn (array $finding): array => ['rule' => $finding['rule'], 'title' => $finding['title'], 'verdict' => $finding['verdict']],
            array_slice($findings, 1, 3),
        );
        $verdict['meta'] = ['kind' => $meta['kind'], 'description' => $meta['description'], 'minecraft' => $meta['minecraft'], 'java' => $meta['java']];
        $verdict['time'] = $meta['time'];
        $verdict['stat'] = $primary['stat'] ?? null;
        $verdict['key'] = $primary['key'] ?? null;

        return $verdict;
    }

    /**
     * @param  array<string, mixed>  $finding
     * @return array<string, mixed>
     */
    private function known(array $finding, string $kind): array
    {
        return [
            'rule' => $finding['rule'],
            // A crash report or hs_err file is a crash, whatever known problem it holds.
            'severity' => $kind !== 'log' ? 'crash' : $finding['severity'],
            'title' => $finding['title'],
            'verdict' => $finding['verdict'],
            'fix' => $finding['fix'],
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  list<array<string, mixed>>  $chains
     * @param  array<string, mixed>|null  $culprit
     * @return array<string, mixed>
     */
    private function generic(array $meta, array $chains, ?array $culprit): array
    {
        $crash = $meta['kind'] !== 'log';

        if ($chains === []) {
            return [
                'rule' => null,
                'severity' => $crash ? 'crash' : 'info',
                'title' => $crash ? 'Crash' : 'Nothing found',
                'verdict' => $meta['description'] ?? 'No known problem found in this file.',
                'fix' => 'Nothing in this file matches a known problem. Read the raw text below, or use Share to ask for help.',
            ];
        }

        $root = TraceParser::root($chains[0]);
        $message = Text::oneLine((string) $root['message'], 140);
        $verdict = Text::simpleName((string) $root['exception']).($message !== '' ? ': '.$message : '');

        if ($culprit === null) {
            $fix = 'No plugin or mod shows up in the trace, so the cause is probably the server software, Java or the world. Update the server and Java, and try without recently added plugins or mods. Use Share to ask for help.';
        } elseif ($culprit['tier'] === 'confirmed') {
            $names = implode(', ', array_map(fn (array $jar): string => (string) $jar['name'], $culprit['jars']));
            $fix = 'Update '.$names.' to the newest version made for your Minecraft version. If it keeps happening, send the report to its author (use Share), or remove it.';
        } else {
            $fix = 'The plugin or mod below is only a guess. Try updating it, and if the problem stays, test without it. Use Share to ask for help.';
        }

        return [
            'rule' => null,
            'severity' => $crash ? 'crash' : 'error',
            'title' => $crash ? 'Crash' : 'Error',
            'verdict' => $verdict,
            'fix' => $fix,
        ];
    }
}
