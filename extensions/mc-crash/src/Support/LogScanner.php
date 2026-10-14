<?php

namespace McCrash\Support;

/**
 * Groups the warnings and errors of one log by signature, so a stack trace that repeats 4000 times
 * is one line with a count, a first and a last time. Harmless repeating lines are collapsed into noise groups.
 */
final class LogScanner
{
    /** Groups kept per log; the rest only count in "dropped". */
    public const MAX_GROUPS = 150;

    private const SAMPLE_LINES = 60;

    private const SAMPLE_BYTES = 6000;

    private const SEVERITY = ['crash' => 0, 'error' => 1, 'warning' => 2, 'info' => 3];

    public function __construct(private readonly Analyzer $analyzer, private readonly RuleEngine $rules) {}

    /**
     * @param  string  $anchor  "end": $date is the day of the last line (the live log), "start": the day of the first line
     *                          (a rotated log), "none": the day is not known
     * @return array{groups: list<array<string, mixed>>, entries: int, dropped: int}
     */
    public function scan(string $text, JarMap $map, ?string $date = null, string $anchor = 'none'): array
    {
        $groups = [];
        $bySignature = [];
        $entries = 0;

        $generator = LogParser::entries(Text::clean($text));
        foreach ($generator as $entry) {
            $entries++;
            $stamp = [$entry['day'], $entry['time']];
            $noise = $this->rules->noise($entry['message']);

            if ($noise !== null) {
                $id = 'noise:'.$noise['id'];
                if (! isset($groups[$id])) {
                    $groups[$id] = $this->group($id, [
                        'rule' => null, 'severity' => 'info', 'title' => $noise['label'], 'fix' => null, 'culprit' => null,
                        'exception' => null, 'stat' => null,
                    ], $entry, true);
                }
                $this->touch($groups[$id], $stamp);

                continue;
            }

            $chains = $this->hasFrames($entry['body']) ? TraceParser::parse($entry['body']) : [];
            $signature = $this->signature($entry, $chains);

            if (isset($bySignature[$signature])) {
                $id = $bySignature[$signature];
                $this->touch($groups[$id], $stamp);
                if ($groups[$id]['stat'] !== null) {
                    $again = $this->rules->first($entry['body']);
                    $this->stat($groups[$id], $again['stat'] ?? null);
                }

                continue;
            }

            $verdict = $this->analyzer->analyze($entry['body'], $map, ['tag' => $entry['tag']]);
            $verdict = $this->forLog($verdict, $entry);
            $id = $verdict['key'] ?? $signature;
            $bySignature[$signature] = $id;

            if (! isset($groups[$id])) {
                $groups[$id] = $this->group($id, $verdict, $entry, false);
            }
            $this->touch($groups[$id], $stamp);
            $this->stat($groups[$id], $verdict['stat'] ?? null);
        }

        $days = $generator->getReturn();
        $list = [];
        foreach ($groups as $group) {
            $group['first'] = $this->when($group['first'], $days, $date, $anchor);
            $group['last'] = $this->when($group['last'], $days, $date, $anchor);
            $list[] = $group;
        }

        $list = self::sort($list);
        $dropped = max(0, count($list) - self::MAX_GROUPS);

        return ['groups' => array_slice($list, 0, self::MAX_GROUPS), 'entries' => $entries, 'dropped' => $dropped];
    }

    /**
     * Combines the groups of several logs. Groups with the same id add up.
     *
     * @param  list<array<string, mixed>>  $a
     * @param  list<array<string, mixed>>  $b
     * @return list<array<string, mixed>>
     */
    public static function merge(array $a, array $b): array
    {
        $byId = [];
        foreach ([...$a, ...$b] as $group) {
            $id = (string) $group['id'];
            if (! isset($byId[$id])) {
                $byId[$id] = $group;

                continue;
            }

            $into = &$byId[$id];
            $into['count'] += $group['count'];
            if ($group['first'] !== null && ($into['first'] === null || strcmp((string) $group['first'], (string) $into['first']) < 0)) {
                $into['first'] = $group['first'];
            }
            if ($group['last'] !== null && ($into['last'] === null || strcmp((string) $group['last'], (string) $into['last']) > 0)) {
                $into['last'] = $group['last'];
                // The most recent occurrence is the one worth reading.
                $into['sample'] = $group['sample'];
                $into['culprit'] = $group['culprit'] ?? $into['culprit'];
            }
            if ($group['stat'] !== null) {
                $into['stat'] = ['value' => max($into['stat']['value'] ?? 0, $group['stat']['value']), 'label' => $group['stat']['label']];
            }
            unset($into);
        }

        return self::sort(array_values($byId));
    }

    /**
     * Fills in the readable text of a group's statistic ("Worst spike: 2345 ms behind").
     *
     * @param  list<array<string, mixed>>  $groups
     * @return list<array<string, mixed>>
     */
    public static function present(array $groups): array
    {
        foreach ($groups as &$group) {
            $group['stat'] = $group['stat'] === null ? null : str_replace('{value}', (string) $group['stat']['value'], (string) $group['stat']['label']);
        }

        return $groups;
    }

    /**
     * @param  list<array<string, mixed>>  $groups
     * @return list<array<string, mixed>>
     */
    public static function sort(array $groups): array
    {
        usort($groups, function (array $x, array $y): int {
            return [(int) $x['noise'], self::SEVERITY[$x['severity']] ?? 3, -$x['count'], -(int) strtotime((string) str_replace('T', ' ', (string) ($x['last'] ?? '')))]
                <=> [(int) $y['noise'], self::SEVERITY[$y['severity']] ?? 3, -$y['count'], -(int) strtotime((string) str_replace('T', ' ', (string) ($y['last'] ?? '')))];
        });

        return $groups;
    }

