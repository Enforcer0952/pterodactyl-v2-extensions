<?php

namespace ValheimTools\Support;

/**
 * adminlist.txt, bannedlist.txt and permittedlist.txt: one ID per line, lines starting
 * with "//" are comments (the files Valheim creates start with one).
 * Editing keeps comments, blank lines, unknown lines and the line endings as they were.
 * Source: Iron Gate, "A Guide to Dedicated Servers".
 */
final class ListFile
{
    public const array NAMES = [
        'admin' => 'adminlist.txt',
        'banned' => 'bannedlist.txt',
        'permitted' => 'permittedlist.txt',
    ];

    public const array HEADERS = [
        'admin' => '// List admin players ID  ONE per line',
        'banned' => '// List banned players ID  ONE per line',
        'permitted' => '// List permitted players ID ONE per line',
    ];

    /** @var list<string> */
    private array $lines;

    private string $eol;

    private bool $trailingNewline;

    private function __construct(string $contents)
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        $this->eol = str_contains($contents, "\r\n") ? "\r\n" : "\n";
        $this->trailingNewline = $contents === '' || str_ends_with($contents, "\n");
        $lines = $contents === '' ? [] : (preg_split('/\r\n|\n|\r/', $contents) ?: []);
        if ($this->trailingNewline && $lines !== []) {
            array_pop($lines);
        }
        $this->lines = $lines;
    }

    public static function parse(string $contents): self
    {
        return new self($contents);
    }

    /** An empty list that starts with Valheim's own comment line. */
    public static function empty(string $kind): self
    {
        $file = new self('');
        if (isset(self::HEADERS[$kind])) {
            $file->lines = [self::HEADERS[$kind]];
            $file->trailingNewline = true;
        }

        return $file;
    }

    public static function isEntry(string $line): bool
    {
        $trimmed = trim($line);

        return $trimmed !== '' && ! str_starts_with($trimmed, '//');
    }

    /**
     * The IDs in the file, in order, without repeats of the same player.
     *
     * @return list<string>
     */
    public function entries(): array
    {
        $seen = [];
        $ids = [];
        foreach ($this->lines as $line) {
            if (! self::isEntry($line)) {
                continue;
            }
            $id = trim($line);
            $key = PlayerIds::key($id);
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $ids[] = $id;
            }
        }

        return $ids;
    }

    public function has(string $id): bool
    {
        $key = PlayerIds::key($id);
        foreach ($this->lines as $line) {
            if (self::isEntry($line) && PlayerIds::key(trim($line)) === $key) {
                return true;
            }
        }

        return false;
    }

    /** Add an ID unless the same player is already listed in any spelling. True when a line was added. */
    public function add(string $id): bool
    {
        if (! PlayerIds::isValid($id) || $this->has($id)) {
            return false;
        }
        $this->lines[] = $id;
        $this->trailingNewline = true;

        return true;
    }

    /** Remove every line of the same player, in any spelling. Returns how many lines went. */
    public function remove(string $id): int
    {
        $key = PlayerIds::key($id);
        $before = count($this->lines);
        $this->lines = array_values(array_filter(
            $this->lines,
            fn (string $line): bool => ! (self::isEntry($line) && PlayerIds::key(trim($line)) === $key),
        ));

        return $before - count($this->lines);
    }

    /** Collapse repeated lines of the same player to the first. Returns how many lines went. */
    public function dedupe(): int
    {
        $seen = [];
        $before = count($this->lines);
        $this->lines = array_values(array_filter($this->lines, function (string $line) use (&$seen): bool {
            if (! self::isEntry($line)) {
                return true;
            }
            $key = PlayerIds::key(trim($line));
            if (isset($seen[$key])) {
                return false;
            }

            return $seen[$key] = true;
        }));

        return $before - count($this->lines);
    }

    public function render(): string
    {
        if ($this->lines === []) {
            return '';
        }

        return implode($this->eol, $this->lines).($this->trailingNewline ? $this->eol : '');
    }
}
