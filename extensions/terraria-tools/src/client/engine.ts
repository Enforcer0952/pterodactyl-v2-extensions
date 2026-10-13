/**
 * A small command-suggestion engine for the Terraria server console. Commands are
 * described by syntax strings such as `kick <Player:player>` or
 * `say <Message:message>`. The console ignores case in command names, so matching
 * does too.
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
    /** Text that replaces what is being typed. */
    value: string;
    /** Short explanation shown next to it. */
    detail?: string;
    /** Where the replaced text starts in the input; the last word when not set. */
    start?: number;
    /** Nothing follows this suggestion, so no space is added after it. */
    final?: boolean;
}

export interface Assist {
    suggestions: Suggestion[];
    /** Syntax lines that still fit what was typed, with the current position marked. */
    hints: { parts: { text: string; current: boolean }[]; source: string }[];
    /** Description of the matched command, when there is exactly one. */
    description?: string;
}

/** Argument types that take the rest of the line. */
const GREEDY = new Set(['message', 'any', 'player']);

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

/** Players the console can name. */
export interface PlayerRef {
    name: string;
    online?: boolean;
}

export function findCommand(commands: CommandDef[], name: string): CommandDef | undefined {
    const lower = name.toLowerCase();
    return commands.find(
        (command) => command.name.toLowerCase() === lower || command.aliases.some((alias) => alias.toLowerCase() === lower),
    );
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
                suggestions.push({ value: name, detail: command.description });
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

    // Where the text after the command name starts.
    const lead = input.length - input.replace(/^\s+/, '').length;
    const restStart = lead + typed[0].length + 1;

    for (const syntax of command.syntax) {
        const tokens = tokenize(syntax).slice(1);

        // A player name is the rest of the line, spaces included, with no quotes.
        const first = tokens[0];
        if (first?.kind === 'arg' && first.type === 'player') {
            hints.push(hint(syntax, 1, command.source));
            const rest = input.slice(restStart).toLowerCase();
            for (const player of players) {
                if (player.name.toLowerCase().startsWith(rest) && player.name.toLowerCase() !== rest && !suggestions.has(player.name)) {
                    suggestions.set(player.name, {
                        value: player.name,
                        detail: player.online ? 'online' : undefined,
                        start: restStart,
                        final: true,
                    });
                }
            }
            continue;
        }

        const position = match(tokens, done);
        if (position === null) continue;

        const next = tokens[position.token];
        hints.push(hint(syntax, position.token + 1, command.source));

        if (!next || position.inside) continue;

        for (const candidate of candidates(next)) {
            if (candidate.value.toLowerCase().startsWith(current) && !suggestions.has(candidate.value)) {
                suggestions.set(candidate.value, {
                    value: candidate.value,
                    detail: next.kind === 'arg' ? `<${next.name}>` : undefined,
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
 * current word belongs to and whether the current word is inside a rest-of-line
 * argument. Null when the words do not fit this syntax.
 */
function match(tokens: Token[], done: string[]): { token: number; inside: boolean } | null {
    let token = 0;
    let word = 0;

    while (word < done.length) {
        const current = tokens[token];
        if (!current) return null;

        if (current.kind === 'arg' && GREEDY.has(current.type)) {
            // Everything that follows belongs to this argument.
            return { token, inside: true };
        }

        if (current.kind === 'literal' && done[word].toLowerCase() !== current.value.toLowerCase()) return null;
        if (current.kind === 'choice' && !current.values.some((value) => value.toLowerCase() === done[word].toLowerCase())) return null;

        word++;
        token++;
    }

    return { token, inside: false };
}

function candidates(token: Token): { value: string }[] {
    if (token.kind === 'literal') return [{ value: token.value }];
    if (token.kind === 'choice') return token.values.map((value) => ({ value }));
    return [];
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

/** Replace the text being typed with a suggestion; a space follows unless nothing can follow. */
export function complete(input: string, suggestion: string | Suggestion): string {
    const value = typeof suggestion === 'string' ? suggestion : suggestion.value;
    if (typeof suggestion !== 'string' && suggestion.start !== undefined) {
        return `${input.slice(0, suggestion.start)}${value}${suggestion.final ? '' : ' '}`;
    }

    const lastSpace = input.lastIndexOf(' ');
    const head = lastSpace === -1 ? '' : input.slice(0, lastSpace + 1);
    return `${head}${value} `;
}
