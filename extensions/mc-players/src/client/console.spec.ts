import { describe, expect, it } from 'vitest';
import { cleanLine, decodeParam, isValidName, parseConsoleLine, replyNotice } from './console';

describe('console parsing', () => {
    it('strips colours and log prefixes', () => {
        expect(cleanLine('\u001b[33m[12:00:00] [Server thread/INFO]: Steve joined the game\u001b[m')).toBe('Steve joined the game');
        expect(cleanLine('[12:00:00 INFO]: §aHello')).toBe('Hello');
    });

    it('spots joins, leaves and list changes', () => {
        expect(parseConsoleLine('[12:00:00 INFO]: Steve joined the game')).toEqual({ type: 'join', name: 'Steve' });
        expect(parseConsoleLine('[12:00:00 INFO]: .Bedrock_Guy left the game')).toEqual({ type: 'leave', name: '.Bedrock_Guy' });
        expect(parseConsoleLine('[12:00:00 INFO]: Banned Griefer: Griefing')).toEqual({ type: 'lists' });
        expect(parseConsoleLine('[12:00:00 INFO]: Added Alex to the whitelist')).toEqual({ type: 'lists' });
        expect(parseConsoleLine('[12:00:00 INFO]: Made Alex a server operator')).toEqual({ type: 'lists' });
        expect(parseConsoleLine('[12:00:00 INFO]: <Steve> I joined the game lol')).toBeNull();
    });

    it('validates names', () => {
        expect(isValidName('Steve_01')).toBe(true);
        expect(isValidName('.BedrockGuy')).toBe(true);
        expect(isValidName('bad name')).toBe(false);
        expect(isValidName('@a')).toBe(false);
    });
});

describe('route params', () => {
    it('decodes names and survives broken escapes', () => {
        expect(decodeParam('Steve')).toBe('Steve');
        expect(decodeParam('%2EBedrock_Guy')).toBe('.Bedrock_Guy');
        expect(decodeParam('%E0%A4%A')).toBe('');
        expect(decodeParam(undefined)).toBe('');
    });
});

describe('command replies', () => {
    it('shows the server answer', () => {
        expect(replyNotice('Banned Steve: x', 'running', 'Done.')).toEqual({ type: 'success', message: 'Banned Steve: x' });
    });

    it('warns when a running server stays silent', () => {
        expect(replyNotice(null, 'running', 'Done.').type).toBe('warning');
    });

    it('accepts silence from a stopped server, where the files were edited', () => {
        expect(replyNotice(null, 'offline', 'Banned Steve.')).toEqual({ type: 'success', message: 'Banned Steve.' });
    });
});
