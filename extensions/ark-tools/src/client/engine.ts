/**
 * A small command-suggestion engine for the ARK RCON console. Commands are
 * described by syntax strings such as `KickPlayer <SteamID:steamid>` or
 * `ServerChatTo <SteamID:steamid> <Message:message>`. ARK ignores case in
 * command names, so matching does too.
 */

export interface CommandDef {
    name: string;
    aliases: string[];
    description: string;
    syntax: string[];
    /** Where the command comes from, shown next to it. */
    source: string;
}

export type Token =
    | { kind: 'literal'; value: string; optional: boolean }
    | { kind: 'choice'; values: string[]; optional: boolean }
    | { kind: 'arg'; name: string; type: string; optional: boolean };

export interface Suggestion {
    /** Text that replaces the word being typed. */
    value: string;
    /** Short explanation shown next to it. */
    detail?: string;
}

export interface Assist {
    suggestions: Suggestion[];
    /** Syntax lines that still fit what was typed, with the current position marked. */
    hints: { parts: { text: string; current: boolean }[]; source: string }[];
    /** Description of the matched command, when there is exactly one. */
    description?: string;
}

/** Argument types that take several words. */
const WIDTH: Record<string, number> = { pos3: 3 };
/** Argument types that take the rest of the line. */
const GREEDY = new Set(['message', 'any']);

const FIXED_VALUES: Record<string, string[]> = {
    bool: ['true', 'false'],
    time: ['06:00', '12:00', '18:00', '00:00'],
    multiplier: ['1', '0.5', '2'],
};

const tokenCache = new Map<string, Token[]>();

/** Split a syntax string into tokens, marking everything inside [...] as optional. */
export function tokenize(syntax: string): Token[] {
    const cached = tokenCache.get(syntax);
    if (cached) return cached;

    const tokens: Token[] = [];
    let depth = 0;
    let i = 0;

    while (i < syntax.length) {
        const char = syntax[i];
        if (char === ' ') {
            i++;
        } else if (char === '[') {
            depth++;
            i++;
        } else if (char === ']') {
            depth = Math.max(0, depth - 1);
            i++;
        } else if (char === '<') {
            const end = syntax.indexOf('>', i);
            const body = syntax.slice(i + 1, end === -1 ? undefined : end);
            const [name, type = 'any'] = body.split(':');
            tokens.push({ kind: 'arg', name, type, optional: depth > 0 });
            i = end === -1 ? syntax.length : end + 1;
        } else if (char === '(') {
            const end = syntax.indexOf(')', i);
            const values = syntax.slice(i + 1, end === -1 ? undefined : end).split('|');
            tokens.push({ kind: 'choice', values, optional: depth > 0 });
            i = end === -1 ? syntax.length : end + 1;
        } else {
            let end = i;
            while (end < syntax.length && !' []<('.includes(syntax[end])) end++;
            tokens.push({ kind: 'literal', value: syntax.slice(i, end), optional: depth > 0 });
            i = end;
        }
    }

    tokenCache.set(syntax, tokens);
    return tokens;
}

/** Split typed text into words; a trailing space starts a new, empty word. */
function words(input: string): string[] {
    const parts = input.replace(/^\s+/, '').split(' ');
    return parts.length === 0 ? [''] : parts;
}

/** Players the console can name: Steam ids for id arguments, names for name arguments. */
export interface PlayerRef {
    name: string;
    steam_id: string;
    online?: boolean;
}

export function findCommand(commands: CommandDef[], name: string): CommandDef | undefined {
    const lower = name.toLowerCase();
    return commands.find((command) => command.name.toLowerCase() === lower || command.aliases.some((alias) => alias.toLowerCase() === lower));
}

/**
 * Work out suggestions and syntax hints for what has been typed so far.
 */
