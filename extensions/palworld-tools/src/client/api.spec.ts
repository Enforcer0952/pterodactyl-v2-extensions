import { expect, it } from 'vitest';
import { USER_ID, displayDefault, isPalworldServer, sameValue, toBase64, validateSetting, type Setting } from './api';

const setting = (extra: Partial<Setting>): Setting => ({
    key: 'X',
    group: 'g',
    type: 'float',
    default: '1.000000',
    label: 'X',
    help: '',
    ...extra,
});

it('checks numbers against type and range', () => {
    const rate = setting({ min: 0 });
    expect(validateSetting(rate, '2.5')).toBeNull();
    expect(validateSetting(rate, '-1')).toBe('At least 0.');
    expect(validateSetting(rate, 'fast')).toBe('Enter a number.');
    expect(validateSetting(rate, '1e3')).toBe('Enter a number.');
    const slots = setting({ type: 'int', min: 1, max: 32 });
    expect(validateSetting(slots, '16')).toBeNull();
    expect(validateSetting(slots, '33')).toBe('At most 32.');
    expect(validateSetting(slots, '1.5')).toBe('Enter a whole number.');
});

it('checks booleans, enums, lists and strings', () => {
    expect(validateSetting(setting({ type: 'bool' }), 'true')).toBeNull();
    expect(validateSetting(setting({ type: 'bool' }), 'yes')).toBe('Must be True or False.');
    const penalty = setting({ type: 'enum', options: ['None', 'Item', 'All'] });
    expect(validateSetting(penalty, 'item')).toBeNull();
    expect(validateSetting(penalty, 'Everything')).toBe('Pick one of the options.');
    const platforms = setting({ type: 'list', options: ['Steam', 'Xbox'] });
    expect(validateSetting(platforms, 'Steam,Xbox')).toBeNull();
    expect(validateSetting(platforms, 'Steam,Switch')).toBe('Use only the listed values.');
    const name = setting({ type: 'string', max: 5 });
    expect(validateSetting(name, 'abc')).toBeNull();
    expect(validateSetting(name, 'abcdef')).toBe('At most 5 characters.');
    expect(validateSetting(name, 'a\nb')).toBe('No line breaks.');
});

it('compares values the way the game reads them', () => {
    expect(sameValue('2', '2.000000')).toBe(true);
    expect(sameValue('true', 'True')).toBe(true);
    expect(sameValue('1', '2')).toBe(false);
    expect(sameValue(null, null)).toBe(true);
    expect(sameValue(null, '1')).toBe(false);
});

it('shows defaults in plain form', () => {
    expect(displayDefault(setting({ default: '1.000000' }))).toBe('1');
    expect(displayDefault(setting({ type: 'list', default: '(Steam,Xbox)' }))).toBe('Steam,Xbox');
    expect(displayDefault(setting({ type: 'int', default: '8211' }))).toBe('8211');
});

it('encodes text as base64 with UTF-8', () => {
    expect(toBase64('abc')).toBe('YWJj');
    expect(atob(toBase64('Pál\n'))).toBe(String.fromCharCode(...new TextEncoder().encode('Pál\n')));
});

it('recognises Palworld servers', () => {
    const server = (tags: string[], invocation: string) => ({ attributes: { egg_tags: tags, invocation } }) as never;
    expect(isPalworldServer(server(['palworld'], ''))).toBe(true);
    expect(isPalworldServer(server(['steamcmd'], './Pal/Binaries/Linux/PalServer-Linux-Shipping Pal'))).toBe(true);
    expect(isPalworldServer(server(['ark'], './ShooterGameServer'))).toBe(false);
    expect(isPalworldServer(null)).toBe(false);
});

it('accepts only safe player ids', () => {
    expect(USER_ID.test('steam_76561198000000000')).toBe(true);
    expect(USER_ID.test('a b')).toBe(false);
    expect(USER_ID.test('../x')).toBe(false);
});
