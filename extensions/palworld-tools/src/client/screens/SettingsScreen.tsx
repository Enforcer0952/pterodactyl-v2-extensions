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
    cancelSettingsApply,
    displayDefault,
    getSettings,
    keys,
    processSettings,
    sameValue,
    saveSettings,
    validateSetting,
    type Edit,
    type Field,
    type Issue,
    type Preset,
    type Setting,
    type SettingsResponse,
} from '../api';

type Tab = 'settings' | 'raw';

export default function SettingsScreen() {
    const server = useCurrentServerRequired();
    const identifier = server.attributes.identifier;
    const queryClient = useQueryClient();
    const [tab, setTab] = useState<Tab>('settings');
    const [edits, setEdits] = useState<Record<string, Edit>>({});
    const [raw, setRaw] = useState<string | null>(null);
    // The file version the unsaved changes were made against. A refetch while editing
    // must not move it, or a stale draft would overwrite a newer file.
    const [baseHash, setBaseHash] = useState<string | null>(null);
    const editing = baseHash !== null;
    const [start, setStart] = useState(false);
    const [preset, setPreset] = useState<Preset | null>(null);

    const settings = useQuery({
        queryKey: keys.settings(identifier),
        queryFn: () => getSettings(identifier),
        refetchOnWindowFocus: !editing,
    });
    const data = settings.data;
    const waiting = data?.apply?.status === 'waiting';

    // While a change waits for the server to stop, ask the panel to write it as soon as it is off.
    useEffect(() => {
        if (!waiting || editing) return;
        const timer = window.setInterval(() => {
            processSettings(identifier)
                .then((result) => queryClient.setQueryData(keys.settings(identifier), result))
                .catch(() => undefined);
        }, 5000);
        return () => window.clearInterval(timer);
    }, [waiting, editing, identifier, queryClient]);

    const byKey = useMemo(() => new Map((data?.schema.settings ?? []).map((setting) => [setting.key.toLowerCase(), setting])), [data]);
    const fields = useMemo(() => new Map((data?.fields ?? []).map((field) => [field.key.toLowerCase(), field])), [data]);

    const rawChanged = raw !== null && raw !== data?.file.raw;
    const editList = Object.values(edits);
    const invalid = editList.some((edit) => {
        const setting = byKey.get(edit.key.toLowerCase());
        return edit.value !== null && setting !== undefined && validateSetting(setting, edit.value) !== null;
    });
    const changes = editList.length + (rawChanged ? 1 : 0);
    const dirty = changes > 0;

    useEffect(() => {
        if (!dirty) return;
        const warn = (event: BeforeUnloadEvent) => event.preventDefault();
        window.addEventListener('beforeunload', warn);
        return () => window.removeEventListener('beforeunload', warn);
    }, [dirty]);

    const reset = () => {
        setEdits({});
        setRaw(null);
        setBaseHash(null);
    };

    const startEditing = () => {
        if (baseHash === null && data) setBaseHash(data.file.hash);
    };

    const addEdits = (list: Edit[]) => {
        startEditing();
        setEdits((current) => {
            const next = { ...current };
            for (const edit of list) next[edit.key.toLowerCase()] = edit;
            return next;
        });
    };

    const setValue = (setting: Setting, value: string | null) => {
        startEditing();
        const field = fields.get(setting.key.toLowerCase());
        const saved = field?.value ?? null;
        setEdits((current) => {
            const next = { ...current };
            const id = setting.key.toLowerCase();
            // A password has no readable saved value, so any typed value is a change.
            if (
                setting.secret
                    ? value === null || value === ''
                    : (value === null && saved === null) || (value !== null && saved !== null && value === saved)
            )
                delete next[id];
            else next[id] = { key: setting.key, value };
            return next;
        });
    };

    const running = data !== undefined && !['offline', 'stopped'].includes(data.state);

    const save = useMutation({
        mutationFn: () =>
            saveSettings(identifier, {
                ...(rawChanged && raw !== null ? { raw } : {}),
                edits: editList,
                hash: baseHash ?? data?.file.hash ?? '',
                restart: running,
                start: !running && start,
            }),
        onSuccess: (result) => {
            queryClient.setQueryData(keys.settings(identifier), result);
            reset();
            if (result.result === 'unchanged') toast.success('Nothing to save: the file already has these settings.');
            else if (result.result === 'waiting')
                toast.success('Stopping the server. The settings are written once it is off, then it starts again.');
            else toast.success(start ? 'Settings saved. Starting the server.' : 'Settings saved.');
            queryClient.invalidateQueries({ queryKey: keys.players(identifier) });
        },
        onError: (error) => toast.error(httpErrorToHuman(error)),
    });

    const cancel = useMutation({
        mutationFn: () => cancelSettingsApply(identifier),
        onSuccess: (result) => {
            queryClient.setQueryData(keys.settings(identifier), result);
            toast.success('Cancelled. The settings were not written.');
        },
        onError: (error) => toast.error(httpErrorToHuman(error)),
    });

    const title = 'Palworld settings';

    if (settings.isLoading) {
        return (
            <ServerContentBlock title={title}>
                <Spinner centered />
            </ServerContentBlock>
        );
    }

    if (settings.error || !data) {
        return (
            <ServerContentBlock title={title}>
                <Alert type={'danger'}>{httpErrorToHuman(settings.error)}</Alert>
            </ServerContentBlock>
        );
    }

    const canSave = data.can.write && (!running || data.can.control) && (running || !start || data.can.start);

    return (
        <ServerContentBlock title={title}>
            <div className={'pal:flex pal:flex-col pal:gap-4 pal:pb-24'}>
                <ApplyStatus data={data} cancelling={cancel.isPending} onCancel={() => cancel.mutate()} />

                {data.file.from_template && (
                    <Alert type={'info'}>
                        {data.file.exists
                            ? "PalWorldSettings.ini is empty, so the game's default settings are shown. Saving writes them to the file."
                            : "PalWorldSettings.ini does not exist yet, so the game's default settings are shown. Saving creates the file."}
                    </Alert>
                )}

                {!data.can.write && (
                    <Alert type={'info'}>
                        You can look at the settings but not change them. Changing them needs permission to edit files.
                    </Alert>
                )}

                {data.issues.length > 0 && <Problems issues={data.issues} edits={edits} onFix={addEdits} onShowRaw={() => setTab('raw')} />}

                <div className={'pal:flex pal:flex-wrap pal:items-center pal:justify-between pal:gap-2'}>
                    <div className={'pal:flex pal:gap-1 pal:rounded-md pal:border pal:border-border pal:p-1'} role={'tablist'}>
                        <TabButton active={tab === 'settings'} onClick={() => setTab('settings')}>
                            Settings
                        </TabButton>
                        <TabButton active={tab === 'raw'} onClick={() => setTab('raw')}>
                            Raw file
                        </TabButton>
                    </div>
                    {tab === 'settings' && data.can.write && (
                        <div className={'pal:w-56'}>
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
                    <SettingsForm data={data} edits={edits} fields={fields} readOnly={!data.can.write} onChange={setValue} />
                ) : (
                    <RawEditor
                        data={data}
                        value={raw ?? data.file.raw}
                        readOnly={!data.can.write}
                        hasEdits={editList.length > 0}
                        onChange={(text) => {
                            startEditing();
                            setRaw(text);
                        }}
                    />
                )}
            </div>

            {dirty && (
                <div
                    className={
                        'pal:fixed pal:inset-x-0 pal:bottom-0 pal:z-30 pal:border-t pal:border-border pal:bg-background/95 pal:px-4 pal:py-3 pal:backdrop-blur'
                    }
                >
                    <div className={'pal:mx-auto pal:flex pal:max-w-5xl pal:flex-wrap pal:items-center pal:justify-between pal:gap-3'}>
                        <p className={'pal:text-sm pal:text-foreground'}>
                            {changes} unsaved change{changes === 1 ? '' : 's'}
                            {invalid && <span className={'pal:ml-2 pal:text-destructive'}>Fix the highlighted values first.</span>}
                            {!canSave && data.can.write && (
                                <span className={'pal:ml-2 pal:text-destructive'}>You may not stop and start this server.</span>
                            )}
                        </p>
                        <div className={'pal:flex pal:flex-wrap pal:items-center pal:gap-2'}>
                            {running ? (
                                <span className={'pal:text-xs pal:text-muted-foreground'}>
                                    The server stops, the file is written, then it starts again.
                                </span>
                            ) : (
                                <label className={'pal:flex pal:items-center pal:gap-2 pal:text-sm pal:text-foreground'}>
                                    <Checkbox checked={start} onChange={setStart} />
                                    Start the server after saving
                                </label>
                            )}
                            <Button isSecondary onClick={reset} disabled={save.isPending}>
                                Discard
                            </Button>
                            <Button onClick={() => save.mutate()} isLoading={save.isPending} disabled={invalid || !canSave}>
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
                    fields={fields}
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
            className={`pal:rounded pal:px-3 pal:py-1 pal:text-sm ${active ? 'pal:bg-muted pal:text-foreground' : 'pal:text-muted-foreground pal:hover:text-foreground'}`}
        >
            {children}
        </button>
    );
}

function ApplyStatus({ data, cancelling, onCancel }: { data: SettingsResponse; cancelling: boolean; onCancel: () => void }) {
    if (!data.apply) return null;

    if (data.apply.status === 'failed') {
        return (
            <Alert type={'danger'} title={'Settings were not written'}>
                <div className={'pal:flex pal:flex-wrap pal:items-center pal:justify-between pal:gap-2'}>
                    <span>{data.apply.error}</span>
                    <Button size={'small'} isSecondary onClick={onCancel} isLoading={cancelling}>
                        Dismiss
                    </Button>
                </div>
            </Alert>
        );
    }

    return (
        <Alert type={'warning'} title={'Waiting to apply settings'}>
            <div className={'pal:flex pal:flex-wrap pal:items-center pal:justify-between pal:gap-2'}>
                <span>
                    {data.apply.status === 'writing'
                        ? 'Writing the settings now, then starting the server.'
                        : 'The server is stopping. The settings are written as soon as it is off, then it starts again.'}
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
    issues: Issue[];
    edits: Record<string, Edit>;
    onFix: (edits: Edit[]) => void;
    onShowRaw: () => void;
}) {
    const fixed = (issue: Issue) => issue.key !== '' && edits[issue.key.toLowerCase()] !== undefined;
    const left = issues.filter((issue) => !fixed(issue)).length;

    return (
        <TitledGreyBox title={`${left} problem${left === 1 ? '' : 's'} found in the settings file`}>
            <ul className={'pal:flex pal:flex-col pal:divide-y pal:divide-border pal:rounded-md pal:border pal:border-border'}>
                {issues.map((issue) => (
                    <li key={`${issue.kind}:${issue.key}`} className={'pal:flex pal:flex-col pal:gap-2 pal:px-3 pal:py-2'}>
                        <p className={`pal:text-sm ${fixed(issue) ? 'pal:text-muted-foreground pal:line-through' : 'pal:text-foreground'}`}>
                            {issue.message}
                        </p>
                        {!fixed(issue) && (
                            <div className={'pal:flex pal:flex-wrap pal:gap-2'}>
                                {issue.fixes.map((fix) => (
                                    <Button key={fix.label} size={'xsmall'} isSecondary onClick={() => onFix(fix.edits)}>
                                        {fix.label}
                                    </Button>
                                ))}
                                {issue.kind === 'broken' && (
                                    <Button size={'xsmall'} isSecondary onClick={onShowRaw}>
                                        Open the raw file
                                    </Button>
                                )}
                            </div>
                        )}
                    </li>
                ))}
            </ul>
        </TitledGreyBox>
    );
}

function SettingsForm({
    data,
    edits,
    fields,
    readOnly,
    onChange,
}: {
    data: SettingsResponse;
    edits: Record<string, Edit>;
    fields: Map<string, Field>;
    readOnly: boolean;
    onChange: (setting: Setting, value: string | null) => void;
}) {
    return (
        <div className={'pal:flex pal:flex-col pal:gap-4'}>
            {data.parser && (
                <Alert type={'info'}>
                    This egg fills some settings from startup variables every time the server starts. Those settings are changed through the
                    variable, so the change sticks.
                </Alert>
            )}
            {data.schema.groups.map((group) => {
                const items = data.schema.settings.filter((setting) => setting.group === group.id);
                if (items.length === 0) return null;

                return (
                    <TitledGreyBox key={group.id} title={group.label}>
                        <div className={'pal:grid pal:grid-cols-1 pal:gap-x-6 pal:gap-y-4 pal:md:grid-cols-2'}>
                            {items.map((setting) => (
                                <SettingRow
                                    key={setting.key}
                                    setting={setting}
                                    field={fields.get(setting.key.toLowerCase())}
                                    edit={edits[setting.key.toLowerCase()]}
                                    readOnly={readOnly}
                                    onChange={(value) => onChange(setting, value)}
                                />
                            ))}
                        </div>
                    </TitledGreyBox>
                );
            })}
        </div>
    );
}

function lockNote(field: Field | undefined): string | null {
    const lock = field?.lock;
    if (!lock) return null;
    if (lock.kind === 'startup') return 'Set in the startup command, which wins over the file. It cannot be changed here.';
    if (lock.kind === 'allocation') return 'Comes from the server port. Change it on the Network page.';
    return lock.editable
        ? `Also changes the ${lock.env} startup variable, because the egg copies it into the file on every start.`
        : `Comes from the ${lock.env} startup variable, and you may not change it.`;
}

function SettingRow({
    setting,
    field,
    edit,
    readOnly,
    onChange,
}: {
    setting: Setting;
    field: Field | undefined;
    edit: Edit | undefined;
    readOnly: boolean;
    onChange: (value: string | null) => void;
}) {
    const id = `pal-setting-${setting.key}`;
    const saved = field?.value ?? null;
    const changed = edit !== undefined;
    const value = edit ? edit.value : saved;
    const lock = field?.lock ?? null;
    const locked = readOnly || (lock !== null && (lock.kind !== 'variable' || !lock.editable));
    const error = value !== null && !setting.secret ? validateSetting(setting, value) : null;
    const isDefault = value === null;
    const note = lockNote(field);
    const canReset = !locked && !isDefault && !setting.secret && lock === null;

    return (
        <div className={'pal:flex pal:flex-col pal:gap-1'}>
            <div className={'pal:flex pal:items-center pal:justify-between pal:gap-2'}>
                <label htmlFor={id} className={'pal:text-sm pal:font-medium pal:text-foreground'}>
                    {setting.label}
                    {changed && <span className={'pal:ml-2 pal:text-xs pal:text-warning'}>changed</span>}
                </label>
                {canReset && (
                    <button
                        type={'button'}
                        onClick={() => onChange(null)}
                        className={'pal:text-xs pal:text-muted-foreground pal:hover:underline'}
                        title={'Remove the setting so the game uses its default'}
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
                    disabled={locked}
                    label={isDefault ? `Default (${setting.default})` : undefined}
                />
            ) : setting.type === 'enum' ? (
                <Select
                    id={id}
                    value={value ?? setting.default}
                    options={[...new Set([...(setting.options ?? []), value ?? setting.default])].map((option) => ({
                        value: option,
                        label: option,
                    }))}
                    disabled={locked}
                    onChange={(next) => onChange(String(next))}
                />
            ) : setting.type === 'list' ? (
                <div className={'pal:flex pal:flex-wrap pal:gap-x-4 pal:gap-y-1'} id={id}>
                    {(setting.options ?? []).map((option) => {
                        const chosen = (value ?? setting.default).split(',').map((item) => item.trim().toLowerCase());
                        const on = chosen.includes(option.toLowerCase());
                        return (
                            <label key={option} className={'pal:flex pal:items-center pal:gap-2 pal:text-sm pal:text-foreground'}>
                                <Checkbox
                                    checked={on}
                                    disabled={locked}
                                    onChange={(checked) =>
                                        onChange(
                                            (setting.options ?? [])
                                                .filter((item) => (item === option ? checked : chosen.includes(item.toLowerCase())))
                                                .join(','),
                                        )
                                    }
                                />
                                {option}
                            </label>
                        );
                    })}
                </div>
            ) : setting.secret ? (
                <Input
                    id={id}
                    type={'password'}
                    autoComplete={'new-password'}
                    value={edit?.value ?? ''}
                    placeholder={field?.set ? 'Set. Type to change it.' : 'Not set'}
                    disabled={locked}
                    $hasError={false}
                    onChange={(event: React.ChangeEvent<HTMLInputElement>) => onChange(event.target.value)}
                />
            ) : (
                <Input
                    id={id}
                    value={value ?? ''}
                    placeholder={`Default: ${displayDefault(setting) === '' ? 'none' : displayDefault(setting)}`}
                    inputMode={setting.type === 'string' ? 'text' : 'decimal'}
                    disabled={locked}
                    $hasError={error !== null}
                    onChange={(event: React.ChangeEvent<HTMLInputElement>) =>
                        onChange(event.target.value === '' ? null : event.target.value)
                    }
                />
            )}

            {error ? (
                <p className={'pal:text-xs pal:text-destructive'}>{error}</p>
            ) : note ? (
                <p className={'pal:text-xs pal:text-warning'}>{note}</p>
            ) : (
                <p className={'pal:text-xs pal:text-muted-foreground'}>
                    {setting.help}
                    {saved === null &&
                        !changed &&
                        !setting.secret &&
                        setting.type !== 'bool' &&
                        ` Not set, so the game uses ${displayDefault(setting) || 'its default'}.`}
                </p>
            )}
        </div>
    );
}

function RawEditor({
    data,
    value,
    readOnly,
    hasEdits,
    onChange,
}: {
    data: SettingsResponse;
    value: string;
    readOnly: boolean;
    hasEdits: boolean;
    onChange: (text: string) => void;
}) {
    return (
        <div className={'pal:flex pal:flex-col pal:gap-2'}>
            <span className={'pal:font-mono pal:text-xs pal:text-muted-foreground'}>{data.file.path}</span>
            {hasEdits && (
                <Alert type={'info'}>
                    You also have unsaved changes in the Settings tab. They are applied on top of this text when you save.
                </Alert>
            )}
            <TextArea
                aria-label={'PalWorldSettings.ini'}
                spellCheck={false}
                rows={20}
                className={'pal:font-mono pal:text-xs'}
                value={value}
                readOnly={readOnly}
                onChange={(event: React.ChangeEvent<HTMLTextAreaElement>) => onChange(event.target.value)}
            />
            <p className={'pal:text-xs pal:text-muted-foreground'}>
                The settings are one OptionSettings=(...) line. Passwords are shown as ******** and kept as they are when you save. Settings
                the form does not know are kept. Every save keeps a backup of the old file next to it. Startup variables are not changed
                from here.
            </p>
        </div>
    );
}

function PresetDialog({
    preset,
    byKey,
    fields,
    edits,
    onClose,
    onApply,
}: {
    preset: Preset;
    byKey: Map<string, Setting>;
    fields: Map<string, Field>;
    edits: Record<string, Edit>;
    onClose: () => void;
    onApply: (edits: Edit[]) => void;
}) {
    const rows = Object.entries(preset.values).flatMap(([key, value]) => {
        const setting = byKey.get(key.toLowerCase());
        if (!setting) return [];
        const field = fields.get(key.toLowerCase());
        // A setting the startup decides cannot be changed by a preset.
        if (field?.lock && (field.lock.kind !== 'variable' || !field.lock.editable)) return [];
        const pending = edits[key.toLowerCase()];
        const current = pending ? pending.value : (field?.value ?? null);
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
                changes.length === 0 ? onClose() : onApply(changes.map((row) => ({ key: row.setting.key, value: row.value })))
            }
        >
            <p className={'pal:mb-3 pal:text-sm'}>{preset.description} Nothing is saved until you press Save.</p>
            {changes.length === 0 ? (
                <p className={'pal:text-sm pal:text-muted-foreground'}>Your settings already match this preset.</p>
            ) : (
                <table className={'pal:w-full pal:text-sm'}>
                    <thead>
                        <tr className={'pal:text-left pal:text-xs pal:text-muted-foreground'}>
                            <th className={'pal:py-1 pal:font-normal'}>Setting</th>
                            <th className={'pal:py-1 pal:font-normal'}>Now</th>
                            <th className={'pal:py-1 pal:font-normal'}>Preset</th>
                        </tr>
                    </thead>
                    <tbody>
                        {changes.map((row) => (
                            <tr key={row.setting.key} className={'pal:border-t pal:border-border'}>
                                <td className={'pal:py-1'}>{row.setting.label}</td>
                                <td className={'pal:py-1 pal:font-mono pal:text-muted-foreground'}>
                                    {row.current ?? `${displayDefault(row.setting)} (default)`}
                                </td>
                                <td className={'pal:py-1 pal:font-mono'}>{row.value}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}
        </Dialog.Confirm>
    );
}
