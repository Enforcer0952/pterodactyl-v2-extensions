import { Fragment, useEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { useQuery } from '@tanstack/react-query';
import { useServerPermission, type SdkServer } from '@pterodactyl/sdk';
import { assist, complete, type Assist, type CommandDef, type PlayerRef, type Suggestion } from './engine';
import { getOnline, isTerrariaServer, keys } from './api';
import commandData from './data/commands.json';

/** The Panel's console input; its aria-label is the most stable way to find it. */
const INPUT_SELECTOR = 'input[aria-label="Console command input."]';
const VISIBLE = 8;

/** Only Terraria servers whose user may use the console. */
export default function ConsoleAssistSlot({ data: server }: { data: SdkServer }) {
    const canSend = useServerPermission('control.console');
    // Player names come from the online players endpoint, which needs the view permission.
    const canView = useServerPermission('ext.terraria-tools.view');

    if (!canSend || !isTerrariaServer(server)) {
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

    const commands = useMemo<CommandDef[]>(() => commandData.map((command) => ({ ...command, source: 'Terraria' })), []);

    const players = useMemo<PlayerRef[]>(
        () => (online.data?.players ?? []).map((player) => ({ name: player.name, online: true })),
        [online.data],
    );

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
        const accept = (suggestion: Suggestion) => {
            input.value = complete(input.value, suggestion);
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
                    accept(suggestions[current] ?? suggestions[0]);
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

    const acceptRef = useRef<(suggestion: Suggestion) => void>(() => undefined);
    const listRef = useRef<HTMLUListElement>(null);

    useEffect(() => {
        listRef.current?.querySelector('[aria-selected="true"]')?.scrollIntoView?.({ block: 'nearest' });
    }, [index, open]);

    if (!input?.parentElement || !open) return null;

    return createPortal(
        <div
            className={
                'terra:absolute terra:bottom-full terra:left-0 terra:z-30 terra:mb-1 terra:flex terra:w-full terra:max-w-3xl terra:flex-col terra:gap-1 terra:font-mono terra:text-xs'
            }
        >
            {result.suggestions.length > 0 && (
                <ul
                    ref={listRef}
                    role={'listbox'}
                    aria-label={'Command suggestions'}
                    className={
                        'terra:max-h-60 terra:overflow-y-auto terra:rounded-sm terra:border terra:border-border terra:bg-popover terra:py-1 terra:shadow-lg'
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
                                acceptRef.current(suggestion);
                            }}
                            onMouseEnter={() => setSelected(position)}
                            className={`terra:flex terra:cursor-pointer terra:items-baseline terra:gap-3 terra:px-2 terra:py-1 ${
                                position === index ? 'terra:bg-accent terra:text-accent-foreground' : 'terra:text-foreground'
                            }`}
                        >
                            <span className={'terra:shrink-0'}>{suggestion.value}</span>
                            {suggestion.detail && (
                                <span
                                    className={`terra:truncate ${position === index ? 'terra:opacity-80' : 'terra:text-muted-foreground'}`}
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
                        'terra:rounded-sm terra:border terra:border-border terra:bg-popover terra:px-2 terra:py-1.5 terra:text-muted-foreground terra:shadow-lg'
                    }
                >
                    {result.hints.slice(0, 4).map((line, position) => (
                        <div key={position} className={'terra:truncate'}>
                            {line.parts.map((part, partIndex) => (
                                <Fragment key={partIndex}>
                                    {partIndex > 0 ? ' ' : ''}
                                    <span
                                        className={part.current ? 'terra:font-semibold terra:text-foreground terra:underline' : undefined}
                                    >
                                        {part.text}
                                    </span>
                                </Fragment>
                            ))}
                        </div>
                    ))}
                    {result.description && (
                        <div className={'terra:mt-1 terra:font-sans terra:text-foreground/80'}>{result.description}</div>
                    )}
                    <div className={'terra:mt-1 terra:font-sans terra:text-[11px]'}>Tab to complete · ↑↓ to choose · Esc to hide</div>
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
