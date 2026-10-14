import { expect, it } from 'vitest';
import { cleanLine, presence } from './console';

it('strips colours, controls and the timestamp', () => {
    expect(cleanLine('\u001b[32m10/14/2026 18:02:11: Game server connected\u001b[0m\r')).toBe('Game server connected');
    expect(cleanLine('plain')).toBe('plain');
});

it('recognises joins, leaves and starts', () => {
    expect(presence('10/14/2026 18:02:52: Got character ZDOID from Big Bob : -438505887:1')).toBe('join');
    expect(presence('Got character ZDOID from Ragnar : 1305773203:1')).toBe('join');
    expect(presence('10/14/2026 18:12:30: Destroying abandoned non persistent zdo 1305773203:5 owner 1305773203')).toBe('leave');
    expect(presence('10/14/2026 18:02:11: Game server connected')).toBe('start');
});

it('ignores a death and other lines', () => {
    expect(presence('Got character ZDOID from Ragnar : 0:0')).toBeNull();
    expect(presence('Got handshake from client 76561198051014133')).toBeNull();
    expect(presence('')).toBeNull();
});

it('keeps odd player names as data', () => {
    expect(presence('Got character ZDOID from <b>"Bob" : 1;rm -rf : 333:1')).toBe('join');
});
