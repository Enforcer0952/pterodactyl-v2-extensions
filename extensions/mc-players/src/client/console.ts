const NAME = '\\.?[A-Za-z0-9_]{1,16}';

/** Strip ANSI colours, Minecraft colour codes and "[12:00:00 INFO]: " style prefixes. */
export function cleanLine(line: string): string {
    return line
        .replace(/\u001b\[[0-9;?]*[ -/]*[@-~]/g, '')
        .replace(/§[0-9a-fk-or]/gi, '')
        .trim()
        .replace(/^(?:\[[^\]]*\]\s*)+:?\s*/, '')
        .trim();
}

export type ConsoleChange = { type: 'join'; name: string } | { type: 'leave'; name: string } | { type: 'lists' };

const JOIN = new RegExp(`^(${NAME}) joined the game`);
const LEAVE = new RegExp(`^(${NAME}) (?:left the game|lost connection)`);
// Answers to ban, pardon, whitelist and op commands typed in the console.
const LISTS =
    /^(?:Banned |Unbanned |Banned IP |Unbanned IP |Added .+ to the whitelist|Removed .+ from the whitelist|Whitelist is now turned |Made .+ (?:a|no longer a) server operator)/;

/** What a console line means for the players card, if anything. */
export function parseConsoleLine(raw: string): ConsoleChange | null {
    const line = cleanLine(raw);

    const join = JOIN.exec(line);
    if (join) return { type: 'join', name: join[1] };

    const leave = LEAVE.exec(line);
    if (leave) return { type: 'leave', name: leave[1] };

    return LISTS.test(line) ? { type: 'lists' } : null;
}

export const isValidName = (value: string) => new RegExp(`^${NAME}$`).test(value);

/** A route parameter decoded safely: a broken escape such as "%E0%A4%A" gives an empty name. */
export function decodeParam(value: string | undefined): string {
    if (!value) return '';
    try {
        return decodeURIComponent(value);
    } catch {
        return '';
    }
}

/**
 * What to tell the user after a player command. A running server that printed no
 * answer may not have done anything, so that is a warning and not a success.
 */
export function replyNotice(
    reply: string | null | undefined,
    state: string,
    fallback: string,
): { type: 'success' | 'warning'; message: string } {
    if (reply) return { type: 'success', message: reply };
    if (state === 'running') {
        return { type: 'warning', message: 'The server sent no reply, so it is unclear whether this worked. Check the console.' };
    }
    return { type: 'success', message: fallback };
}