    /**
     * A log line that matches no known problem gets its own text as the verdict; a warning stays a warning.
     *
     * @param  array<string, mixed>  $verdict
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function forLog(array $verdict, array $entry): array
    {
        if ($verdict['rule'] === null) {
            $verdict['severity'] = $entry['level'] === 'WARN' ? 'warning' : 'error';
            if ($verdict['exception'] === null) {
                $verdict['verdict'] = Text::oneLine($entry['message'], 200);
                $verdict['title'] = 'Message';
                $verdict['fix'] = null;
            } elseif ($entry['message'] !== '' && ! str_contains($entry['message'], (string) $verdict['exception'])) {
                $verdict['verdict'] = Text::oneLine($entry['message'], 120).' - '.$verdict['verdict'];
            }
        }

        return $verdict;
    }

    /**
     * @param  array<string, mixed>  $verdict
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function group(string $id, array $verdict, array $entry, bool $noise): array
    {
        $sample = Text::head($entry['body'], $noise ? 12 : self::SAMPLE_LINES);

        return [
            'id' => $id,
            'rule' => $verdict['rule'] ?? null,
            'severity' => $verdict['severity'],
            'level' => $entry['level'],
            'title' => $verdict['verdict'] ?? $verdict['title'],
            'fix' => $verdict['fix'] ?? null,
            'culprit' => $verdict['culprit'] ?? null,
            'exception' => $verdict['exception'] ?? null,
            'count' => 0,
            'first' => null,
            'last' => null,
            'stat' => null,
            'noise' => $noise,
            'sample' => strlen($sample) > self::SAMPLE_BYTES ? substr($sample, 0, self::SAMPLE_BYTES) : $sample,
        ];
    }

    /**
     * @param  array<string, mixed>  $group
     * @param  array{0: int, 1: ?string}  $stamp
     */
    private function touch(array &$group, array $stamp): void
    {
        $group['count']++;
        if ($stamp[1] !== null) {
            $group['first'] ??= $stamp;
            $group['last'] = $stamp;
        }
    }

    /**
     * @param  array<string, mixed>  $group
     * @param  array{value: int, label: string}|null  $stat
     */
    private function stat(array &$group, ?array $stat): void
    {
        if ($stat === null) {
            return;
        }

        $group['stat'] = ['value' => max($group['stat']['value'] ?? 0, $stat['value']), 'label' => $stat['label']];
    }

    /**
     * @param  array{0: int, 1: string}|null  $stamp
     */
    private function when(?array $stamp, int $days, ?string $date, string $anchor): ?string
    {
        if ($stamp === null) {
            return null;
        }

        [$day, $time] = $stamp;
        if ($date === null || $anchor === 'none') {
            return $time;
        }

        $when = $date;
        if ($anchor === 'end') {
            for ($i = 0; $i < $days - $day; $i++) {
                $when = Times::previousDay($when);
            }
        } else {
            for ($i = 0; $i < $day; $i++) {
                $when = Times::nextDay($when);
            }
        }

        return $when.'T'.$time;
    }

    private function hasFrames(string $body): bool
    {
        return preg_match('/\n[ \t]*(?:at [^\n(]+\(|[\w$.]+\.[\w$<>]+\([\w$]+\.java:\d+\))/', $body) === 1;
    }

    /**
     * Same problem, same signature: exception, message with the numbers taken out, and the first frames
     * without line numbers.
     *
     * @param  array<string, mixed>  $entry
     * @param  list<array<string, mixed>>  $chains
     */
    private function signature(array $entry, array $chains): string
    {
        $parts = [$entry['level']];

        if ($chains === []) {
            $parts[] = self::normalize($entry['message']);
        } else {
            $root = TraceParser::root($chains[0]);
            $parts[] = (string) $root['exception'];
            $parts[] = self::normalize((string) $root['message']);
            $frames = [];
            foreach ($root['frames'] as $frame) {
                if (Frames::isInteresting((string) $frame['class'])) {
                    $frames[] = $frame['class'].'.'.$frame['method'];
                    if (count($frames) >= 4) {
                        break;
                    }
                }
            }
            if ($frames === []) {
                foreach (array_slice($root['frames'], 0, 3) as $frame) {
                    $frames[] = $frame['class'].'.'.$frame['method'];
                }
            }
            $parts[] = implode(',', $frames);
            $parts[] = self::normalize($entry['message']);
        }

        return substr(sha1(implode('|', $parts)), 0, 16);
    }

    /** Numbers, ids and addresses differ from one occurrence to the next; the rest of the message does not. */
    public static function normalize(string $message): string
    {
        $message = (string) preg_replace('/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i', '<uuid>', $message);
        $message = (string) preg_replace('/\b\d{1,3}(?:\.\d{1,3}){3}(?::\d+)?\b/', '<ip>', $message);
        $message = (string) preg_replace('/\b0x[0-9a-f]+\b|\b[0-9a-f]{12,}\b/i', '<hex>', $message);
        $message = (string) preg_replace('/-?\d+(?:\.\d+)?/', '#', $message);

        return mb_substr(trim((string) preg_replace('/\s+/', ' ', $message)), 0, 200);
    }
}
