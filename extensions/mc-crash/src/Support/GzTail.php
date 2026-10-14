<?php

namespace McCrash\Support;

/** Unpacks a gzip log in small steps and keeps only its end, so a big rotated log never sits in memory whole. */
final class GzTail
{
    /** Input is fed in pieces of this size, which also bounds how much one step can expand to. */
    private const STEP = 8192;

    /**
     * @param  int  $keep  bytes of the end to keep
     * @param  int  $limit  most bytes to unpack; a log that is bigger is cut there ("truncated")
     * @return array{text: string, cut: bool, truncated: bool, error: ?string}
     */
    public static function tail(string $gz, int $keep, int $limit = 256 * 1024 * 1024): array
    {
        $result = ['text' => '', 'cut' => false, 'truncated' => false, 'error' => null];

        if (strlen($gz) < 18 || ! str_starts_with($gz, "\x1f\x8b")) {
            $result['error'] = 'This is not a gzip file.';

            return $result;
        }

        $context = @inflate_init(ZLIB_ENCODING_GZIP);
        if ($context === false) {
            $result['error'] = 'Could not start unpacking.';

            return $result;
        }

        $buffer = '';
        $total = 0;
        $length = strlen($gz);

        for ($offset = 0; $offset < $length; $offset += self::STEP) {
            $out = @inflate_add($context, substr($gz, $offset, self::STEP), ZLIB_NO_FLUSH);
            if ($out === false) {
                // Whatever was unpacked before the damage is still worth reading.
                $result['error'] = 'The file is damaged.';
                $result['truncated'] = true;

                break;
            }

            $buffer .= $out;
            $total += strlen($out);

            if (strlen($buffer) > $keep * 2) {
                $buffer = substr($buffer, -$keep);
                $result['cut'] = true;
            }

            if ($total > $limit) {
                $result['truncated'] = true;

                break;
            }

            if (inflate_get_status($context) === ZLIB_STREAM_END) {
                break;
            }
        }

        if ($result['error'] === null && ! $result['truncated'] && inflate_get_status($context) !== ZLIB_STREAM_END) {
            $result['error'] = 'The file ends early.';
            $result['truncated'] = true;
        }

        if (strlen($buffer) > $keep) {
            $buffer = substr($buffer, -$keep);
            $result['cut'] = true;
        }

        if ($result['cut']) {
            $newline = strpos($buffer, "\n");
            $buffer = $newline === false ? $buffer : substr($buffer, $newline + 1);
        }

        $result['text'] = $buffer;

        return $result;
    }
}
