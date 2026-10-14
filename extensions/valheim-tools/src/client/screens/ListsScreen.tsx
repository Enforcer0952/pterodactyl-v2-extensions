import { useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
    Alert,
    Button,
    Dialog,
    Input,
    ServerContentBlock,
    Spinner,
    TitledGreyBox,
    httpErrorToHuman,
    toast,
    useCurrentServerRequired,
} from '@pterodactyl/sdk';
import { addToList, getLists, keys, removeFromList, type ListEntry, type ListKind, type ListsResponse } from '../api';
import { formatDate, needsPermitConfirm } from '../format';
import { Empty, Row, Rows } from '../shared';

const LISTS: { kind: ListKind; title: string; help: string }[] = [
    { kind: 'admin', title: 'Admins', help: 'Admins can kick, ban and use the admin commands in the game.' },
    { kind: 'banned', title: 'Banned', help: 'Banned players cannot join.' },
    {
        kind: 'permitted',
        title: 'Permitted',
        help: 'When anyone is on this list, only the players on it can join. Leave it empty to let everyone in.',
    },
];

export default function ListsScreen() {
    const server = useCurrentServerRequired();
    const identifier = server.attributes.identifier;
    const queryClient = useQueryClient();
    const [removing, setRemoving] = useState<{ kind: ListKind; entry: ListEntry } | null>(null);
    const [firstPermit, setFirstPermit] = useState<string | null>(null);

    const lists = useQuery({ queryKey: keys.lists(identifier), queryFn: () => getLists(identifier), refetchOnWindowFocus: true });

    const add = useMutation({
        mutationFn: (input: { kind: ListKind; id: string; confirm?: boolean }) =>
            addToList(identifier, input.kind, input.id, input.confirm),
        onSuccess: (result) => {
            queryClient.setQueryData<ListsResponse>(keys.lists(identifier), result);
            toast.success(result.added ? 'Added. The server reads the lists when it starts.' : 'That player is on the list already.');
            setFirstPermit(null);
        },
        onError: (error, input) => {
            const message = httpErrorToHuman(error);
            if (input.kind === 'permitted' && !input.confirm && needsPermitConfirm(message)) {
                setFirstPermit(input.id);
                return;
            }
            toast.error(message);
            setFirstPermit(null);
        },
    });

    const remove = useMutation({
        mutationFn: (input: { kind: ListKind; id: string }) => removeFromList(identifier, input.kind, input.id),
        onSuccess: (result) => {
            queryClient.setQueryData<ListsResponse>(keys.lists(identifier), result);
            toast.success('Removed. The server reads the lists when it starts.');
            setRemoving(null);
        },
        onError: (error) => {
            toast.error(httpErrorToHuman(error));
            setRemoving(null);
        },
    });

    if (lists.isLoading) {
        return (
            <ServerContentBlock title={'Access lists'}>
                <Spinner centered />
            </ServerContentBlock>
        );
    }

    if (lists.error || !lists.data) {
        return (
            <ServerContentBlock title={'Access lists'}>
                <Alert type={'danger'}>{httpErrorToHuman(lists.error)}</Alert>
            </ServerContentBlock>
        );
    }

    const data = lists.data;
    const permitted = data.lists.permitted.entries.length > 0;

    return (
        <ServerContentBlock title={'Access lists'}>
            <div className={'vh:flex vh:flex-col vh:gap-4'}>
                <Alert type={'info'}>
                    These are the files adminlist.txt, bannedlist.txt and permittedlist.txt in {data.directory}. Changes are saved to the
                    files; Valheim reads them when it starts, so they apply after a restart.
                </Alert>
                {permitted && (
                    <Alert type={'warning'}>
                        The permitted list has {data.lists.permitted.entries.length} player(s). Only they can join this server.
                    </Alert>
                )}
                {!data.editable && (
                    <Alert type={'warning'}>You can look at the lists but not change them: that needs the file update permission.</Alert>
                )}

                {LISTS.map(({ kind, title, help }) => (
                    <TitledGreyBox key={kind} title={`${title} (${data.lists[kind].entries.length})`}>
                        <div className={'vh:flex vh:flex-col vh:gap-3'}>
                            <p className={'vh:text-xs vh:text-muted-foreground'}>{help}</p>
                            {data.lists[kind].entries.length === 0 ? (
                                <Empty>
                                    {data.lists[kind].exists ? 'Nobody is on this list.' : `${data.lists[kind].file} does not exist yet.`}
                                </Empty>
                            ) : (
                                <Rows>
                                    {data.lists[kind].entries.map((entry) => (
                                        <Row
                                            key={entry.id}
                                            name={entry.name ?? 'Unknown name'}
                                            detail={[entry.id, entry.last_seen ? `last seen ${formatDate(entry.last_seen)}` : '']
                                                .filter(Boolean)
                                                .join(' · ')}
                                        >
                                            {data.editable && (
                                                <Button
                                                    size={'xsmall'}
                                                    isSecondary
                                                    disabled={remove.isPending}
                                                    onClick={() => setRemoving({ kind, entry })}
                                                >
                                                    Remove
                                                </Button>
                                            )}
                                        </Row>
                                    ))}
                                </Rows>
                            )}
                            {data.editable && (
                                <AddForm
                                    label={title}
                                    pending={add.isPending && add.variables?.kind === kind}
                                    onAdd={(id) => add.mutate({ kind, id })}
                                />
                            )}
                        </div>
                    </TitledGreyBox>
                ))}
            </div>

            {removing && (
                <Dialog.Confirm
                    open
                    title={`Remove ${removing.entry.name ?? removing.entry.id}?`}
                    confirm={'Remove'}
                    pending={remove.isPending}
                    onClose={() => setRemoving(null)}
                    onConfirmed={() => remove.mutate({ kind: removing.kind, id: removing.entry.id })}
                >
                    <p className={'vh:text-sm'}>
                        {removing.entry.id} is taken off the {removing.kind} list. The old file is kept next to it as a .bak.
                    </p>
                </Dialog.Confirm>
            )}

            {firstPermit && (
                <Dialog.Confirm
                    open
                    title={'Turn on the permitted list?'}
                    confirm={'Add player'}
                    pending={add.isPending}
                    onClose={() => setFirstPermit(null)}
                    onConfirmed={() => add.mutate({ kind: 'permitted', id: firstPermit, confirm: true })}
                >
                    <p className={'vh:text-sm'}>
                        The permitted list is empty, so everyone may join now. Once {firstPermit} is on it, only the players on it can join.
                    </p>
                </Dialog.Confirm>
            )}
        </ServerContentBlock>
    );
}

function AddForm({ label, pending, onAdd }: { label: string; pending: boolean; onAdd: (id: string) => void }) {
    const [value, setValue] = useState('');
    const trimmed = value.trim();

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (trimmed === '') return;
        onAdd(trimmed);
        setValue('');
    };

    return (
        <form className={'vh:flex vh:gap-2'} onSubmit={submit}>
            <Input
                aria-label={`Add to ${label}`}
                value={value}
                maxLength={100}
                placeholder={'Steam ID or profile link'}
                onChange={(event) => setValue(event.target.value)}
            />
            <Button type={'submit'} size={'small'} isLoading={pending} disabled={trimmed === ''}>
                Add
            </Button>
        </form>
    );
}
