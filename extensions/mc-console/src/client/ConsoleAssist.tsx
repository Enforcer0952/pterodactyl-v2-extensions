import { Fragment, useEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { useQuery } from '@tanstack/react-query';
import { http, useServerPermission, type SdkServer } from '@pterodactyl/sdk';
import { assist, complete, usageToSyntax, type Assist, type CommandDef } from './engine';

/** The Panel's console input; its aria-label is the most stable way to find it. */
const INPUT_SELECTOR = 'input[aria-label="Console command input."]';
const VISIBLE = 8;

interface ServerCommands {
    plugins: { name: string; aliases: string[]; description: string; usage: string | null; plugin: string }[];
    pending: number;
    players: string[];
}

interface VanillaCommand {
    name: string;
    aliases: string[];
    description: string;
    syntax: string[];
}

/** Only Minecraft Java servers that are not proxies get suggestions. */
export default function ConsoleAssistSlot({ data: server }: { data: SdkServer }) {
    const tags = (server.attributes.egg_tags ?? []).map((tag) => tag.toLowerCase());
    const features = (server.attributes.egg_features ?? []).map((feature) => feature.toLowerCase());
    const canSend = useServerPermission('control.console');
    // Plugin commands and player names come from the server's files, so they need
    // their own permission; vanilla commands only need console access.
    const canSeeServerData = useServerPermission('ext.mc-console.suggestions');
    const java = tags.includes('minecraft') || features.includes('eula');

    if (!canSend || !java || tags.includes('bedrock') || tags.includes('proxy')) {
        return null;
    }

    return <ConsoleAssist server={server} withServerData={canSeeServerData} />;
}

export function ConsoleAssist({ server, withServerData = true }: { server: SdkServer; withServerData?: boolean }) {
    const identifier = server.attributes.identifier;
    const input = useConsoleInput();
    const [text, setText] = useState('');
    const [focused, setFocused] = useState(false);
    const [dismissed, setDismissed] = useState(false);
    const [selected, setSelected] = useState(0);

    const vanilla = useQuery({
        queryKey: ['mc-console', 'vanilla'],
        queryFn: async () => (await import('./data/vanilla.json')).default as VanillaCommand[],
        staleTime: Infinity,
    });

    const serverCommands = useQuery({
        queryKey: ['mc-console', identifier, 'commands'],
        queryFn: async () => (await http.get<ServerCommands>(`/api/client/servers/${identifier}/extensions/mc-console/commands`)).data,
        staleTime: 5 * 60_000,
        enabled: withServerData,
        retry: false,
        // Plugins are read in batches; keep asking until all of them are done.
        refetchInterval: (query) => ((query.state.data?.pending ?? 0) > 0 ? 3000 : false),
    });

    const commands = useMemo<CommandDef[]>(() => {
        const list: CommandDef[] = (vanilla.data ?? []).map((command) => ({ ...command, source: 'Minecraft' }));
        const known = new Set(list.flatMap((command) => [command.name, ...command.aliases]));
        const byName = new Map(list.flatMap((command) => [command.name, ...command.aliases].map((name) => [name, command] as const)));
        for (const command of serverCommands.data?.plugins ?? []) {
            if (known.has(command.name)) {
                // Plugins such as EssentialsX replace vanilla commands; offer their syntax too.
                const existing = byName.get(command.name);
                if (existing && command.usage) {
                    existing.syntax = [...existing.syntax, ...usageToSyntax(command.name, command.usage)];
                    existing.source = existing.source === 'Minecraft' ? `Minecraft / ${command.plugin}` : existing.source;
                }
                continue;
            }
            known.add(command.name);
            list.push({
                name: command.name,
                aliases: command.aliases.filter((alias) => !known.has(alias)),
                description: command.description,
                syntax: usageToSyntax(command.name, command.usage),
                source: command.plugin,
            });
        }
        return list;
    }, [vanilla.data, serverCommands.data]);

    const result: Assist = useMemo(
        () => (text.trim() === '' ? { suggestions: [], hints: [] } : assist(text, commands, serverCommands.data?.players ?? [])),
        [text, commands, serverCommands.data],
    );

    const open = focused && !dismissed && (result.suggestions.length > 0 || result.hints.length > 0);
    const index = Math.min(selected, Math.max(0, result.suggestions.length - 1));

    // Keep the latest state reachable from the native listeners below.
    const state = useRef({ open, index, result, text });
    state.current = { open, index, result, text };

    useEffect(() => setSelected(0), [text]);

    useEffect(() => {
        if (!input) return;

        const sync = () => {
            setText(input.value);
            setDismissed(false);
        };
        const accept = (value: string) => {
            input.value = complete(input.value, value);
            input.setSelectionRange(input.value.length, input.value.length);
            // Let the panel and other listeners know the value changed.
            input.dispatchEvent(new Event('input', { bubbles: true }));
            setText(input.value);
        };

        // Runs on the input itself, before the Panel's own key handling, so Tab and
        // the arrow keys can be taken over while suggestions are showing.
        const onKeyDown = (event: KeyboardEvent) => {
            const { open: isOpen, index: current, result: assistResult } = state.current;
            const suggestions = assistResult.suggestions;

            // Leave typing with an input method and modifier shortcuts alone.
            if (event.isComposing || event.ctrlKey || event.altKey || event.metaKey) return;

            if (event.key === 'Tab') {
                // Only while the list is showing, so Tab still moves focus after Esc.
                if (isOpen && suggestions.length > 0 && !event.shiftKey) {
                    event.preventDefault();
                    event.stopPropagation();
                    accept(suggestions[current]?.value ?? suggestions[0].value);
                }
                return;
            }

            if (!isOpen || suggestions.length === 0) return;

            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                event.stopPropagation();
                const step = event.key === 'ArrowDown' ? 1 : -1;
                setSelected((value) => (value + step + suggestions.length) % suggestions.length);
            } else if (event.key === 'Escape') {
                event.stopPropagation();
                setDismissed(true);
            }
        };

        const onKeyUp = (event: KeyboardEvent) => {
            // The Panel changes the value on Enter (clears it) and on history recall.
            if (event.key === 'Enter' || event.key === 'ArrowUp' || event.key === 'ArrowDown') {
                setText(input.value);
            }
        };

        const onFocus = () => setFocused(true);
        const onBlur = () => setFocused(false);

        input.addEventListener('input', sync);
        input.addEventListener('keydown', onKeyDown);
        input.addEventListener('keyup', onKeyUp);
        input.addEventListener('focus', onFocus);
        input.addEventListener('blur', onBlur);
        setFocused(document.activeElement === input);
        setText(input.value);

        acceptRef.current = accept;

        return () => {
            input.removeEventListener('input', sync);
            input.removeEventListener('keydown', onKeyDown);
            input.removeEventListener('keyup', onKeyUp);
            input.removeEventListener('focus', onFocus);
            input.removeEventListener('blur', onBlur);
        };
    }, [input]);

    const acceptRef = useRef<(value: string) => void>(() => undefined);
    const listRef = useRef<HTMLUListElement>(null);

    useEffect(() => {
        listRef.current?.querySelector('[aria-selected="true"]')?.scrollIntoView?.({ block: 'nearest' });
    }, [index, open]);

    // Combobox wiring on the panel's input, for screen readers.
    useEffect(() => {
        if (!input) return;
        const showing = open && result.suggestions.length > 0;
        input.setAttribute('aria-expanded', String(showing));
        input.setAttribute('aria-controls', 'mccon-suggestions');
        if (showing) input.setAttribute('aria-activedescendant', `mccon-option-${index}`);
        else input.removeAttribute('aria-activedescendant');
    }, [input, open, index, result.suggestions.length]);

    if (!input?.parentElement || !open) return null;

    return createPortal(
        <div
            className={
                'mccon:absolute mccon:bottom-full mccon:left-0 mccon:z-30 mccon:mb-1 mccon:flex mccon:w-full mccon:max-w-3xl mccon:flex-col mccon:gap-1 mccon:font-mono mccon:text-xs'
            }
        >
            {result.suggestions.length > 0 && (
                <ul
                    ref={listRef}
                    id={'mccon-suggestions'}
                    role={'listbox'}
                    aria-label={'Command suggestions'}
                    className={
                        'mccon:overflow-y-auto mccon:rounded-sm mccon:border mccon:border-border mccon:bg-popover mccon:py-1 mccon:shadow-lg'
                    }
                    style={{ maxHeight: `${VISIBLE * 1.75}rem` }}
                >
                    {result.suggestions.map((suggestion, position) => (
                        <li
                            key={suggestion.value}
                            id={`mccon-option-${position}`}
                            role={'option'}
                            aria-selected={position === index}
                            onMouseDown={(event) => {
                                // Keep focus in the console input.
                                event.preventDefault();
                                acceptRef.current(suggestion.value);
                            }}
                            onMouseEnter={() => setSelected(position)}
                            className={`mccon:flex mccon:cursor-pointer mccon:items-baseline mccon:gap-3 mccon:px-2 mccon:py-1 ${
                                position === index ? 'mccon:bg-accent mccon:text-accent-foreground' : 'mccon:text-foreground'
                            }`}
                        >
                            <span className={'mccon:shrink-0'}>{suggestion.value}</span>
                            {suggestion.detail && (
                                <span
                                    className={`mccon:truncate ${position === index ? 'mccon:opacity-80' : 'mccon:text-muted-foreground'}`}
                                >
                                    {suggestion.detail}
                                </span>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            {result.hints.length > 0 && (
                <div
                    className={
                        'mccon:rounded-sm mccon:border mccon:border-border mccon:bg-popover mccon:px-2 mccon:py-1.5 mccon:text-muted-foreground mccon:shadow-lg'
                    }
                >
                    {result.hints.slice(0, 6).map((line, position) => (
                        <div key={position} className={'mccon:truncate'}>
                            {line.parts.map((part, partIndex) => (
                                <Fragment key={partIndex}>
                                    {partIndex > 0 ? ' ' : '/'}
                                    <span
                                        className={part.current ? 'mccon:font-semibold mccon:text-foreground mccon:underline' : undefined}
                                    >
                                        {part.text}
                                    </span>
                                </Fragment>
                            ))}
                            {line.source !== 'Minecraft' && <span className={'mccon:ml-2 mccon:opacity-70'}>({line.source})</span>}
                        </div>
                    ))}
                    {result.description && (
                        <div className={'mccon:mt-1 mccon:font-sans mccon:text-foreground/80'}>{result.description}</div>
                    )}
                    <div className={'mccon:mt-1 mccon:font-sans mccon:text-[11px]'}>
                        Tab to complete · ↑↓ to choose · Esc to hide
                        {withServerData &&
                            (serverCommands.data?.pending ?? 0) > 0 &&
                            ` · reading plugins (${serverCommands.data?.pending} left)`}
                        {withServerData && serverCommands.isError && ' · plugin commands unavailable'}
                    </div>
                </div>
            )}
        </div>,
        input.parentElement,
    );
}

/** The Panel's console input element, followed as the console remounts. */
function useConsoleInput(): HTMLInputElement | null {
    const [input, setInput] = useState<HTMLInputElement | null>(() => document.querySelector<HTMLInputElement>(INPUT_SELECTOR));

    useEffect(() => {
        const find = () => {
            const element = document.querySelector<HTMLInputElement>(INPUT_SELECTOR);
            setInput((current) => (current === element ? current : element));
        };
        find();
        // Every console line is a DOM change, so the check is throttled to one per frame.
        let frame = 0;
        const observer = new MutationObserver(() => {
            if (frame !== 0) return;
            frame = requestAnimationFrame(() => {
                frame = 0;
                find();
            });
        });
        observer.observe(document.body, { childList: true, subtree: true });
        return () => {
            observer.disconnect();
            cancelAnimationFrame(frame);
        };
    }, []);

    return input;
}
