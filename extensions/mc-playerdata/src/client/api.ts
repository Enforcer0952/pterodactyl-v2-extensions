import { http } from '@pterodactyl/sdk';

export type ServerState = 'running' | 'offline' | 'starting' | 'stopping' | string;

export interface Item {
    /** A slot number, or head, chest, legs, feet, offhand for equipment. */
    slot: number | string;
    id: string;
    /** Readable name; mod items keep their namespace. */
    name: string;
    modded: boolean;
    count: number;
    custom_name: string | null;
    item_name: string | null;
    damage: number | null;
    max_damage: number | null;
    unbreakable: boolean;
    enchantments: Enchantment[];
    stored_enchantments: Enchantment[];
    lore: string[];
    contents: { name: string; id: string; count: number }[];
    format: 'legacy' | 'components' | 'plain';
}

export interface Enchantment {
    id: string;
    name: string;
    level: number;
}

export type EquipmentSlot = 'head' | 'chest' | 'legs' | 'feet' | 'offhand';
export type Area = 'inventory' | 'ender' | 'equipment';

export interface Place {
    x: number;
    y: number;
    z: number;
    dimension: { id: string; name: string };
    forced?: boolean;
}

export interface Effect {
    id: string;
    name: string;
    level: number;
    ticks: number;
    infinite: boolean;
    seconds: number | null;
}

export interface PlayerSummary {
    data_version: number | null;
    inventory: Item[];
    equipment: Record<EquipmentSlot, Item | null>;
    ender: Item[];
    other: Item[];
    selected_slot: number | null;
    position: { x: number; y: number; z: number; yaw: number | null; pitch: number | null } | null;
    dimension: { id: string; name: string } | null;
    spawn: Place | null;
    xp: { level: number | null; progress: number | null; total: number | null };
    health: number | null;
    max_health: number | null;
    food: { level: number | null; saturation: number | null };
    game_mode: { id: number; name: string } | null;
    effects: Effect[];
}

export interface PlayerView {
    state: ServerState;
    player: { uuid: string; name: string | null };
    hash: string;
    /** When the player file was last written by the server. */
    saved: string | null;
    size: number;
    world: string;
    /** The folder the player files are in, e.g. world/players/data. */
    folder?: string;
    backups: string[];
    can_manage: boolean;
    can_edit: boolean;
    read_only: string | null;
    data: PlayerSummary;
}

export interface EditResult extends PlayerView {
    changed: boolean;
    backup: string | null;
}

export interface KnownPlayer {
    name: string | null;
    uuid: string;
    saved: string | null;
}

export type EditRequest =
    | { action: 'remove-item'; area: Area; slot: number | string; amount?: number }
    | { action: 'clear-inventory' | 'clear-ender' | 'clear-effects' | 'teleport-spawn' }
    | { action: 'game-mode'; mode: number }
    | { action: 'teleport'; x: number; y: number; z: number; dimension: string };

const base = (server: string) => `/api/client/servers/${server}/extensions/mc-playerdata`;

export const keys = {
    player: (server: string, player: string) => ['mc-playerdata', server, 'player', player.toLowerCase()] as const,
    players: (server: string) => ['mc-playerdata', server, 'players'] as const,
};

export async function getPlayer(server: string, player: string): Promise<PlayerView> {
    const { data } = await http.get<PlayerView>(`${base(server)}/player`, { params: { player } });
    return data;
}

export async function getPlayers(server: string): Promise<KnownPlayer[]> {
    const { data } = await http.get<{ players: KnownPlayer[] }>(`${base(server)}/players`);
    return data.players;
}

export async function editPlayer(server: string, player: string, hash: string, request: EditRequest): Promise<EditResult> {
    const { data } = await http.post<EditResult>(`${base(server)}/player/edit`, { player, hash, ...request });
    return data;
}
