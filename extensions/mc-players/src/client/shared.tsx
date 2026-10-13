import { useState } from 'react';
import { Dialog, Input } from '@pterodactyl/sdk';
import type { Action } from './api';

export interface Prompt {
    action: Extract<Action, 'kick' | 'ban'>;
    name: string;
}

export function ReasonDialog({
    prompt,
    pending,
    onClose,
    onConfirm,
}: {
    prompt: Prompt;
    pending: boolean;
    onClose: () => void;
    onConfirm: (reason: string) => void;
}) {
    const [reason, setReason] = useState('');
    const verb = prompt.action === 'ban' ? 'Ban' : 'Kick';

    return (
        <Dialog.Confirm
            open
            title={`${verb} ${prompt.name}?`}
            confirm={verb}
            pending={pending}
            onClose={onClose}
            onConfirmed={() => onConfirm(reason)}
        >
            <div className={'mcp:flex mcp:flex-col mcp:gap-2'}>
                <p className={'mcp:text-sm'}>
                    {prompt.action === 'ban'
                        ? `${prompt.name} is disconnected and cannot join again until unbanned.`
                        : `${prompt.name} is disconnected but can join again.`}
                </p>
                <Input
                    value={reason}
                    placeholder={'Reason (optional)'}
                    aria-label={'Reason'}
                    maxLength={120}
                    onChange={(event) => setReason(event.target.value)}
                />
            </div>
        </Dialog.Confirm>
    );
}

export function banDetail(ban: { reason: string | null; source: string | null; created: string | null }): string {
    const date = ban.created ? new Date(ban.created.replace(' ', 'T').replace(/ ([+-]\d{2})(\d{2})$/, '$1:$2')) : null;
    return [ban.reason, ban.source ? `by ${ban.source}` : null, date && !Number.isNaN(date.getTime()) ? date.toLocaleDateString() : null]
        .filter(Boolean)
        .join(' · ');
}
