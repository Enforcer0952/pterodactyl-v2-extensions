import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
    Alert,
    Button,
    Input,
    Select,
    Spinner,
    Switch,
    TitledGreyBox,
    httpErrorToHuman,
    toast,
    useServerPermission,
    type SdkServer,
} from '@pterodactyl/sdk';
import {
    getSettings,
    isValheimServer,
    keys,
    saveSettings,
    validateName,
    validatePassword,
    type SettingsInput,
    type SettingsResponse,
} from './api';
import { Field } from './shared';

export default function SettingsSlot({ data: server }: { data: SdkServer }) {
    const allowed = useServerPermission('ext.valheim-tools.settings');
    const canRead = useServerPermission('startup.read');
    if (!allowed || !canRead || !isValheimServer(server)) return null;

    return <SettingsCard server={server} />;
}

export function SettingsCard({ server }: { server: SdkServer }) {
    const identifier = server.attributes.identifier;
    const queryClient = useQueryClient();
    const settings = useQuery({ queryKey: keys.settings(identifier), queryFn: () => getSettings(identifier), staleTime: 30_000 });

    const [name, setName] = useState<string | null>(null);
    const [password, setPassword] = useState<string | null>(null);
    const [visible, setVisible] = useState(false);
    const [isPublic, setPublic] = useState<boolean | null>(null);
    const [crossplay, setCrossplay] = useState<boolean | null>(null);
    const [preset, setPreset] = useState<string | null>(null);

    const data = settings.data;
    const reset = () => {
        setName(null);
        setPassword(null);
        setPublic(null);
        setCrossplay(null);
        setPreset(null);
    };

    const save = useMutation({
        mutationFn: (input: SettingsInput) => saveSettings(identifier, input),
        onSuccess: (result) => {
            queryClient.setQueryData<SettingsResponse>(keys.settings(identifier), result);
            reset();
            toast.success('Settings saved. They apply the next time the server starts.');
            if (result.warning) toast.info(result.warning);
        },
        onError: (error) => toast.error(httpErrorToHuman(error)),
    });

    const dirty = name !== null || password !== null || isPublic !== null || crossplay !== null || preset !== null;
    useEffect(() => {
        if (!dirty) return;
        const warn = (event: BeforeUnloadEvent) => event.preventDefault();
        window.addEventListener('beforeunload', warn);
        return () => window.removeEventListener('beforeunload', warn);
    }, [dirty]);

    if (settings.isLoading) {
        return (
            <TitledGreyBox title={'Server settings'}>
                <Spinner centered size={'small'} />
            </TitledGreyBox>
        );
    }
    if (settings.error || !data) {
        return (
            <TitledGreyBox title={'Server settings'}>
                <Alert type={'danger'}>{httpErrorToHuman(settings.error)}</Alert>
            </TitledGreyBox>
        );
    }

    const shownName = name ?? data.name.value ?? '';
    const shownPassword = password ?? data.password.value ?? '';
    const nameError = name !== null ? validateName(name, data.limits.name) : null;
    const passwordError =
        password !== null ? validatePassword(password, data.world, data.limits.password_min, data.limits.password_max) : null;
    const canEdit = data.can_update;
    const nothing = !data.name.present && !data.password.present && !data.public.present && !data.crossplay.present && !data.preset.present;

    const submit = () => {
        const input: SettingsInput = {};
        if (name !== null) input.name = name;
        if (password !== null) input.password = password;
        if (isPublic !== null) input.public = isPublic;
        if (crossplay !== null) input.crossplay = crossplay;
        if (preset !== null) input.preset = preset;
        save.mutate(input);
    };

    return (
        <TitledGreyBox title={'Server settings'}>
            {nothing ? (
                <p className={'vh:py-2 vh:text-sm vh:text-muted-foreground'}>
                    This egg has none of the startup variables used here (SERVER_NAME, PASSWORD, PUBLIC_SERVER, ENABLE_CROSSPLAY).
                </p>
            ) : (
                <div className={'vh:flex vh:flex-col vh:gap-4'}>
                    <div className={'vh:grid vh:grid-cols-1 vh:gap-4 vh:md:grid-cols-2'}>
                        {data.name.present && (
                            <Field label={'Server name'} htmlFor={'vh-name'} error={nameError}>
                                <Input
                                    id={'vh-name'}
                                    value={shownName}
                                    $hasError={nameError !== null}
                                    disabled={!canEdit || !data.name.editable}
                                    onChange={(event) =>
                                        setName(event.target.value === (data.name.value ?? '') ? null : event.target.value)
                                    }
                                />
                            </Field>
                        )}
                        {data.password.present && (
                            <Field label={'Password'} htmlFor={'vh-password'} error={passwordError}>
                                <div className={'vh:flex vh:gap-2'}>
                                    <Input
                                        id={'vh-password'}
                                        type={visible ? 'text' : 'password'}
                                        autoComplete={'off'}
                                        value={shownPassword}
                                        $hasError={passwordError !== null}
                                        disabled={!canEdit || !data.password.editable}
                                        onChange={(event) =>
                                            setPassword(event.target.value === (data.password.value ?? '') ? null : event.target.value)
                                        }
                                    />
                                    <Button.Text size={'xsmall'} type={'button'} onClick={() => setVisible((current) => !current)}>
                                        {visible ? 'Hide' : 'Show'}
                                    </Button.Text>
                                </div>
                            </Field>
                        )}
                        {data.preset.present && (
                            <Field label={'World preset'}>
                                <Select
                                    options={['', ...data.presets].map((value) => ({ value, label: value === '' ? 'Default' : value }))}
                                    value={preset ?? (data.preset.value ?? '').toLowerCase()}
                                    disabled={!canEdit || !data.preset.editable}
                                    onChange={(value) =>
                                        setPreset(String(value) === (data.preset.value ?? '').toLowerCase() ? null : String(value))
                                    }
                                />
                            </Field>
                        )}
                    </div>
                    <div className={'vh:flex vh:flex-col vh:gap-2'}>
                        {data.public.present && (
                            <Switch
                                label={'Show in the server list'}
                                description={'Off means players join with the IP address.'}
                                checked={isPublic ?? data.public.value === '1'}
                                disabled={!canEdit || !data.public.editable}
                                onChange={(checked) => setPublic(checked === (data.public.value === '1') ? null : checked)}
                            />
                        )}
                        {data.crossplay.present && (
                            <Switch
                                label={'Crossplay'}
                                description={
                                    'Lets Xbox and Microsoft Store players join. Needs the PlayFab backend; Steam-only servers turn it off.'
                                }
                                checked={crossplay ?? data.crossplay.value === '1'}
                                disabled={!canEdit || !data.crossplay.editable}
                                onChange={(checked) => setCrossplay(checked === (data.crossplay.value === '1') ? null : checked)}
                            />
                        )}
                    </div>
                    {data.warning && <Alert type={'warning'}>{data.warning}</Alert>}
                    {canEdit ? (
                        <div className={'vh:flex vh:items-center vh:gap-3'}>
                            <Button
                                size={'small'}
                                isLoading={save.isPending}
                                disabled={!dirty || nameError !== null || passwordError !== null}
                                onClick={submit}
                            >
                                Save settings
                            </Button>
                            {dirty && (
                                <Button.Text size={'small'} onClick={reset}>
                                    Discard
                                </Button.Text>
                            )}
                            <span className={'vh:text-xs vh:text-muted-foreground'}>Applies the next time the server starts.</span>
                        </div>
                    ) : (
                        <p className={'vh:text-xs vh:text-muted-foreground'}>You can look at these settings but not change them.</p>
                    )}
                </div>
            )}
        </TitledGreyBox>
    );
}
