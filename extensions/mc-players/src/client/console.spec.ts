import { describe, expect, it } from 'vitest';
import { cleanLine, isValidName, parseConsoleLine } from './console';

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
