import { useState, type FormEvent } from 'react';
import { useMutation } from '@tanstack/react-query';
import {
    Button,
    Input,
    SocketEvent,
    TitledGreyBox,
    httpErrorToHuman,
    toast,
    useServerPermission,
    useServerWebsocketEvent,
    type SdkServer,
} from '@pterodactyl/sdk';
import { MAX_MESSAGE, isTerrariaServer, runAdminCommand, type AdminAction } from './api';

export default function AdminSlot({ data: server }: { data: SdkServer }) {
    const canAdmin = useServerPermission('ext.terraria-tools.admin');
    const canConsole = useServerPermission('control.console');
    if (!isTerrariaServer(server) || !canAdmin || !canConsole) return null;

    return <AdminCard server={server} />;
}

const TIMES: { action: AdminAction; label: string; hint: string }[] = [
    { action: 'dawn', label: 'Dawn', hint: '4:30 AM' },
    { action: 'noon', label: 'Noon', hint: '12:00 PM' },
    { action: 'dusk', label: 'Dusk', hint: '7:30 PM' },
    { action: 'midnight', label: 'Midnight', hint: '12:00 AM' },
];

const DONE: Record<AdminAction, string> = {
    save: 'World saved.',
    settle: 'Liquids are settling.',
    dawn: 'It is dawn.',
    noon: 'It is noon.',
    dusk: 'It is dusk.',
    midnight: 'It is midnight.',
    say: 'Message sent.',
};

/** One line of text without control characters, as the panel side sends it on. */
export function cleanMessage(text: string): string {
    // eslint-disable-next-line no-control-regex
    return text.replace(/[\u0000-\u001f\u007f-\u009f\u2028\u2029]+/g, ' ').trim();
}

export function AdminCard({ server }: { server: SdkServer }) {
    const identifier = server.attributes.identifier;
    const [message, setMessage] = useState('');
    // Only known once the server reports; until then the buttons are tried and the panel answers.
    const [state, setState] = useState<string | null>(null);

    useServerWebsocketEvent(SocketEvent.STATUS, (status) => setState(status));

    const command = useMutation({
        mutationFn: (input: { action: AdminAction; message?: string }) => runAdminCommand(identifier, input.action, input.message),
        onSuccess: (_, input) => {
            toast.success(DONE[input.action]);
            if (input.action === 'say') setMessage('');
        },
        onError: (error) => toast.error(httpErrorToHuman(error)),
    });

    const text = cleanMessage(message);
    const tooLong = [...text].length > MAX_MESSAGE;
    const offline = state !== null && state !== 'running';
    const busy = command.isPending || offline;

    const say = (event?: FormEvent) => {
        event?.preventDefault();
        if (text !== '' && !tooLong) command.mutate({ action: 'say', message: text });
    };

    return (
        <TitledGreyBox title={'Server admin'}>
            <div className={'terra:flex terra:flex-col terra:gap-4'}>
                <div className={'terra:flex terra:flex-wrap terra:gap-2'}>
                    <Button size={'small'} isSecondary disabled={busy} onClick={() => command.mutate({ action: 'save' })}>
                        Save world
                    </Button>
                    <Button size={'small'} isSecondary disabled={busy} onClick={() => command.mutate({ action: 'settle' })}>
                        Settle liquids
                    </Button>
                </div>

                <div className={'terra:flex terra:flex-col terra:gap-2'}>
                    <p className={'terra:text-sm terra:font-medium terra:text-foreground'}>Time</p>
                    <div className={'terra:flex terra:flex-wrap terra:gap-2'}>
                        {TIMES.map((time) => (
                            <Button
                                key={time.action}
                                size={'small'}
                                isSecondary
                                disabled={busy}
                                title={time.hint}
                                onClick={() => command.mutate({ action: time.action })}
                            >
                                {time.label}
                            </Button>
                        ))}
                    </div>
                </div>

                <form className={'terra:flex terra:flex-col terra:gap-1'} onSubmit={say}>
                    <div className={'terra:flex terra:flex-wrap terra:gap-2'}>
                        <div className={'terra:min-w-48 terra:flex-1'}>
                            <Input
                                value={message}
                                maxLength={MAX_MESSAGE * 2}
                                placeholder={'Message to all players'}
                                aria-label={'Message to all players'}
                                $hasError={tooLong}
                                onChange={(event) => setMessage(event.target.value)}
                            />
                        </div>
                        <Button size={'small'} type={'submit'} disabled={busy || text === '' || tooLong}>
                            Say
                        </Button>
                    </div>
                    <p className={tooLong ? 'terra:text-xs terra:text-destructive' : 'terra:text-xs terra:text-muted-foreground'}>
                        {tooLong ? `A message can be at most ${MAX_MESSAGE} characters.` : 'Shown in chat to everyone as a server message.'}
                    </p>
                </form>
            </div>
        </TitledGreyBox>
    );
}
