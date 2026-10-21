import { describe, expect, it } from 'vitest';
import type { Item } from './api';
import {
    durability,
    formatCoord,
    formatDuration,
    formatNumber,
    initials,
    isPlayerRef,
    itemTitle,
    parseCoordinate,
    percent,
    roman,
    slotName,
} from './format';
import { teleportRequest } from './Actions';

const item = (patch: Partial<Item> = {}): Item => ({
    slot: 0,
    id: 'minecraft:diamond_sword',
    name: 'Diamond Sword',
    modded: false,
    count: 1,
    custom_name: null,
    item_name: null,
    damage: null,
    max_damage: null,
    unbreakable: false,
    enchantments: [],
    stored_enchantments: [],
    lore: [],
    contents: [],
    format: 'components',
    ...patch,
});

describe('formatting', () => {
    it('writes levels as roman numerals up to ten', () => {
        expect(roman(1)).toBe('I');
        expect(roman(5)).toBe('V');
        expect(roman(10)).toBe('X');
        expect(roman(11)).toBe('11');
        expect(roman(0)).toBe('0');
    });

    it('formats the time left on an effect', () => {
        expect(formatDuration(0)).toBe('0:00');
        expect(formatDuration(75)).toBe('1:15');
        expect(formatDuration(3700)).toBe('1:01:40');
        expect(formatDuration(-5)).toBe('0:00');
    });

    it('shortens names for small tiles', () => {
        expect(initials('Diamond Sword')).toBe('DS');
        expect(initials('Netherite Upgrade Smithing Template')).toBe('NUS');
        expect(initials('Stick')).toBe('St');
        expect(initials('create:brass_ingot')).toBe('BI');
        expect(initials('')).toBe('?');
    });

    it('prefers the custom name', () => {
        expect(itemTitle(item())).toBe('Diamond Sword');
        expect(itemTitle(item({ custom_name: 'Excalibur' }))).toBe('Excalibur');
    });

    it('describes durability', () => {
        expect(durability(item())).toBeNull();
        expect(durability(item({ damage: 0 }))).toBeNull();
        expect(durability(item({ damage: 120, max_damage: 1561 }))).toBe('1441 of 1561 uses left');
        expect(durability(item({ damage: 120 }))).toBe('120 damage taken');
    });

    it('formats numbers and percentages', () => {
        expect(formatNumber(18.5)).toBe('18.5');
        expect(formatNumber(20)).toBe('20');
        expect(formatNumber(100.123)).toBe('100.1');
        expect(formatNumber(null)).toBe('Unknown');
        expect(formatNumber(Number.NaN)).toBe('Unknown');
        expect(percent(0.5)).toBe('50%');
        expect(percent(1.7)).toBe('100%');
        expect(percent(null)).toBe('Unknown');
    });

    it('formats positions', () => {
        expect(formatCoord(100.5)).toBe('100.5');
        expect(formatCoord(64)).toBe('64');
        expect(formatCoord(-200.25)).toBe('-200.25');
        expect(formatCoord(1.005)).toBe('1');
        expect(formatCoord(0.1 + 0.2)).toBe('0.3');
        expect(formatCoord(null)).toBe('Unknown');
    });

    it('names slots', () => {
        expect(slotName('inventory', 0)).toBe('Hotbar slot 1');
        expect(slotName('inventory', 9)).toBe('Inventory slot 1');
        expect(slotName('inventory', 35)).toBe('Inventory slot 27');
        expect(slotName('inventory', 40)).toBe('Slot 40');
        expect(slotName('ender', 26)).toBe('Ender chest slot 27');
        expect(slotName('equipment', 'offhand')).toBe('Off hand');
        expect(slotName('equipment', 'head')).toBe('Head');
    });

    it('accepts only names and UUIDs for the address', () => {
        expect(isPlayerRef('Steve_1')).toBe(true);
        expect(isPlayerRef('.Bedrock')).toBe(true);
        expect(isPlayerRef('069a79f4-44e9-4726-a5be-fca90e38aaf5')).toBe(true);
        expect(isPlayerRef('069a79f444e94726a5befca90e38aaf5')).toBe(true);
        expect(isPlayerRef('../etc')).toBe(false);
        expect(isPlayerRef('')).toBe(false);
        expect(isPlayerRef('a b')).toBe(false);
    });
});

describe('coordinates', () => {
    it('parses plain numbers with a dot or a comma', () => {
        expect(parseCoordinate('10')).toBe(10);
        expect(parseCoordinate(' -3.5 ')).toBe(-3.5);
        expect(parseCoordinate('2,5')).toBe(2.5);
        expect(parseCoordinate('')).toBeNull();
        expect(parseCoordinate('abc')).toBeNull();
        expect(parseCoordinate('1e5')).toBeNull();
        expect(parseCoordinate('Infinity')).toBeNull();
        expect(parseCoordinate('1.2.3')).toBeNull();
    });

    it('checks a teleport before it is sent', () => {
        expect(teleportRequest('10', '64', '-20.5', 'minecraft:the_nether')).toEqual({
            action: 'teleport',
            x: 10,
            y: 64,
            z: -20.5,
            dimension: 'minecraft:the_nether',
        });
        expect(typeof teleportRequest('x', '64', '0', 'minecraft:overworld')).toBe('string');
        expect(typeof teleportRequest('0', '5000', '0', 'minecraft:overworld')).toBe('string');
        expect(typeof teleportRequest('0', '-2049', '0', 'minecraft:overworld')).toBe('string');
        expect(typeof teleportRequest('30000001', '64', '0', 'minecraft:overworld')).toBe('string');
        expect(typeof teleportRequest('0', '64', '-30000001', 'minecraft:overworld')).toBe('string');
        expect(teleportRequest('30000000', '4064', '-30000000', 'minecraft:overworld')).toMatchObject({ action: 'teleport' });
    });
});
