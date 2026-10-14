/**
 * Joins and leaves in Valheim's console output. Mirrors the panel side's LogParser, but only
 * to know when to ask for a fresh player list: the panel side does the real parsing.
 */

/** One console line without colour codes, control characters and the leading timestamp. */
export function cleanLine(line: string): string {
    // eslint-disable-next-line no-control-regex
    const withoutColours = line.replace(/\u001b\[[0-9;?]*[A-Za-z]/g, '');
    const text = withoutColours.replace(/[\p{Cc}\p{Zl}\p{Zp}]+/gu, ' ').trim();
    return text.replace(/^\d{2}\/\d{2}\/\d{4} \d{2}:\d{2}:\d{2}:\s*/, '');
}

export type Presence = 'join' | 'leave' | 'start';

export function presence(line: string): Presence | null {
    const text = cleanLine(line);
    if (/^Got character ZDOID from .+ : -?\d+:\d+$/.test(text)) {
        return /: 0:0$/.test(text) ? null : 'join';
    }
    if (/^Destroying abandoned non persistent zdo \S+ owner -?\d+/.test(text)) return 'leave';
    if (text.includes('Game server connected')) return 'start';
    return null;
}
