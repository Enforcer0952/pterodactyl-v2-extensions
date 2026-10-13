import { useEffect, useMemo, useState, type ReactNode } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
    Alert,
    Button,
    Checkbox,
    Dialog,
    Input,
    Select,
    ServerContentBlock,
    Spinner,
    Switch,
    TextArea,
    TitledGreyBox,
    httpErrorToHuman,
    toast,
    useCurrentServerRequired,
} from '@pterodactyl/sdk';
import {
    cancelConfigApply,
    editKey,
    getConfig,
    keys,
    sameValue,
    saveConfig,
    validateSetting,
    type ConfigEdit,
    type ConfigFileName,
    type ConfigIssue,
    type ConfigPreset,
    type ConfigResponse,
    type ConfigSetting,
} from '../api';

const FILES: ConfigFileName[] = ['GameUserSettings.ini', 'Game.ini'];

type Tab = 'settings' | 'raw';

export default function ConfigScreen() {
    const server = useCurrentServerRequired();
    const identifier = server.attributes.identifier;
    const queryClient = useQueryClient();
    const [tab, setTab] = useState<Tab>('settings');
    const [edits, setEdits] = useState<Record<string, ConfigEdit>>({});
    const [raw, setRaw] = useState<Partial<Record<ConfigFileName, string>>>({});
    // The file versions the unsaved changes were made against. A refetch while editing
    // must not move these, or a stale draft would overwrite a newer file.
    const [baseHashes, setBaseHashes] = useState<Record<string, string> | null>(null);
    const editing = baseHashes !== null;

    const config = useQuery({
        queryKey: keys.config(identifier),
        queryFn: () => getConfig(identifier),
        // While settings wait for the server to stop, keep the status fresh, but not
        // while someone is editing.
        refetchInterval: (query) => (query.state.data?.apply && !editing ? 10_000 : false),
        refetchOnWindowFocus: !editing,
    });
    const [minutes, setMinutes] = useState(5);
    const [start, setStart] = useState(false);
    const [preset, setPreset] = useState<ConfigPreset | null>(null);

    const data = config.data;
    const settings = data?.schema.settings ?? [];
    const byKey = useMemo(() => new Map(settings.map((setting) => [setting.key.toLowerCase(), setting])), [settings]);
    const values = useMemo(() => new Map((data?.fields ?? []).map((field) => [field.key.toLowerCase(), field])), [data]);

    const rawChanged = (Object.keys(raw) as ConfigFileName[]).filter((file) => raw[file] !== data?.files[file]?.raw);
    const editList = Object.values(edits);
    const invalid = editList.some((edit) => {
        const setting = byKey.get(edit.key.toLowerCase());
        return edit.value !== null && setting !== undefined && validateSetting(setting, edit.value) !== null;
    });
    const changes = editList.length + rawChanged.length;
    const dirty = changes > 0;

    useEffect(() => {
        if (!dirty) return;
        const warn = (event: BeforeUnloadEvent) => event.preventDefault();
        window.addEventListener('beforeunload', warn);
        return () => window.removeEventListener('beforeunload', warn);
    }, [dirty]);

    const reset = () => {
        setEdits({});
        setRaw({});
        setBaseHashes(null);
    };

    const startEditing = () => {
        if (baseHashes === null && data) {
            setBaseHashes(Object.fromEntries(FILES.map((file) => [file, data.files[file]?.hash ?? ''])));
        }
    };

    const addEdits = (list: ConfigEdit[]) => {
        startEditing();
        setEdits((current) => {
            const next = { ...current };
            for (const edit of list) next[editKey(edit)] = edit;
            return next;
        });
    };

    const setValue = (setting: ConfigSetting, value: string | null) => {
        startEditing();
        const saved = values.get(setting.key.toLowerCase())?.value ?? null;
        const edit: ConfigEdit = { file: setting.file, section: setting.section, key: setting.key, value };
        setEdits((current) => {
            const next = { ...current };
            if ((value === null && saved === null) || (value !== null && saved !== null && value === saved)) {
                delete next[editKey(edit)];
            } else {
                next[editKey(edit)] = edit;
            }
            return next;
        });
    };

    const running = data !== undefined && !['offline', 'stopped'].includes(data.state);

    const save = useMutation({
        mutationFn: () =>
            saveConfig(identifier, {
                raw: Object.fromEntries(rawChanged.map((file) => [file, raw[file] ?? ''])),
                edits: editList,
                hashes: baseHashes ?? Object.fromEntries(FILES.map((file) => [file, data?.files[file]?.hash ?? ''])),
                minutes,
                start,
            }),
        onSuccess: (result) => {
            queryClient.setQueryData(keys.config(identifier), result);
            reset();
            if (result.result === 'unchanged') toast.success('Nothing to save: the files already have these settings.');
            else if (result.result === 'waiting')
                toast.success(
                    minutes === 0
                        ? 'Saving the world and restarting now to apply the settings.'
                        : `Restarting in ${minutes} minute${minutes === 1 ? '' : 's'} to apply the settings.`,
                );
            else toast.success(start ? 'Settings saved. Starting the server.' : 'Settings saved.');
        },
        onError: (error) => toast.error(httpErrorToHuman(error)),
    });

    const cancel = useMutation({
        mutationFn: () => cancelConfigApply(identifier),
        onSuccess: (result) => {
            queryClient.setQueryData(keys.config(identifier), result);
            toast.success('Cancelled. The settings were not written.');
        },
        onError: (error) => toast.error(httpErrorToHuman(error)),
    });

    if (config.isLoading) {
        return (
            <ServerContentBlock title={'Config'}>
                <Spinner centered />
            </ServerContentBlock>
        );
    }

    if (config.error || !data) {
        return (
            <ServerContentBlock title={'Config'}>
                <Alert type={'danger'}>{httpErrorToHuman(config.error)}</Alert>
            </ServerContentBlock>
        );
    }

    return (
        <ServerContentBlock title={'Config'}>
            <div className={'ark:flex ark:flex-col ark:gap-4 ark:pb-24'}>
                <ApplyStatus data={data} cancelling={cancel.isPending} onCancel={() => cancel.mutate()} />

                {data.issues.length > 0 && (
                    <Problems issues={data.issues} edits={edits} onFix={(fix) => addEdits(fix)} onShowRaw={() => setTab('raw')} />
                )}

                <div className={'ark:flex ark:flex-wrap ark:items-center ark:justify-between ark:gap-2'}>
                    <div className={'ark:flex ark:gap-1 ark:rounded-md ark:border ark:border-border ark:p-1'} role={'tablist'}>
                        <TabButton active={tab === 'settings'} onClick={() => setTab('settings')}>
                            Settings
                        </TabButton>
                        <TabButton active={tab === 'raw'} onClick={() => setTab('raw')}>
                            Raw files
                        </TabButton>
                    </div>
                    {tab === 'settings' && (
                        <div className={'ark:w-56'}>
                            <Select
                                placeholder={'Apply a preset…'}
                                value={null}
                                options={data.schema.presets.map((item) => ({ value: item.id, label: item.label }))}
                                onChange={(id) => setPreset(data.schema.presets.find((item) => item.id === id) ?? null)}
                            />
                        </div>
                    )}
                </div>

                {tab === 'settings' ? (
                    <SettingsForm data={data} edits={edits} values={values} onChange={setValue} />
                ) : (
                    <RawEditor data={data} raw={raw} onChange={(file, text) => {
                            startEditing();
                            setRaw((current) => ({ ...current, [file]: text }));
                        }} hasEdits={editList.length > 0} />
                )}
            </div>

            {dirty && (
                <div
                    className={
                        'ark:fixed ark:inset-x-0 ark:bottom-0 ark:z-30 ark:border-t ark:border-border ark:bg-background/95 ark:px-4 ark:py-3 ark:backdrop-blur'
                    }
                >
                    <div className={'ark:mx-auto ark:flex ark:max-w-5xl ark:flex-wrap ark:items-center ark:justify-between ark:gap-3'}>
                        <p className={'ark:text-sm ark:text-foreground'}>
                            {changes} unsaved change{changes === 1 ? '' : 's'}
                            {invalid && <span className={'ark:ml-2 ark:text-red-400'}>Fix the highlighted values first.</span>}
                        </p>
                        <div className={'ark:flex ark:flex-wrap ark:items-center ark:gap-2'}>
                            {running ? (
                                <div className={'ark:w-44'}>
                                    <Select
                                        value={minutes}
                                        options={data.minutes.map((value) => ({
                                            value,
                                            label: value === 0 ? 'Restart now' : `Restart in ${value} min`,
                                        }))}
                                        onChange={(value) => setMinutes(Number(value))}
                                    />
                                </div>
                            ) : (
                                <label className={'ark:flex ark:items-center ark:gap-2 ark:text-sm ark:text-foreground'}>
                                    <Checkbox checked={start} onChange={setStart} />
                                    Start the server after saving
                                </label>
                            )}
                            <Button isSecondary onClick={reset} disabled={save.isPending}>
                                Discard
                            </Button>
                            <Button onClick={() => save.mutate()} isLoading={save.isPending} disabled={invalid}>
                                {running ? 'Save and restart' : 'Save'}
                            </Button>
                        </div>
                    </div>
                </div>
            )}

            {preset && (
                <PresetDialog
                    preset={preset}
                    byKey={byKey}
                    values={values}
                    edits={edits}
                    onClose={() => setPreset(null)}
                    onApply={(list) => {
                        addEdits(list);
                        setPreset(null);
                        setTab('settings');
                    }}
                />
            )}
        </ServerContentBlock>
    );
}

