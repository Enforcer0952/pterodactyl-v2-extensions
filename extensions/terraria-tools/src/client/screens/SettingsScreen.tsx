import { useEffect, useState, type ReactNode } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
    Alert,
    Button,
    Input,
    Select,
    ServerContentBlock,
    Spinner,
    TextArea,
    TitledGreyBox,
    httpErrorToHuman,
    toast,
    useCurrentServerRequired,
} from '@pterodactyl/sdk';
import { getSettings, keys, saveSettings, validateField, type SettingField, type SettingsResponse } from '../api';
import { skippedMessage } from '../format';

type Tab = 'form' | 'raw';

export default function SettingsScreen() {
    const server = useCurrentServerRequired();
    const identifier = server.attributes.identifier;
    const queryClient = useQueryClient();
    const [tab, setTab] = useState<Tab>('form');
    const [edits, setEdits] = useState<Record<string, string>>({});
    const [raw, setRaw] = useState<string | null>(null);
    // The file version the unsaved changes were made against. A refetch while
    // editing must not move it, or a stale draft would overwrite a newer file.
    const [baseHash, setBaseHash] = useState<string | null>(null);

    const settings = useQuery({
        queryKey: keys.settings(identifier),
        queryFn: () => getSettings(identifier),
        refetchOnWindowFocus: baseHash === null,
    });

    const data = settings.data;
    const rawChanged = raw !== null && raw !== data?.raw;
    const editKeys = Object.keys(edits);
    const changes = editKeys.length + (rawChanged ? 1 : 0);
    const dirty = changes > 0;
    const invalid = (data?.fields ?? []).some((field) => edits[field.key] !== undefined && validateField(field, edits[field.key]) !== null);

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
        if (baseHash === null && data) setBaseHash(data.hash);
    };

    const setValue = (field: SettingField, value: string) => {
        startEditing();
        setEdits((current) => {
            const next = { ...current };
            if (value === (field.value ?? '')) delete next[field.key];
            else next[field.key] = value;
            return next;
        });
    };

    const save = useMutation({
        mutationFn: () =>
            saveSettings(identifier, {
                raw: rawChanged ? (raw ?? '') : undefined,
                edits: editKeys.length > 0 ? edits : undefined,
                hash: baseHash ?? data?.hash ?? '',
            }),
        onSuccess: (result) => {
            queryClient.setQueryData<SettingsResponse>(keys.settings(identifier), result);
            reset();
            if (!result.written) toast.success('Nothing to save: the file already has these settings.');
            else toast.success(result.state === 'offline' ? 'Settings saved.' : 'Settings saved. Restart the server to apply them.');
            if (result.variables.skipped.length > 0) toast.info(skippedMessage(result.variables));
        },
        onError: (error) => toast.error(httpErrorToHuman(error)),
    });

    if (settings.isLoading) {
        return (
            <ServerContentBlock title={'Server settings'}>
                <Spinner centered />
            </ServerContentBlock>
        );
    }

    if (settings.error || !data) {
        return (
            <ServerContentBlock title={'Server settings'}>
                <Alert type={'danger'}>{httpErrorToHuman(settings.error)}</Alert>
            </ServerContentBlock>
        );
    }

    return (
        <ServerContentBlock title={'Server settings'}>
            <div className={'terra:flex terra:flex-col terra:gap-4 terra:pb-24'}>
                {!data.exists && (
                    <Alert type={'info'}>
                        serverconfig.txt does not exist yet. Start the server once so the egg creates it, or save here to create it.
                    </Alert>
                )}
                {!data.editable && (
                    <Alert type={'warning'}>
                        You can look at these settings but not change them: changing needs the file update permission.
                    </Alert>
                )}
                {data.state !== 'offline' && data.editable && (
                    <Alert type={'info'}>The server only reads serverconfig.txt when it starts. Saved changes apply after a restart.</Alert>
                )}

                <div
                    className={'terra:flex terra:gap-1 terra:self-start terra:rounded-md terra:border terra:border-border terra:p-1'}
                    role={'tablist'}
                >
                    <TabButton active={tab === 'form'} onClick={() => setTab('form')}>
                        Settings
                    </TabButton>
                    <TabButton active={tab === 'raw'} onClick={() => setTab('raw')}>
                        Raw file
                    </TabButton>
                </div>

                {tab === 'form' ? (
                    <TitledGreyBox title={'serverconfig.txt'}>
                        <div className={'terra:flex terra:flex-col terra:gap-4'}>
                            <div className={'terra:grid terra:grid-cols-1 terra:gap-x-6 terra:gap-y-4 terra:md:grid-cols-2'}>
                                {data.fields.map((field) => (
                                    <FieldRow
                                        key={field.key}
                                        field={field}
                                        value={edits[field.key] ?? field.value ?? ''}
                                        changed={edits[field.key] !== undefined}
                                        disabled={!data.editable}
                                        onChange={(value) => setValue(field, value)}
                                    />
                                ))}
                            </div>
                            <p className={'terra:text-xs terra:text-muted-foreground'}>
                                Eggs that fill this file from startup variables write them again when the server starts. When you may change
                                the startup variables, they are updated along with the file. The port and any other keys are left alone; use
                                the raw file for those.
                            </p>
                        </div>
                    </TitledGreyBox>
                ) : (
                    <div className={'terra:flex terra:flex-col terra:gap-2'}>
                        {editKeys.length > 0 && (
                            <Alert type={'info'}>
                                You also have unsaved changes in the Settings tab. They are applied on top of this text when you save.
                            </Alert>
                        )}
                        <TextArea
                            aria-label={'serverconfig.txt'}
                            spellCheck={false}
                            rows={22}
                            className={'terra:font-mono terra:text-xs'}
                            value={raw ?? data.raw}
                            readOnly={!data.editable}
                            placeholder={data.exists ? '' : 'This file does not exist yet.'}
                            onChange={(event: React.ChangeEvent<HTMLTextAreaElement>) => {
                                startEditing();
                                setRaw(event.target.value);
                            }}
                        />
                        <p className={'terra:text-xs terra:text-muted-foreground'}>
                            Comments and unknown keys are kept as you write them. Every save keeps the old file next to it as
                            serverconfig.txt.&lt;date&gt;.bak (the newest five stay).
                            {data.masked && ' The password is hidden because you may not edit the file or the startup variables.'}
                        </p>
                    </div>
                )}
            </div>

            {dirty && (
                <div
                    className={
                        'terra:fixed terra:inset-x-0 terra:bottom-0 terra:z-30 terra:border-t terra:border-border terra:bg-background/95 terra:px-4 terra:py-3 terra:backdrop-blur'
                    }
                >
                    <div
                        className={
                            'terra:mx-auto terra:flex terra:max-w-5xl terra:flex-wrap terra:items-center terra:justify-between terra:gap-3'
                        }
                    >
                        <p className={'terra:text-sm terra:text-foreground'}>
                            {changes} unsaved change{changes === 1 ? '' : 's'}
                            {invalid && <span className={'terra:ml-2 terra:text-destructive'}>Fix the highlighted values first.</span>}
                        </p>
                        <div className={'terra:flex terra:gap-2'}>
                            <Button isSecondary onClick={reset} disabled={save.isPending}>
                                Discard
                            </Button>
                            <Button onClick={() => save.mutate()} isLoading={save.isPending} disabled={invalid || !data.editable}>
                                Save
                            </Button>
                        </div>
                    </div>
                </div>
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
            className={`terra:rounded terra:px-3 terra:py-1 terra:text-sm ${active ? 'terra:bg-muted terra:text-foreground' : 'terra:text-muted-foreground terra:hover:text-foreground'}`}
        >
            {children}
        </button>
    );
}

function FieldRow({
    field,
    value,
    changed,
    disabled,
    onChange,
}: {
    field: SettingField;
    value: string;
    changed: boolean;
    disabled: boolean;
    onChange: (value: string) => void;
}) {
    const id = `terra-setting-${field.key}`;
    const [show, setShow] = useState(false);
    const hidden = field.kind === 'secret' && field.value === null && field.set;
    const error = changed ? validateField(field, value) : null;

    return (
        <div className={'terra:flex terra:flex-col terra:gap-1'}>
            <div className={'terra:flex terra:items-center terra:justify-between terra:gap-2'}>
                <label htmlFor={id} className={'terra:text-sm terra:font-medium terra:text-foreground'}>
                    {field.label}
                    {changed && <span className={'terra:ml-2 terra:text-xs terra:text-warning'}>changed</span>}
                </label>
                {field.kind === 'secret' && !hidden && (
                    <button
                        type={'button'}
                        onClick={() => setShow(!show)}
                        className={'terra:text-xs terra:text-muted-foreground terra:hover:underline'}
                    >
                        {show ? 'Hide' : 'Show'}
                    </button>
                )}
            </div>

            {field.kind === 'choice' ? (
                <Select
                    id={id}
                    options={(field.choices ?? []).map((choice) => ({ value: choice.value, label: choice.label }))}
                    value={value}
                    disabled={disabled}
                    placeholder={'Not set'}
                    onChange={(next) => onChange(String(next))}
                />
            ) : hidden ? (
                <Input id={id} value={'********'} disabled readOnly aria-label={field.label} />
            ) : (
                <Input
                    id={id}
                    type={field.kind === 'secret' && !show ? 'password' : 'text'}
                    autoComplete={field.kind === 'secret' ? 'new-password' : 'off'}
                    inputMode={field.kind === 'int' ? 'numeric' : 'text'}
                    value={value}
                    maxLength={field.max && field.kind !== 'int' ? field.max : undefined}
                    disabled={disabled}
                    placeholder={field.present ? '' : 'Not set'}
                    $hasError={error !== null}
                    onChange={(event: React.ChangeEvent<HTMLInputElement>) => onChange(event.target.value)}
                />
            )}

            {error ? (
                <p className={'terra:text-xs terra:text-destructive'}>{error}</p>
            ) : (
                <p className={'terra:text-xs terra:text-muted-foreground'}>
                    {hidden ? 'A password is set. It is hidden because you may not edit the file or the startup variables.' : field.help}
                </p>
            )}
        </div>
    );
}
