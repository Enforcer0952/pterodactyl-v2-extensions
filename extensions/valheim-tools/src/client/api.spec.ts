import { expect, it } from 'vitest';
import { isValheim, usesBepInEx, validWorldName, validateName, validatePassword } from './api';

it('detects Valheim servers like the panel side', () => {
    expect(isValheim(['valheim'], '')).toBe(true);
    expect(isValheim(['Valheim'], '')).toBe(true);
    expect(isValheim([], './valheim_server.x86_64 -nographics -batchmode')).toBe(true);
    expect(isValheim(['minecraft'], 'java -jar server.jar')).toBe(false);
    expect(isValheim(null, undefined)).toBe(false);
});

it('detects BepInEx in the startup command', () => {
    expect(usesBepInEx('export DOORSTOP_TARGET_ASSEMBLY=./BepInEx/core/BepInEx.Preloader.dll; ./valheim_server.x86_64')).toBe(true);
    expect(usesBepInEx('./valheim_server.x86_64 -nographics')).toBe(false);
});

it('validates world names like the panel side', () => {
    for (const name of ['Dedicated', 'My World', 'Midgard_2', 'a', 'World-1.5']) expect(validWorldName(name)).toBe(true);
    for (const name of ['', ' x', 'x ', '.hidden', 'a..b', 'a/b', 'a\\b', 'a"b', 'a$b', 'a`b', 'a'.repeat(21)]) {
        expect(validWorldName(name)).toBe(false);
    }
});

it('validates the server name', () => {
    expect(validateName("Anton's Valheim", 60)).toBeNull();
    for (const bad of ['', ' x', 'x ', 'a"b', 'a$b', 'a`b', 'a\\b', 'a{{b}}', 'a'.repeat(61)]) expect(validateName(bad, 60)).not.toBeNull();
});

it('validates the password', () => {
    expect(validatePassword('secret', 'Dedicated', 5, 20)).toBeNull();
    for (const bad of ['abcd', 'a'.repeat(21), 'has space', 'a"bcde', 'a$bcde']) {
        expect(validatePassword(bad, 'Dedicated', 5, 20)).not.toBeNull();
    }
    expect(validatePassword('secret', 'My secret world', 5, 20)).not.toBeNull();
    expect(validatePassword('secret', null, 5, 20)).toBeNull();
});
