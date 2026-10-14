<?php

namespace McCrash\Support;

/**
 * Finds Java stack traces in any text: crash reports, console logs (with or without
 * the "[12:00:00 ERROR]:" prefix), hs_err files and server thread dumps.
 *
 * A chain is the outer exception plus its "Caused by" exceptions:
 * {exception, message, frames, causes: [{exception, message, frames}], line}
 * A frame is {class, method, file, line, jar}; "jar" is only set when the trace itself names the jar
 * ("Foo-1.2.jar//com.foo.Bar", "~[Foo-1.2.jar:?]").
 */
final class TraceParser
{
    private const MAX_CHAINS = 300;

    private const MAX_FRAMES = 150;

    private const MAX_CAUSES = 12;

    private const PREFIX = '/^(?:\[\d{1,2}[A-Za-z]{3}\d{4} [\d:.]+\]|\[\d{2}:\d{2}:\d{2}[^\]]*\]|\d{2}:\d{2}:\d{2} \[[A-Z]+\])(?: \[[^\]]*\])*:? ?/';

    private const FRAME = '/^\s*(?:at\s+)?(?<prefix>(?:[^\s(\/]*\/)*)(?<cm>[A-Za-z_$][\w$.<>\-]*)\((?<loc>[^)]*)\)(?<tail>.*)$/';

    private const HEADER = '/^\s*(?<kind>Caused by: |Suppressed: )?(?:Exception in thread "[^"]*" )?(?<exc>(?:[A-Za-z_$][\w$]*\.)+[A-Z$][\w$]*|[A-Z][\w$]*(?:Exception|Error|Throwable))(?::\s?(?<msg>.*))?$/';

    private const THREAD = '/^\s*(?:Current Thread: .*|Thread\[.*\]|"[^"]+" #\d+.*)$/';

    private const HS_FRAME = '/^[jJ]\s+(?:\d+\s+c\d\s+)?(?<cm>[A-Za-z_$][\w$.<>\-]*)\(/';

    /**
     * @return list<array<string, mixed>>
     */
    public static function parse(string $text): array
    {
        $chains = [];
        $current = null;   // index of the chain being filled
        $target = null;    // [chain index, cause index or -1]
        $pending = null;   // exception header waiting for its first frame
        $throwaway = false;
        $inHsFrames = false;
        $hs = null;

        $lines = explode("\n", $text);
        foreach ($lines as $number => $raw) {
            if (count($chains) >= self::MAX_CHAINS) {
                break;
            }

            $line = $raw !== '' && ($raw[0] === '[' || ctype_digit($raw[0])) ? (string) preg_replace(self::PREFIX, '', $raw) : $raw;

            if (preg_match('/^Java frames:/', $line) === 1) {
                $inHsFrames = true;
                $hs = null;

                continue;
            }
            if ($inHsFrames) {
                if (preg_match(self::HS_FRAME, $line, $m) === 1) {
                    $frame = self::splitClassMethod($m['cm']);
                    if ($frame !== null) {
                        $hs ??= count($chains);
                        $chains[$hs] ??= ['exception' => 'JVM crash', 'message' => '', 'frames' => [], 'causes' => [], 'line' => $number];
                        if (count($chains[$hs]['frames']) < self::MAX_FRAMES) {
                            $chains[$hs]['frames'][] = $frame + ['file' => '', 'line' => 0, 'jar' => null];
                        }
                    }

                    continue;
                }
                if (trim($line) === '' || str_starts_with($line, '#') || str_starts_with($line, 'Lock stack') || str_starts_with($line, 'siginfo')) {
                    $inHsFrames = false;
                }
            }

            if (preg_match(self::FRAME, $line, $m) === 1 && self::looksLikeFrame($line, $m)) {
                if ($pending !== null) {
                    [$current, $target] = self::promote($chains, $pending);
                    $pending = null;
                    $throwaway = false;
                }
                if ($throwaway || $target === null) {
                    continue;
                }

                $frame = self::frame($m);
                if ($frame !== null) {
                    self::append($chains, $target, $frame);
                }

                continue;
            }

            if (preg_match('/^\s*\.\.\. \d+ (more|common frames omitted)/', $line) === 1) {
                continue;
            }

            if (preg_match(self::HEADER, $line, $m) === 1) {
                $kind = $m['kind'] ?? '';
                $header = ['exception' => $m['exc'], 'message' => rtrim($m['msg'] ?? ''), 'line' => $number];

                if ($kind === 'Suppressed: ') {
                    $pending = null;
                    $throwaway = true;
                    $target = null;

                    continue;
                }
                if ($kind === 'Caused by: ' && $current !== null) {
                    $throwaway = false;
                    if (count($chains[$current]['causes']) < self::MAX_CAUSES) {
                        $chains[$current]['causes'][] = ['exception' => $header['exception'], 'message' => $header['message'], 'frames' => []];
                        $target = [$current, count($chains[$current]['causes']) - 1];
                    } else {
                        $target = null;
                    }
                    $pending = null;

                    continue;
                }

                $pending = $header;
                $throwaway = false;

                continue;
            }

            if (preg_match(self::THREAD, $line) === 1) {
                $pending = ['exception' => 'Thread', 'message' => trim($line), 'line' => $number];
                $throwaway = false;

                continue;
            }

            if ($pending !== null) {
                // The message continues on the next lines until the first "at" line.
                if (trim($line) === '' || substr_count($pending['message'], "\n") >= 4) {
                    $pending = null;
                } else {
                    $pending['message'] .= "\n".trim($line);
                }

                continue;
            }

            if (trim($line) === '') {
                $target = null;
                $throwaway = false;
            }
        }

        return array_values($chains);
    }