function TabButton({ active, onClick, children }: { active: boolean; onClick: () => void; children: ReactNode }) {
    return (
        <button
            type={'button'}
            role={'tab'}
            aria-selected={active}
            onClick={onClick}
            className={`ark:rounded ark:px-3 ark:py-1 ark:text-sm ${active ? 'ark:bg-muted ark:text-foreground' : 'ark:text-muted-foreground ark:hover:text-foreground'}`}
        >
            {children}
        </button>
    );
}

function ApplyStatus({ data, cancelling, onCancel }: { data: ConfigResponse; cancelling: boolean; onCancel: () => void }) {
    if (!data.apply) {
        return data.restart && data.restart.kind !== 'apply' ? (
            <Alert type={'info'}>A restart is counting down. Settings saved now wait until it is done.</Alert>
        ) : null;
    }

    if (data.apply.status === 'failed') {
        return (
            <Alert type={'danger'} title={'Settings were not written'}>
                <div className={'ark:flex ark:flex-wrap ark:items-center ark:justify-between ark:gap-2'}>
                    <span>{data.apply.error}</span>
                    <Button size={'small'} isSecondary onClick={onCancel} isLoading={cancelling}>
                        Dismiss
                    </Button>
                </div>
            </Alert>
        );
    }

    const when = data.restart?.kind === 'apply' && data.restart.restart_at ? new Date(data.restart.restart_at) : null;

    return (
        <Alert type={'warning'} title={'Waiting to apply settings'}>
            <div className={'ark:flex ark:flex-wrap ark:items-center ark:justify-between ark:gap-2'}>
                <span>
                    {data.apply.status === 'writing'
                        ? 'Writing the settings now, then starting the server.'
                        : when
                          ? `The server stops at ${when.toLocaleTimeString()}. The settings are written as soon as it is off, then it starts again.`
                          : 'The settings are written as soon as the server is off, then it starts again.'}
                </span>
                {data.apply.status === 'waiting' && (
                    <Button size={'small'} color={'red'} isSecondary onClick={onCancel} isLoading={cancelling}>
                        Cancel
                    </Button>
                )}
            </div>
        </Alert>
    );
}

