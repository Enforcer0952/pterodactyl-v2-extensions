<?php

namespace TerrariaTools\Support;

/**
 * Finds the file Wings made when it copied a file. Wings has no "copy to" action: it
 * copies next to the original as "<name> copy.<ext>", or "<name> copy <n>.<ext>" when
 * that name is taken. The copy is the one new file with exactly that shape.
 */
final class FileCopy
{
    /**
     * The name Wings gives a copy of this file, as a pattern.
     */
    public static function pattern(string $name): string
    {
        $dot = strrpos($name, '.');
        $extension = $dot === false ? '' : substr($name, $dot);
        $stem = $dot === false ? $name : substr($name, 0, $dot);
        // Wings keeps ".tar.gz" and the like together.
        if ($extension !== '' && str_ends_with($stem, '.tar')) {
            $extension = '.tar'.$extension;
            $stem = substr($stem, 0, -4);
        }

        return '/^'.preg_quote($stem, '/').' copy( \d+)?'.preg_quote($extension, '/').'$/uD';
    }

    /**
     * The copy's name, or null when it cannot be told for sure: nothing new has the
     * shape of a copy, or more than one new file does (something else wrote files at
     * the same time).
     *
     * @param  list<string>  $before  names in the folder before the copy
     * @param  list<string>  $after  names in the folder after the copy
     */
    public static function find(string $name, array $before, array $after): ?string
    {
        $pattern = self::pattern($name);
        $found = [];
        foreach (array_diff($after, $before) as $candidate) {
            if ($candidate !== $name && preg_match($pattern, $candidate) === 1) {
                $found[] = $candidate;
            }
        }

        return count($found) === 1 ? $found[0] : null;
    }
}
