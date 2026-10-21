/**
 * A small command-suggestion engine for Minecraft console input. Commands are
 * described by syntax strings such as `ban <targets:players> [<reason:message>]`
 * or `gamemode (survival|creative|adventure|spectator) [<target:players>]`.
 */

export interface CommandDef {
    name: string;
    aliases: string[];
    description: string;
    syntax: string[];
    /** Where the command comes from: "Minecraft" or a plugin name. */
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

/** Argument types that take several words in game. */
const WIDTH: Record<string, number> = { pos3: 3, pos2: 2, rotation: 2 };
/** Argument types that take the rest of the line. */
const GREEDY = new Set(['message', 'command', 'json', 'nbt', 'any']);
const PLAYER_TYPES = new Set(['players', 'player', 'entities', 'entity']);
const SELECTORS = ['@a', '@p', '@r', '@s', '@e', '@n'];

const FIXED_VALUES: Record<string, string[]> = {
    bool: ['true', 'false'],
    gamemode: ['survival', 'creative', 'adventure', 'spectator'],
    difficulty: ['peaceful', 'easy', 'normal', 'hard'],
    dimension: ['minecraft:overworld', 'minecraft:the_nether', 'minecraft:the_end'],
    color: [
        'black',
        'dark_blue',
        'dark_green',
        'dark_aqua',
        'dark_red',
        'dark_purple',
        'gold',
        'gray',
        'dark_gray',
        'blue',
        'green',
        'aqua',
        'red',
        'light_purple',
        'yellow',
        'white',
        'reset',
    ],
    pos3: ['~ ~ ~'],
    pos2: ['~ ~'],
    rotation: ['~ ~'],
    time: ['1d', '100t', '10s'],
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

/**
 * Turn a plugin.yml usage line such as "/<command> <player> [reason]" into a syntax
 * string. Lines that do not look like syntax fall back to the bare command.
 */
export function usageToSyntax(name: string, usage: string | null): string[] {
    if (!usage) return [name];

    return usage
        .split(/\r?\n/)
        .map((line) =>
            line
                .replace(/§[0-9a-fk-or]/gi, '')
                .replace(/^\s*usage:\s*/i, '')
                .trim(),
        )
        .filter((line) => /^\/?(<command>|\S+)/.test(line))
        .map((line) => {
            const parts = groups(line.replace(/^\//, ''));
            parts[0] = name;
            return parts.map((part, index) => (index === 0 ? part : usageWord(part))).join(' ');
        })
        .slice(0, 8);
}

/** Split a usage line into words, keeping "<player name>" and "[a|b c]" together. */
function groups(line: string): string[] {
    const parts: string[] = [];
    let current = '';
    let depth = 0;
    for (const char of line) {
        if ('<[('.includes(char)) depth++;
        if ('>])'.includes(char)) depth = Math.max(0, depth - 1);
        if (/\s/.test(char) && depth === 0) {
            if (current) parts.push(current);
            current = '';
        } else {
            current += char;
        }
    }
    if (current) parts.push(current);
    return parts;
}

/** One usage word as syntax. Anything unreadable becomes a single string, never "rest of line". */
function usageWord(word: string): string {
    const optional = /^\[(.*)\]$/.exec(word);
    const inner = optional ? optional[1].trim() : word;
    const wrap = (text: string) => (optional ? `[${text}]` : text);

    const arg = /^<([^>]+)>$/.exec(inner);
    const body = arg ? arg[1] : inner;

    // "<add|remove>", "[on|off]" and bare "a|b" are fixed choices.
    if (body.includes('|')) {
        const values = body
            .split('|')
            .map((value) => value.trim().replace(/[<>[\]()]/g, ''))
            .filter((value) => /^[\w.-]+$/.test(value));
        if (values.length > 1) return wrap(`(${values.join('|')})`);
    }

    if (!arg && !optional && /^[\w-]+$/.test(word)) return word;

    const name =
        body
            .replace(/[<>[\]():]/g, '')
            .trim()
            .replace(/\s+/g, '_') || 'value';
    return wrap(`<${name}:${guessType(name)}>`);
}

function guessType(name: string): string {
    return /player|target|user|name/i.test(name) ? 'players' : 'string';
}

/**
 * Split typed text into words. Runs of spaces count as one, a trailing space starts
 * a new empty word, and "quoted text" stays one word.
 */
export function words(input: string): string[] {
    const text = input.replace(/^\s*\//, '').replace(/^\s+/, '');
    const parts: string[] = [];
    let current = '';
    let quoted = false;
    for (const char of text) {
        if (char === '"') {
            quoted = !quoted;
            current += char;
        } else if (char === ' ' && !quoted) {
            if (current !== '') parts.push(current);
            current = '';
        } else {
            current += char;
        }
    }
    parts.push(current);
    return parts;
}

export function findCommand(commands: CommandDef[], name: string): CommandDef | undefined {
    const lower = name.toLowerCase().replace(/^minecraft:/, '');
    return commands.find((command) => command.name === lower || command.aliases.includes(lower));
}

/**
 * Work out suggestions and syntax hints for what has been typed so far.
 */
/** How many commands deep "execute ... run execute ... run" is followed. */
const MAX_NESTING = 8;

export function assist(input: string, commands: CommandDef[], players: string[], depth = 0): Assist {
    const typed = words(input);

    if (typed.length === 1) {
        const prefix = typed[0].toLowerCase();
        const suggestions: Suggestion[] = [];
        for (const command of commands) {
            const names = [command.name, ...command.aliases].filter((name) => name.startsWith(prefix));
            for (const name of names) {
                suggestions.push({
                    value: name,
                    detail: command.source === 'Minecraft' ? command.description : `${command.source}: ${command.description}`,
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

        if (next?.kind === 'arg' && next.type === 'command') {
            // Commands like "execute ... run <command>" continue with another command.
            if (depth >= MAX_NESTING) continue;
            const rest = args.slice(position.start).join(' ');
            const nested = assist(rest, commands, players, depth + 1);
            nested.suggestions.forEach((suggestion) => suggestions.set(suggestion.value, suggestion));
            if (nested.hints.length) hints.push(...nested.hints);
            // One nested answer is enough; other "run" syntaxes would give the same one.
            break;
        }

        if (!next || position.inside) continue;

        for (const value of candidates(next, players)) {
            if (value.toLowerCase().startsWith(current) && !suggestions.has(value)) {
                suggestions.set(value, { value, detail: next.kind === 'arg' ? `<${next.name}>` : undefined });
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

function candidates(token: Token, players: string[]): string[] {
    if (token.kind === 'literal') return [token.value];
    if (token.kind === 'choice') return token.values;
    if (PLAYER_TYPES.has(token.type)) return [...players, ...SELECTORS];
    return FIXED_VALUES[token.type] ?? [];
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
    const typed = words(input);
    const current = typed[typed.length - 1];
    const head = input.slice(0, input.length - current.length);
    return `${head}${value} `;
}
