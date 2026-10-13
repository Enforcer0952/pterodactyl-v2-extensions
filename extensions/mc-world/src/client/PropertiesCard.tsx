import { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
    Alert,
    Button,
    Input,
    Select,
    Spinner,
    Switch,
    TextArea,
    TitledGreyBox,
    httpErrorToHuman,
    toast,
    useServerPermission,
} from '@pterodactyl/sdk';
import { getProperties, keys, saveProperties, type PropertyDef } from './api';
import { buildEdits, isStopped, validKey, validateProperty } from './logic';

/** Null means the key is removed from the file. */
type Draft = Record<string, string | null>;

const SECRET = /password|secret|token/i;

export default function PropertiesCard({ identifier }: { identifier: string }) {
    const queryClient = useQueryClient();
    const canEdit = useServerPermission('file.update');
    const properties = useQuery({
        queryKey: keys.properties(identifier),
        queryFn: () => getProperties(identifier),
        // A refetch while editing must not move the version the changes are checked against.
        refetchOnWindowFocus: false,
    });

    const [draft, setDraft] = useState<Draft>({});
    const [added, setAdded] = useState<string[]>([]);
    // The file version the unsaved changes were made against.
    const [baseHash, setBaseHash] = useState<string | null>(null);

    const data = properties.data;
    const saved = useMemo(() => Object.fromEntries((data?.entries ?? []).map((entry) => [entry.key, entry.value])), [data]);
    const settings = data?.schema.settings ?? [];
    const known = useMemo(() => new Set(settings.map((setting) => setting.key)), [settings]);

    const edits = buildEdits(saved, draft);
    const invalid = Object.entries(draft).some(([key, value]) => {
        const def = settings.find((setting) => setting.key === key);
        return value !== null && (def ? validateProperty(def, value) !== null : value.length > 2000 || value.includes('\u0000'));
    });
    const dirty = edits.length > 0;

    useEffect(() => {
        if (!dirty) return;
        const warn = (event: BeforeUnloadEvent) => event.preventDefault();
        window.addEventListener('beforeunload', warn);
        return () => window.removeEventListener('beforeunload', warn);
    }, [dirty]);

    const discard = () => {
        setDraft({});
        setAdded([]);
        setBaseHash(null);
    };

    const setValue = (key: string, value: string | null) => {
        if (baseHash === null && data) setBaseHash(data.hash);
        setDraft((current) => {
            const next = { ...current };
            if (value !== null && saved[key] === value) delete next[key];
            else if (value === null && !(key in saved)) delete next[key];
            else next[key] = value;
            return next;
        });
    };

    const save = useMutation({
        mutationFn: () => saveProperties(identifier, baseHash ?? data?.hash ?? '', edits),
        onSuccess: (result) => {
            queryClient.setQueryData(keys.properties(identifier), result);
            void queryClient.invalidateQueries({ queryKey: keys.worlds(identifier) });
            discard();
            toast.success(isStopped(result.state) ? 'Saved.' : 'Saved. The changes apply after a restart.');
        },
        onError: (error) => toast.error(httpErrorToHuman(error)),
    });

    if (properties.isLoading) {
        return (
            <TitledGreyBox title={'Properties'}>
                <Spinner centered />
            </TitledGreyBox>
        );
    }

    if (properties.error || !data) {
        return (
            <TitledGreyBox title={'Properties'}>
                <Alert type={'danger'}>{httpErrorToHuman(properties.error)}</Alert>
            </TitledGreyBox>
        );
    }

    if (!data.exists) {
        return (
            <TitledGreyBox title={'Properties'}>
                <Alert type={'info'}>server.properties does not exist yet. Start the server once so it is created.</Alert>
            </TitledGreyBox>
        );
    }

    const current = (key: string): string | undefined => (key in draft ? (draft[key] ?? undefined) : saved[key]);
    const others = [...data.entries.map((entry) => entry.key).filter((key) => !known.has(key)), ...added.filter((key) => !(key in saved))];

    return (
        <div className={'mcw:flex mcw:flex-col mcw:gap-4 mcw:pb-20'}>
            {!isStopped(data.state) && (
                <Alert type={'info'}>The server is running. Changes are saved to the file and apply after a restart.</Alert>
            )}

            {data.schema.groups.map((group) => {
                const items = settings.filter((setting) => setting.group === group.id);
                if (items.length === 0) return null;

                return (
                    <TitledGreyBox key={group.id} title={group.label}>
                        <div className={'mcw:grid mcw:grid-cols-1 mcw:gap-x-6 mcw:gap-y-4 mcw:md:grid-cols-2'}>
                            {items.map((def) => (
                                <PropertyRow
                                    key={def.key}
                                    def={def}
                                    value={current(def.key)}
                                    changed={def.key in draft}
                                    disabled={!canEdit}
                                    onChange={(value) => setValue(def.key, value)}
                                />
                            ))}
                        </div>
                    </TitledGreyBox>
                );
            })}

            <AllProperties
                keysShown={others}
                saved={saved}
                draft={draft}
                disabled={!canEdit}
                taken={(key) => key in saved || known.has(key) || added.includes(key)}
                onChange={setValue}
                onAdd={(key, value) => {
                    setAdded((list) => [...list, key]);
                    setValue(key, value);
                }}
            />

            {dirty && (
                <div
                    className={
                        'mcw:fixed mcw:inset-x-0 mcw:bottom-0 mcw:z-30 mcw:border-t mcw:border-border mcw:bg-background/95 mcw:px-4 mcw:py-3 mcw:backdrop-blur'
                    }
                >
                    <div className={'mcw:mx-auto mcw:flex mcw:max-w-5xl mcw:flex-wrap mcw:items-center mcw:justify-between mcw:gap-3'}>
                        <p className={'mcw:text-sm mcw:text-foreground'}>
                            {edits.length} unsaved change{edits.length === 1 ? '' : 's'}
                            {invalid && <span className={'mcw:ml-2 mcw:text-destructive'}>Fix the highlighted values first.</span>}
                            {!invalid && !isStopped(data.state) && (
                                <span className={'mcw:ml-2 mcw:text-muted-foreground'}>Applies after a restart.</span>
                            )}
                        </p>
                        <div className={'mcw:flex mcw:gap-2'}>
                            <Button isSecondary onClick={discard} disabled={save.isPending}>
                                Discard
                            </Button>
                            <Button onClick={() => save.mutate()} isLoading={save.isPending} disabled={invalid || !canEdit}>
                                Save
                            </Button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}

function PropertyRow({
    def,
    value,
    changed,
    disabled,
    onChange,
}: {
    def: PropertyDef;
    value: string | undefined;
    changed: boolean;
    disabled: boolean;
    onChange: (value: string) => void;
}) {
    const id = `mcw-prop-${def.key}`;
    const shown = value ?? '';
    // An unset key reads as its default, so the form shows what the server will do.
    const effective = value ?? def.default;
    const error = value !== undefined && changed ? validateProperty(def, value) : null;

    return (
        <div className={'mcw:flex mcw:flex-col mcw:gap-1'}>
            <label htmlFor={id} className={'mcw:text-sm mcw:font-medium mcw:text-foreground'}>
                {def.label}
                <span className={'mcw:ml-2 mcw:font-mono mcw:text-xs mcw:font-normal mcw:text-muted-foreground'}>{def.key}</span>
                {changed && <span className={'mcw:ml-2 mcw:text-xs mcw:text-warning'}>changed</span>}
            </label>

            {def.type === 'bool' ? (
                <Switch
                    id={id}
                    checked={effective === 'true'}
                    disabled={disabled}
                    onChange={(checked) => onChange(checked ? 'true' : 'false')}
                />
            ) : def.type === 'select' ? (
                <Select
                    id={id}
                    value={effective}
                    disabled={disabled}
                    options={optionsFor(def, effective)}
                    onChange={(next) => onChange(String(next))}
                />
            ) : def.type === 'text' ? (
                <TextArea
                    id={id}
                    rows={2}
                    value={shown}
                    placeholder={def.default}
                    disabled={disabled}
                    onChange={(event: React.ChangeEvent<HTMLTextAreaElement>) => onChange(event.target.value)}
                />
            ) : (
                <Input
                    id={id}
                    value={shown}
                    placeholder={def.default === '' ? 'Empty' : `Default: ${def.default}`}
                    type={def.type === 'int' ? 'number' : 'text'}
                    min={def.min}
                    max={def.max}
                    disabled={disabled}
                    $hasError={error !== null}
                    onChange={(event: React.ChangeEvent<HTMLInputElement>) => onChange(event.target.value)}
                />
            )}

            {error ? (
                <p className={'mcw:text-xs mcw:text-destructive'}>{error}</p>
            ) : (
                def.help && <p className={'mcw:text-xs mcw:text-muted-foreground'}>{def.help}</p>
            )}
        </div>
    );
}

/** The listed options, plus the current value when the file has one the list does not know (an older version's name). */
export function optionsFor(def: PropertyDef, current: string): { value: string; label: string }[] {
    const options = def.options ?? [];
    return options.some((option) => option.value === current) ? options : [...options, { value: current, label: `${current} (current)` }];
}

function AllProperties({
    keysShown,
    saved,
    draft,
    disabled,
    taken,
    onChange,
    onAdd,
}: {
    keysShown: string[];
    saved: Record<string, string>;
    draft: Draft;
    disabled: boolean;
    taken: (key: string) => boolean;
    onChange: (key: string, value: string | null) => void;
    onAdd: (key: string, value: string) => void;
}) {
    const [newKey, setNewKey] = useState('');
    const [newValue, setNewValue] = useState('');
    const keyError =
        newKey === ''
            ? null
            : !validKey(newKey)
              ? 'Use letters, digits, dots, dashes and underscores.'
              : taken(newKey)
                ? 'That key is already in the file.'
                : null;

    return (
        <TitledGreyBox title={'All properties'}>
            <div className={'mcw:flex mcw:flex-col mcw:gap-3'}>
                <p className={'mcw:text-sm mcw:text-muted-foreground'}>
                    Everything the form above does not cover. Comments and the order of the file are kept.
                </p>
                {keysShown.length === 0 ? (
                    <p className={'mcw:text-sm mcw:text-muted-foreground'}>No other properties.</p>
                ) : (
                    <ul className={'mcw:flex mcw:flex-col mcw:divide-y mcw:divide-border mcw:rounded-md mcw:border mcw:border-border'}>
                        {keysShown.map((key) => {
                            const removed = key in draft && draft[key] === null;
                            const value = key in draft ? (draft[key] ?? '') : (saved[key] ?? '');
                            const bad = !removed && value.length > 2000;

                            return (
                                <li key={key} className={'mcw:flex mcw:flex-wrap mcw:items-center mcw:gap-2 mcw:px-3 mcw:py-2'}>
                                    <span
                                        className={`mcw:w-56 mcw:break-all mcw:font-mono mcw:text-xs ${removed ? 'mcw:text-muted-foreground mcw:line-through' : 'mcw:text-foreground'}`}
                                    >
                                        {key}
                                    </span>
                                    <div className={'mcw:min-w-48 mcw:flex-1'}>
                                        <Input
                                            aria-label={key}
                                            value={value}
                                            type={SECRET.test(key) ? 'password' : 'text'}
                                            autoComplete={'off'}
                                            disabled={disabled || removed}
                                            $hasError={bad}
                                            onChange={(event: React.ChangeEvent<HTMLInputElement>) => onChange(key, event.target.value)}
                                        />
                                    </div>
                                    {!disabled && (
                                        <Button
                                            size={'xsmall'}
                                            isSecondary
                                            onClick={() => (removed ? onChange(key, saved[key] ?? '') : onChange(key, null))}
                                        >
                                            {removed ? 'Undo' : 'Remove'}
                                        </Button>
                                    )}
                                </li>
                            );
                        })}
                    </ul>
                )}

                {!disabled && (
                    <form
                        className={'mcw:flex mcw:flex-wrap mcw:items-start mcw:gap-2'}
                        onSubmit={(event) => {
                            event.preventDefault();
                            if (newKey === '' || keyError) return;
                            onAdd(newKey, newValue);
                            setNewKey('');
                            setNewValue('');
                        }}
                    >
                        <div className={'mcw:flex mcw:w-56 mcw:flex-col mcw:gap-1'}>
                            <Input
                                aria-label={'New key'}
                                placeholder={'New key'}
                                value={newKey}
                                $hasError={keyError !== null}
                                onChange={(event: React.ChangeEvent<HTMLInputElement>) => setNewKey(event.target.value.trim())}
                            />
                            {keyError && <p className={'mcw:text-xs mcw:text-destructive'}>{keyError}</p>}
                        </div>
                        <div className={'mcw:min-w-48 mcw:flex-1'}>
                            <Input
                                aria-label={'New value'}
                                placeholder={'Value'}
                                value={newValue}
                                onChange={(event: React.ChangeEvent<HTMLInputElement>) => setNewValue(event.target.value)}
                            />
                        </div>
                        <Button type={'submit'} size={'small'} isSecondary disabled={newKey === '' || keyError !== null}>
                            Add
                        </Button>
                    </form>
                )}
            </div>
        </TitledGreyBox>
    );
}