    /** The deepest exception of a chain (the last "Caused by"), or the chain itself. */
    public static function root(array $chain): array
    {
        $causes = $chain['causes'] ?? [];

        return $causes === [] ? $chain : $causes[count($causes) - 1];
    }

    /**
     * Exceptions of a chain, root cause first: the order to look through them for the culprit.
     *
     * @return list<array<string, mixed>>
     */
    public static function rootFirst(array $chain): array
    {
        $nodes = [$chain];
        foreach ($chain['causes'] ?? [] as $cause) {
            $nodes[] = $cause;
        }

        return array_reverse($nodes);
    }

    /**
     * @param  array<int, array<string, mixed>>  $chains
     * @param  array<string, mixed>  $header
     * @return array{0: int, 1: array{0: int, 1: int}}
     */
    private static function promote(array &$chains, array $header): array
    {
        $chains[] = ['exception' => $header['exception'], 'message' => $header['message'], 'frames' => [], 'causes' => [], 'line' => $header['line']];
        $index = count($chains) - 1;

        return [$index, [$index, -1]];
    }

    /**
     * @param  array<int, array<string, mixed>>  $chains
     * @param  array{0: int, 1: int}  $target
     * @param  array<string, mixed>  $frame
     */
    private static function append(array &$chains, array $target, array $frame): void
    {
        [$chain, $cause] = $target;
        if ($cause === -1) {
            if (count($chains[$chain]['frames']) < self::MAX_FRAMES) {
                $chains[$chain]['frames'][] = $frame;
            }

            return;
        }

        if (count($chains[$chain]['causes'][$cause]['frames']) < self::MAX_FRAMES) {
            $chains[$chain]['causes'][$cause]['frames'][] = $frame;
        }
    }

    /**
     * A bare "pkg.Class.method(File.java:1)" line only counts with a source location, so ordinary
     * prose with parentheses is not taken for a frame.
     *
     * @param  array<string, string>  $m
     */
    private static function looksLikeFrame(string $line, array $m): bool
    {
        if (preg_match('/^\s*at\s/', $line) === 1) {
            return str_contains($m['cm'], '.');
        }

        return str_contains($m['cm'], '.') && preg_match('/^(?:[\w$]+\.(?:java|kt|scala|groovy):\d+|Native Method|Unknown Source)$/', $m['loc']) === 1
            && preg_match('/^\s+/', $line) === 1;
    }

    /**
     * @param  array<string, string>  $m
     * @return array<string, mixed>|null
     */
    private static function frame(array $m): ?array
    {
        $split = self::splitClassMethod($m['cm']);
        if ($split === null) {
            return null;
        }

        $file = '';
        $line = 0;
        if (preg_match('/^(?<file>[^:]+)(?::(?<line>\d+))?$/', $m['loc'], $loc) === 1) {
            $file = $loc['file'];
            $line = (int) ($loc['line'] ?? 0);
        }

        return $split + ['file' => $file, 'line' => $line, 'jar' => self::jarOf($m['prefix'], $m['tail'])];
    }

    /**
     * @return array{class: string, method: string}|null
     */
    private static function splitClassMethod(string $cm): ?array
    {
        $pos = strrpos($cm, '.');
        if ($pos === false || $pos === 0) {
            return null;
        }

        return ['class' => substr($cm, 0, $pos), 'method' => substr($cm, $pos + 1)];
    }

    /** The jar a frame names itself: Paper's "Foo.jar//class" and Log4j's "~[Foo.jar:?]". */
    private static function jarOf(string $prefix, string $tail): ?string
    {
        foreach (explode('/', $prefix) as $part) {
            if (preg_match('/^([\w.+\-]+\.jar)$/i', $part, $m) === 1) {
                return $m[1];
            }
        }

        if (preg_match('/\[+([\w.+\-]+\.jar)(?:%23\d+)?(?:!\/)?(?::[^\]]*)?\]/i', $tail, $m) === 1) {
            return $m[1];
        }

        return null;
    }
}
