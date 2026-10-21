import type { Item } from './api';

const ROMAN = ['', 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X'];

/** Enchantment and effect levels the way the game writes them: V, not 5. */
export function roman(level: number): string {
    return level >= 1 && level <= 10 ? ROMAN[level] : String(level);
}

/** 75 seconds is 1:15, 3700 seconds is 1:01:40. */
export function formatDuration(seconds: number): string {
    const total = Math.max(0, Math.floor(seconds));
    const h = Math.floor(total / 3600);
    const m = Math.floor((total % 3600) / 60);
    const s = total % 60;
    const pad = (n: number) => String(n).padStart(2, '0');
    return h > 0 ? `${h}:${pad(m)}:${pad(s)}` : `${m}:${pad(s)}`;
}

/** Two or three letters for a tile that is too small for a name. */
export function initials(name: string): string {
    const path = name.includes(':') ? name.slice(name.indexOf(':') + 1) : name;
    const words = path.split(/[\s_/]+/).filter(Boolean);
    if (words.length === 0) return '?';
    if (words.length === 1) return words[0].slice(0, 2);
    return words
        .slice(0, 3)
        .map((word) => word[0])
        .join('')
        .toUpperCase();
}

/** What an item is called: the player's own name for it, else the game's. */
export function itemTitle(item: Item): string {
    return item.custom_name ?? item.name;
}

export function durability(item: Item): string | null {
    if (item.damage === null || item.damage <= 0) return null;
    if (item.max_damage && item.max_damage > 0) {
        const left = Math.max(0, item.max_damage - item.damage);
        return `${left} of ${item.max_damage} uses left`;
    }
    return `${item.damage} damage taken`;
}

export function formatNumber(value: number | null | undefined, digits = 1): string {
    if (value === null || value === undefined || !Number.isFinite(value)) return 'Unknown';
    return Number.isInteger(value) ? String(value) : value.toFixed(digits);
}

/** A position with up to two decimals and no trailing zeros. */
export function formatCoord(value: number | null | undefined): string {
    if (value === null || value === undefined || !Number.isFinite(value)) return 'Unknown';
    return String(Number(value.toFixed(2)));
}

export function percent(progress: number | null): string {
    if (progress === null || !Number.isFinite(progress)) return 'Unknown';
    return `${Math.round(Math.min(1, Math.max(0, progress)) * 100)}%`;
}

/** A coordinate typed by a person: a plain number, with a comma or a dot. */
export function parseCoordinate(text: string): number | null {
    const clean = text.trim().replace(',', '.');
    if (!/^-?\d+(\.\d+)?$/.test(clean)) return null;
    const value = Number(clean);
    return Number.isFinite(value) ? value : null;
}

export function formatDate(value: string | null | undefined): string {
    if (!value) return 'Unknown';
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? 'Unknown' : date.toLocaleString();
}

export function slotName(area: string, slot: number | string): string {
    if (area === 'equipment') return slot === 'offhand' ? 'Off hand' : `${String(slot).charAt(0).toUpperCase()}${String(slot).slice(1)}`;
    if (area === 'ender') return `Ender chest slot ${Number(slot) + 1}`;
    const n = Number(slot);
    return n >= 0 && n <= 8 ? `Hotbar slot ${n + 1}` : n >= 9 && n <= 35 ? `Inventory slot ${n - 8}` : `Slot ${n}`;
}

/** A name or a UUID the screen accepts in its address. */
export function isPlayerRef(value: string): boolean {
    return /^\.?[A-Za-z0-9_]{1,16}$/.test(value) || /^[0-9a-f]{8}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{12}$/i.test(value);
}

/** Edits are offered only when the server allows them and the file is not being read again after a save. */
export function canEditNow(view: { can_edit: boolean }, refreshing: boolean): boolean {
    return view.can_edit && !refreshing;
}

/** A route parameter decoded safely: a broken escape gives an empty value. */
export function decodeParam(value: string | undefined): string {
    if (!value) return '';
    try {
        return decodeURIComponent(value);
    } catch {
        return '';
    }
}