function Problems({
    issues,
    edits,
    onFix,
    onShowRaw,
}: {
    issues: ConfigIssue[];
    edits: Record<string, ConfigEdit>;
    onFix: (edits: ConfigEdit[]) => void;
    onShowRaw: () => void;
}) {
    const [open, setOpen] = useState(issues.length <= 5);
    const fixed = (issue: ConfigIssue) => {
        const pending = edits[editKey(issue)];
        return pending !== undefined;
    };
    const left = issues.filter((issue) => !fixed(issue)).length;

    return (
        <TitledGreyBox title={`${left} problem${left === 1 ? '' : 's'} found in the config files`}>
            <div className={'ark:flex ark:flex-col ark:gap-2'}>
                <p className={'ark:text-sm ark:text-muted-foreground'}>
                    These lines are in a file or section where ARK never reads them, or look like a typo of a real setting. Fixes are added
                    to your unsaved changes.
                </p>
                {open ? (
                    <ul className={'ark:flex ark:flex-col ark:divide-y ark:divide-border ark:rounded-md ark:border ark:border-border'}>
                        {issues.map((issue) => (
                            <li key={`${issue.file}:${issue.line}:${issue.kind}`} className={'ark:flex ark:flex-col ark:gap-2 ark:px-3 ark:py-2'}>
                                <div className={'ark:flex ark:items-start ark:justify-between ark:gap-2'}>
                                    <p className={`ark:text-sm ${fixed(issue) ? 'ark:text-muted-foreground ark:line-through' : 'ark:text-foreground'}`}>
                                        {issue.message}
                                    </p>
                                    <button
                                        type={'button'}
                                        onClick={onShowRaw}
                                        className={'ark:shrink-0 ark:font-mono ark:text-xs ark:text-muted-foreground ark:hover:underline'}
                                    >
                                        {issue.file}:{issue.line}
                                    </button>
                                </div>
                                {!fixed(issue) && issue.fixes.length > 0 && (
                                    <div className={'ark:flex ark:flex-wrap ark:gap-2'}>
                                        {issue.fixes.map((fix) => (
                                            <Button key={fix.label} size={'xsmall'} isSecondary onClick={() => onFix(fix.edits)}>
                                                {fix.label}
                                            </Button>
                                        ))}
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>
                ) : (
                    <div>
                        <Button size={'small'} isSecondary onClick={() => setOpen(true)}>
                            Show all {issues.length}
                        </Button>
                    </div>
                )}
                {left > 1 && (
                    <div>
                        <Button
                            size={'small'}
                            onClick={() => onFix(issues.filter((issue) => !fixed(issue) && issue.fixes.length > 0).flatMap((issue) => issue.fixes[0].edits))}
                        >
                            Apply the first fix for all
                        </Button>
                    </div>
                )}
            </div>
        </TitledGreyBox>
    );
}

function SettingsForm({
    data,
    edits,
    values,
    onChange,
}: {
    data: ConfigResponse;
    edits: Record<string, ConfigEdit>;
    values: Map<string, ConfigResponse['fields'][number]>;
    onChange: (setting: ConfigSetting, value: string | null) => void;
}) {
    return (
        <div className={'ark:flex ark:flex-col ark:gap-4'}>
            {!data.files['GameUserSettings.ini'].exists && (
                <Alert type={'info'}>GameUserSettings.ini does not exist yet. Start the server once so ARK creates it, or save here to create it.</Alert>
            )}
            {data.schema.groups.map((group) => {
                const items = data.schema.settings.filter((setting) => setting.group === group.id);
                if (items.length === 0) return null;

                return (
                    <TitledGreyBox key={group.id} title={group.label}>
                        <div className={'ark:grid ark:grid-cols-1 ark:gap-x-6 ark:gap-y-4 ark:md:grid-cols-2'}>
                            {items.map((setting) => {
                                const field = values.get(setting.key.toLowerCase());
                                const edit = edits[editKey(setting)];
                                return (
                                    <SettingRow
                                        key={setting.key}
                                        setting={setting}
                                        saved={field?.value ?? null}
                                        value={edit ? edit.value : (field?.value ?? null)}
                                        changed={edit !== undefined}
                                        overridden={field?.overridden ?? false}
                                        onChange={(value) => onChange(setting, value)}
                                    />
                                );
                            })}
                        </div>
                    </TitledGreyBox>
                );
            })}
        </div>
    );
}

function SettingRow({
    setting,
    saved,
    value,
    changed,
    overridden,
    onChange,
}: {
    setting: ConfigSetting;
    saved: string | null;
    value: string | null;
    changed: boolean;
    overridden: boolean;
    onChange: (value: string | null) => void;
}) {
    const id = `ark-config-${setting.key}`;
    const error = value !== null ? validateSetting(setting, value) : null;
    const isDefault = value === null;

    return (
        <div className={'ark:flex ark:flex-col ark:gap-1'}>
            <div className={'ark:flex ark:items-center ark:justify-between ark:gap-2'}>
                <label htmlFor={id} className={'ark:text-sm ark:font-medium ark:text-foreground'}>
                    {setting.label}
                    {changed && <span className={'ark:ml-2 ark:text-xs ark:text-orange-400'}>changed</span>}
                </label>
                {!isDefault && !overridden && (
                    <button
                        type={'button'}
                        onClick={() => onChange(null)}
                        className={'ark:text-xs ark:text-muted-foreground ark:hover:underline'}
                        title={'Remove the line so ARK uses its default'}
                    >
                        Use default
                    </button>
                )}
            </div>

            {setting.type === 'bool' ? (
                <Switch
                    id={id}
                    checked={(value ?? setting.default).toLowerCase() === 'true'}
                    onChange={(checked) => onChange(checked ? 'True' : 'False')}
                    disabled={overridden}
                    label={isDefault ? `Default (${setting.default})` : undefined}
                />
            ) : (
                <Input
                    id={id}
                    value={value ?? ''}
                    placeholder={`Default: ${setting.default === '' ? 'none' : setting.default}`}
                    inputMode={setting.type === 'string' ? 'text' : 'decimal'}
                    disabled={overridden}
                    $hasError={error !== null}
                    onChange={(event: React.ChangeEvent<HTMLInputElement>) => onChange(event.target.value === '' ? null : event.target.value)}
                />
            )}

            {error ? (
                <p className={'ark:text-xs ark:text-red-400'}>{error}</p>
            ) : overridden ? (
                <p className={'ark:text-xs ark:text-orange-400'}>Set by the startup command, which wins over this file. Change it on the Startup page.</p>
            ) : (
                <p className={'ark:text-xs ark:text-muted-foreground'}>
                    {setting.help}
                    {setting.help && ' '}
                    {saved === null && !changed && setting.type !== 'bool' && setting.default !== '' && `Not set, so ARK uses ${setting.default}.`}
                </p>
            )}
        </div>
    );
}

function RawEditor({
    data,
    raw,
    onChange,
    hasEdits,
}: {
    data: ConfigResponse;
    raw: Partial<Record<ConfigFileName, string>>;
    onChange: (file: ConfigFileName, text: string) => void;
    hasEdits: boolean;
}) {
    const [file, setFile] = useState<ConfigFileName>('GameUserSettings.ini');
    const info = data.files[file];

    return (
        <div className={'ark:flex ark:flex-col ark:gap-2'}>
            <div className={'ark:flex ark:flex-wrap ark:items-center ark:justify-between ark:gap-2'}>
                <div className={'ark:flex ark:gap-2'}>
                    {FILES.map((name) => (
                        <Button key={name} size={'small'} isSecondary={name !== file} onClick={() => setFile(name)}>
                            {name}
                            {raw[name] !== undefined && raw[name] !== data.files[name].raw && ' •'}
                        </Button>
                    ))}
                </div>
                <span className={'ark:font-mono ark:text-xs ark:text-muted-foreground'}>{info.path}</span>
            </div>
            {hasEdits && (
                <Alert type={'info'}>
                    You also have unsaved changes in the Settings tab. They are applied on top of this text when you save.
                </Alert>
            )}
            <TextArea
                aria-label={file}
                spellCheck={false}
                rows={28}
                className={'ark:font-mono ark:text-xs'}
                value={raw[file] ?? info.raw}
                placeholder={info.exists ? '' : 'This file does not exist yet.'}
                onChange={(event: React.ChangeEvent<HTMLTextAreaElement>) => onChange(file, event.target.value)}
            />
            <p className={'ark:text-xs ark:text-muted-foreground'}>
                Passwords are shown as ******** and kept as they are when you save. Change them on the Startup page. Every save keeps a
                backup of the old file next to it.
            </p>
        </div>
    );
}

function PresetDialog({
    preset,
    byKey,
    values,
    edits,
    onClose,
    onApply,
}: {
    preset: ConfigPreset;
    byKey: Map<string, ConfigSetting>;
    values: Map<string, ConfigResponse['fields'][number]>;
    edits: Record<string, ConfigEdit>;
    onClose: () => void;
    onApply: (edits: ConfigEdit[]) => void;
}) {
    const rows = Object.entries(preset.values).flatMap(([key, value]) => {
        const setting = byKey.get(key.toLowerCase());
        if (!setting) return [];
        const pending = edits[editKey(setting)];
        const current = pending ? pending.value : (values.get(key.toLowerCase())?.value ?? null);
        return [{ setting, current, value, same: sameValue(current ?? setting.default, value) }];
    });
    const changes = rows.filter((row) => !row.same);

    return (
        <Dialog.Confirm
            open
            title={`Apply the ${preset.label} preset?`}
            confirm={changes.length === 0 ? 'Close' : `Apply ${changes.length} change${changes.length === 1 ? '' : 's'}`}
            onClose={onClose}
            onConfirmed={() =>
                changes.length === 0
                    ? onClose()
                    : onApply(changes.map((row) => ({ file: row.setting.file, section: row.setting.section, key: row.setting.key, value: row.value })))
            }
        >
            <p className={'ark:mb-3 ark:text-sm'}>{preset.description} Nothing is saved until you press Save.</p>
            {changes.length === 0 ? (
                <p className={'ark:text-sm ark:text-muted-foreground'}>Your settings already match this preset.</p>
            ) : (
                <table className={'ark:w-full ark:text-sm'}>
                    <thead>
                        <tr className={'ark:text-left ark:text-xs ark:text-muted-foreground'}>
                            <th className={'ark:py-1 ark:font-normal'}>Setting</th>
                            <th className={'ark:py-1 ark:font-normal'}>Now</th>
                            <th className={'ark:py-1 ark:font-normal'}>Preset</th>
                        </tr>
                    </thead>
                    <tbody>
                        {changes.map((row) => (
                            <tr key={row.setting.key} className={'ark:border-t ark:border-border'}>
                                <td className={'ark:py-1'}>{row.setting.label}</td>
                                <td className={'ark:py-1 ark:font-mono ark:text-muted-foreground'}>{row.current ?? `${row.setting.default} (default)`}</td>
                                <td className={'ark:py-1 ark:font-mono'}>{row.value}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}
        </Dialog.Confirm>
    );
}
