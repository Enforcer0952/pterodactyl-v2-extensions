import { Fragment, useEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { useQuery } from '@tanstack/react-query';
import { useServerPermission, type SdkServer } from '@pterodactyl/sdk';
import { assist, complete, type Assist, type CommandDef, type PlayerRef } from './engine';
import { getLists, getOnline, keys } from './api';
import commandData from './data/commands.json';

/** The Panel's console input; its aria-label is the most stable way to find it. */
const INPUT_SELECTOR = 'input[aria-label="Console command input."]';
const VISIBLE = 8;

/** Only ARK servers (the "ark" tag) whose user may use the console. */
export default function ConsoleAssistSlot({ data: server }: { data: SdkServer }) {
    const tags = (server.attributes.egg_tags ?? []).map((tag) => tag.toLowerCase());
    const canSend = useServerPermission('control.console');
    // Player names come from the players endpoints, which need the view permission.
    const canView = useServerPermission('ext.ark-tools.view');

    if (!canSend || !tags.includes('ark')) {
        return null;
    }

    return <ConsoleAssist server={server} withPlayers={canView} />;
}

export function ConsoleAssist({ server, withPlayers = true }: { server: SdkServer; withPlayers?: boolean }) {
    const identifier = server.attributes.identifier;
    const input = useConsoleInput();
    const [text, setText] = useState('');
    const [focused, setFocused] = useState(false);
    const [dismissed, setDismissed] = useState(false);
    const [selected, setSelected] = useState(0);

    // Shares its data with the players card, so this adds no requests of its own.
    const online = useQuery({
        queryKey: keys.online(identifier),
        queryFn: () => getOnline(identifier),
        staleTime: 30_000,
        enabled: withPlayers,
    });
    const lists = useQuery({
        queryKey: keys.lists(identifier),
        queryFn: () => getLists(identifier),
        staleTime: 30_000,
        enabled: withPlayers,
    });

    const commands = useMemo<CommandDef[]>(() => commandData.map((command) => ({ ...command, source: 'ARK' })), []);

    const players = useMemo<PlayerRef[]>(() => {
        const list: PlayerRef[] = (online.data?.online ?? []).map((player) => ({ ...player, online: true }));
        const seen = new Set(list.map((player) => player.steam_id));
        for (const player of lists.data?.known ?? []) {
            if (!seen.has(player.steam_id)) {
                seen.add(player.steam_id);
                list.push({ name: player.name, steam_id: player.steam_id });
            }
        }
        return list;
    }, [online.data, lists.data]);

    const result: Assist = useMemo(
        () => (text.trim() === '' ? { suggestions: [], hints: [] } : assist(text, commands, players)),
        [text, commands, players],
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
            setText(input.value);
        };

        // Runs on the input itself, before the Panel's own key handling, so Tab and
        // the arrow keys can be taken over while suggestions are showing.
        const onKeyDown = (event: KeyboardEvent) => {
            const { open: isOpen, index: current, result: assistResult } = state.current;
            const suggestions = assistResult.suggestions;

            if (event.key === 'Tab') {
                if (suggestions.length > 0 && !event.shiftKey) {
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

    if (!input?.parentElement || !open) return null;

    return createPortal(
        <div
            className={
                'ark:absolute ark:bottom-full ark:left-0 ark:z-30 ark:mb-1 ark:flex ark:w-full ark:max-w-3xl ark:flex-col ark:gap-1 ark:font-mono ark:text-xs'
            }
        >
            {result.suggestions.length > 0 && (
                <ul
                    ref={listRef}
                    role={'listbox'}
                    aria-label={'Command suggestions'}
                    className={
                        'ark:max-h-60 ark:overflow-y-auto ark:rounded-sm ark:border ark:border-border ark:bg-popover ark:py-1 ark:shadow-lg'
                    }
                    style={{ maxHeight: `${VISIBLE * 1.75}rem` }}
                >
                    {result.suggestions.map((suggestion, position) => (
                        <li
                            key={suggestion.value}
                            role={'option'}
                            aria-selected={position === index}
                            onMouseDown={(event) => {
                                // Keep focus in the console input.
                                event.preventDefault();
                                acceptRef.current(suggestion.value);
                            }}
                            onMouseEnter={() => setSelected(position)}
                            className={`ark:flex ark:cursor-pointer ark:items-baseline ark:gap-3 ark:px-2 ark:py-1 ${
                                position === index ? 'ark:bg-accent ark:text-accent-foreground' : 'ark:text-foreground'
                            }`}
                        >
                            <span className={'ark:shrink-0'}>{suggestion.value}</span>
                            {suggestion.detail && (
                                <span className={`ark:truncate ${position === index ? 'ark:opacity-80' : 'ark:text-muted-foreground'}`}>
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
                        'ark:rounded-sm ark:border ark:border-border ark:bg-popover ark:px-2 ark:py-1.5 ark:text-muted-foreground ark:shadow-lg'
                    }
                >
                    {result.hints.slice(0, 4).map((line, position) => (
                        <div key={position} className={'ark:truncate'}>
                            {line.parts.map((part, partIndex) => (
                                <Fragment key={partIndex}>
                                    {partIndex > 0 ? ' ' : ''}
                                    <span className={part.current ? 'ark:font-semibold ark:text-foreground ark:underline' : undefined}>
                                        {part.text}
                                    </span>
                                </Fragment>
                            ))}
                        </div>
                    ))}
                    {result.description && <div className={'ark:mt-1 ark:font-sans ark:text-foreground/80'}>{result.description}</div>}
                    <div className={'ark:mt-1 ark:font-sans ark:text-[11px]'}>Tab to complete · ↑↓ to choose · Esc to hide</div>
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
        const observer = new MutationObserver(find);
        observer.observe(document.body, { childList: true, subtree: true });
        return () => observer.disconnect();
    }, []);

    return input;
}