export function assist(input: string, commands: CommandDef[], players: PlayerRef[]): Assist {
    const typed = words(input);

    if (typed.length === 1) {
        const prefix = typed[0].toLowerCase();
        const suggestions: Suggestion[] = [];
        for (const command of commands) {
            const names = [command.name, ...command.aliases].filter((name) => name.toLowerCase().startsWith(prefix));
            for (const name of names) {
                suggestions.push({
                    value: name,
                    detail: command.description,
                });
            }
        }
        suggestions.sort((a, b) => a.value.length - b.value.length || a.value.localeCompare(b.value));
        const exact = findCommand(commands, prefix);
        return {
            suggestions: suggestions.slice(0, 50),
            hints: exact ? exact.syntax.slice(0, 6).map((syntax) => hint(syntax, 0, exact.source)) : [],
            description: exact?.description,
        };
    }

    const command = findCommand(commands, typed[0]);
    if (!command) return { suggestions: [], hints: [] };

    const args = typed.slice(1);
    const current = args[args.length - 1].toLowerCase();
    const done = args.slice(0, -1);
    const suggestions = new Map<string, Suggestion>();
    const hints: Assist['hints'] = [];

    for (const syntax of command.syntax) {
        const tokens = tokenize(syntax).slice(1);
        const position = match(tokens, done);
        if (position === null) continue;

        const next = tokens[position.token];
        hints.push(hint(syntax, position.token + 1, command.source));

        if (!next || position.inside) continue;

        for (const candidate of candidates(next, players)) {
            const matches =
                candidate.value.toLowerCase().startsWith(current) || (candidate.label?.toLowerCase().includes(current) ?? false);
            if (matches && !suggestions.has(candidate.value)) {
                suggestions.set(candidate.value, {
                    value: candidate.value,
                    detail: candidate.label ?? (next.kind === 'arg' ? `<${next.name}>` : undefined),
                });
            }
        }
    }

    return {
        suggestions: [...suggestions.values()].slice(0, 50),
        hints: dedupe(hints).slice(0, 6),
        description: command.description,
    };
}

/**
 * Walk the completed words through a syntax. Returns the index of the token the
 * current word belongs to, the word where that token starts, and whether the
 * current word is in the middle of a multi-word or rest-of-line argument. Null
 * when the words do not fit this syntax.
 */
function match(tokens: Token[], done: string[]): { token: number; start: number; inside: boolean } | null {
    let token = 0;
    let word = 0;

    while (word < done.length) {
        const current = tokens[token];
        if (!current) return null;

        if (current.kind === 'arg' && GREEDY.has(current.type)) {
            // Everything that follows belongs to this argument.
            return { token, start: word, inside: true };
        }

        const width = current.kind === 'arg' ? (WIDTH[current.type] ?? 1) : 1;
        if (current.kind === 'literal' && done[word].toLowerCase() !== current.value.toLowerCase()) return null;
        if (current.kind === 'choice' && !current.values.some((value) => value.toLowerCase() === done[word].toLowerCase())) return null;

        if (word + width > done.length) {
            // The current word is the 2nd or 3rd part of a coordinate.
            return { token, start: word, inside: true };
        }

        word += width;
        token++;
    }

    return { token, start: word, inside: false };
}

function candidates(token: Token, players: PlayerRef[]): { value: string; label?: string }[] {
    if (token.kind === 'literal') return [{ value: token.value }];
    if (token.kind === 'choice') return token.values.map((value) => ({ value }));
    if (token.type === 'steamid') {
        return players.map((player) => ({ value: player.steam_id, label: `${player.name}${player.online ? ' (online)' : ''}` }));
    }
    if (token.type === 'playername') {
        // ARK wants names with spaces in double quotes.
        return players.map((player) => ({
            value: /\s/.test(player.name) ? `"${player.name}"` : player.name,
            label: player.online ? 'online' : undefined,
        }));
    }
    return (FIXED_VALUES[token.type] ?? []).map((value) => ({ value }));
}

function hint(syntax: string, currentIndex: number, source: string) {
    const tokens = tokenize(syntax);
    const parts = tokens.map((token, index) => ({ text: show(token), current: index === currentIndex }));
    return { parts, source };
}

function show(token: Token): string {
    const text = token.kind === 'literal' ? token.value : token.kind === 'choice' ? token.values.join('|') : `<${token.name}>`;
    return token.optional ? `[${text}]` : text;
}

function dedupe(hints: Assist['hints']): Assist['hints'] {
    const seen = new Set<string>();
    return hints.filter((item) => {
        const key = item.parts.map((part) => part.text).join(' ') + item.parts.findIndex((part) => part.current);
        if (seen.has(key)) return false;
        seen.add(key);
        return true;
    });
}

/** Replace the word being typed with a suggestion and add a space after it. */
export function complete(input: string, value: string): string {
    const lastSpace = input.lastIndexOf(' ');
    const head = lastSpace === -1 ? '' : input.slice(0, lastSpace + 1);
    return `${head}${value} `;
}
