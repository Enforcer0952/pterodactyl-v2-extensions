import type { ReactNode } from 'react';
import { Button, TitledGreyBox, Tooltip } from '@pterodactyl/sdk';
import { Backpack, Package } from 'lucide-react';
import type { Area, EquipmentSlot, Item, PlayerSummary } from './api';
import { durability, initials, itemTitle, roman, slotName } from './format';

export interface Selection {
    area: Area;
    slot: number | string;
}

const same = (a: Selection | null, area: Area, slot: number | string) => a !== null && a.area === area && a.slot === slot;

function Enchanted({ item }: { item: Item }) {
    return item.enchantments.length > 0 || item.stored_enchantments.length > 0 ? (
        <span className={'mcpd:absolute mcpd:top-1 mcpd:right-1 mcpd:size-1.5 mcpd:rounded-full mcpd:bg-primary'}>
            <span className={'mcpd:sr-only'}>Enchanted</span>
        </span>
    ) : null;
}

function TooltipBody({ item }: { item: Item }) {
    const lines = [...item.enchantments, ...item.stored_enchantments];
    return (
        <div className={'mcpd:flex mcpd:flex-col mcpd:gap-0.5 mcpd:text-xs'}>
            <span className={'mcpd:font-semibold'}>{itemTitle(item)}</span>
            {item.custom_name && <span className={'mcpd:text-muted-foreground'}>{item.name}</span>}
            {lines.map((line) => (
                <span key={line.id}>
                    {line.name} {roman(line.level)}
                </span>
            ))}
            {item.count > 1 && <span className={'mcpd:text-muted-foreground'}>Count {item.count}</span>}
        </div>
    );
}

/** One slot as text: no game textures can be shipped, so a tile shows the item's name. */
export function ItemTile({
    item,
    label,
    selected,
    onSelect,
}: {
    item: Item | null;
    label: string;
    selected: boolean;
    onSelect: () => void;
}) {
    if (!item) {
        return (
            <div
                aria-label={`${label}: empty`}
                className={
                    'mcpd:aspect-square mcpd:min-w-0 mcpd:rounded mcpd:border mcpd:border-dashed mcpd:border-border mcpd:bg-muted/30'
                }
            />
        );
    }

    return (
        <Tooltip content={<TooltipBody item={item} />}>
            <button
                type={'button'}
                aria-label={`${label}: ${itemTitle(item)}${item.count > 1 ? `, ${item.count}` : ''}`}
                aria-pressed={selected}
                onClick={onSelect}
                className={`mcpd:relative mcpd:flex mcpd:aspect-square mcpd:min-w-0 mcpd:flex-col mcpd:items-center mcpd:justify-center mcpd:overflow-hidden mcpd:rounded mcpd:border mcpd:bg-muted mcpd:p-0.5 mcpd:text-center mcpd:leading-tight mcpd:hover:bg-muted/60 ${
                    selected ? 'mcpd:border-primary mcpd:ring-2 mcpd:ring-primary' : 'mcpd:border-border'
                }`}
            >
                <span
                    className={`mcpd:w-full mcpd:break-words mcpd:text-[9px] mcpd:sm:hidden ${item.modded ? 'mcpd:text-warning' : 'mcpd:text-foreground'}`}
                    aria-hidden
                >
                    {initials(item.name)}
                </span>
                <span
                    className={`mcpd:hidden mcpd:w-full mcpd:line-clamp-3 mcpd:break-words mcpd:text-[10px] mcpd:sm:block ${item.modded ? 'mcpd:text-warning' : 'mcpd:text-foreground'}`}
                    aria-hidden
                >
                    {itemTitle(item)}
                </span>
                <Enchanted item={item} />
                {item.count > 1 && (
                    <span
                        className={
                            'mcpd:absolute mcpd:right-0.5 mcpd:bottom-0.5 mcpd:rounded mcpd:bg-background/80 mcpd:px-1 mcpd:text-[10px] mcpd:font-semibold mcpd:text-foreground'
                        }
                    >
                        {item.count}
                    </span>
                )}
            </button>
        </Tooltip>
    );
}

function bySlot(items: Item[]): Map<number | string, Item> {
    return new Map(items.map((item) => [item.slot, item]));
}

const EQUIPMENT: { slot: EquipmentSlot; label: string }[] = [
    { slot: 'head', label: 'Head' },
    { slot: 'chest', label: 'Chest' },
    { slot: 'legs', label: 'Legs' },
    { slot: 'feet', label: 'Feet' },
    { slot: 'offhand', label: 'Off hand' },
];

function Caption({ children }: { children: ReactNode }) {
    return <p className={'mcpd:text-xs mcpd:font-medium mcpd:text-muted-foreground'}>{children}</p>;
}

/** Armor and the off hand, then the main inventory with the hotbar set apart below it. */
export function InventoryGrid({
    data,
    selection,
    onSelect,
}: {
    data: PlayerSummary;
    selection: Selection | null;
    onSelect: (selection: Selection) => void;
}) {
    const items = bySlot(data.inventory);
    const main = Array.from({ length: 27 }, (_, index) => index + 9);
    const hotbar = Array.from({ length: 9 }, (_, index) => index);

    const tile = (slot: number) => (
        <ItemTile
            key={slot}
            item={items.get(slot) ?? null}
            label={slotName('inventory', slot)}
            selected={same(selection, 'inventory', slot)}
            onSelect={() => onSelect({ area: 'inventory', slot })}
        />
    );

    return (
        <TitledGreyBox icon={Backpack} title={'Inventory'}>
            <div className={'mcpd:flex mcpd:flex-col mcpd:gap-4 mcpd:md:flex-row'}>
                <div className={'mcpd:flex mcpd:flex-col mcpd:gap-1'}>
                    <Caption>Armor and off hand</Caption>
                    <div className={'mcpd:grid mcpd:grid-cols-5 mcpd:gap-1 mcpd:md:w-16 mcpd:md:grid-cols-1'}>
                        {EQUIPMENT.map(({ slot, label }) => (
                            <div key={slot} className={slot === 'offhand' ? 'mcpd:md:mt-3' : undefined}>
                                <ItemTile
                                    item={data.equipment[slot]}
                                    label={slotName('equipment', slot)}
                                    selected={same(selection, 'equipment', slot)}
                                    onSelect={() => onSelect({ area: 'equipment', slot })}
                                />
                                <p className={'mcpd:mt-0.5 mcpd:text-center mcpd:text-[10px] mcpd:text-muted-foreground'}>{label}</p>
                            </div>
                        ))}
                    </div>
                </div>
                <div className={'mcpd:flex mcpd:min-w-0 mcpd:flex-1 mcpd:flex-col mcpd:gap-1'}>
                    <Caption>Inventory</Caption>
                    <div className={'mcpd:grid mcpd:grid-cols-9 mcpd:gap-1'}>{main.map(tile)}</div>
                    <div className={'mcpd:mt-3 mcpd:flex mcpd:flex-col mcpd:gap-1'}>
                        <Caption>
                            Hotbar
                            {data.selected_slot !== null && data.selected_slot >= 0 && data.selected_slot <= 8
                                ? ` (slot ${data.selected_slot + 1} selected in game)`
                                : ''}
                        </Caption>
                        <div className={'mcpd:grid mcpd:grid-cols-9 mcpd:gap-1'}>{hotbar.map(tile)}</div>
                    </div>
                </div>
            </div>
        </TitledGreyBox>
    );
}

export function EnderGrid({
    data,
    selection,
    onSelect,
}: {
    data: PlayerSummary;
    selection: Selection | null;
    onSelect: (selection: Selection) => void;
}) {
    const items = bySlot(data.ender);

    return (
        <TitledGreyBox icon={Package} title={'Ender chest'}>
            <div className={'mcpd:grid mcpd:grid-cols-9 mcpd:gap-1'}>
                {Array.from({ length: 27 }, (_, slot) => (
                    <ItemTile
                        key={slot}
                        item={items.get(slot) ?? null}
                        label={slotName('ender', slot)}
                        selected={same(selection, 'ender', slot)}
                        onSelect={() => onSelect({ area: 'ender', slot })}
                    />
                ))}
            </div>
        </TitledGreyBox>
    );
}

/** Items in slots outside the normal grid, often added by mods. */
export function OtherSlots({
    data,
    selection,
    onSelect,
}: {
    data: PlayerSummary;
    selection: Selection | null;
    onSelect: (selection: Selection) => void;
}) {
    if (data.other.length === 0) return null;

    return (
        <TitledGreyBox title={'Other slots'}>
            <p className={'mcpd:mb-2 mcpd:text-xs mcpd:text-muted-foreground'}>
                Items saved in slots outside the normal inventory, often added by mods.
            </p>
            <div className={'mcpd:grid mcpd:grid-cols-9 mcpd:gap-1'}>
                {data.other.map((item) => (
                    <ItemTile
                        key={String(item.slot)}
                        item={item}
                        label={slotName('inventory', item.slot)}
                        selected={same(selection, 'inventory', item.slot)}
                        onSelect={() => onSelect({ area: 'inventory', slot: item.slot })}
                    />
                ))}
            </div>
        </TitledGreyBox>
    );
}

export function findItem(data: PlayerSummary, selection: Selection | null): Item | null {
    if (!selection) return null;
    if (selection.area === 'equipment') return data.equipment[selection.slot as EquipmentSlot] ?? null;
    const list = selection.area === 'ender' ? data.ender : [...data.inventory, ...data.other];
    return list.find((item) => item.slot === selection.slot) ?? null;
}

/** Everything about the selected item, with the ways to take it out. */
export function ItemDetail({
    item,
    selection,
    canEdit,
    busy,
    onRemove,
}: {
    item: Item | null;
    selection: Selection | null;
    canEdit: boolean;
    busy: boolean;
    onRemove: (amount: number | null) => void;
}) {
    return (
        <TitledGreyBox title={'Item'}>
            {!item || !selection ? (
                <p className={'mcpd:text-sm mcpd:text-muted-foreground'}>Select an item to see its details.</p>
            ) : (
                <div className={'mcpd:flex mcpd:flex-col mcpd:gap-2 mcpd:text-sm'}>
                    <div>
                        <p className={'mcpd:font-semibold mcpd:text-foreground'}>{itemTitle(item)}</p>
                        {item.custom_name && <p className={'mcpd:text-xs mcpd:text-muted-foreground'}>{item.name}</p>}
                        <p className={'mcpd:font-mono mcpd:text-xs mcpd:text-muted-foreground'}>{item.id}</p>
                        <p className={'mcpd:text-xs mcpd:text-muted-foreground'}>
                            {slotName(selection.area, selection.slot)} · Count {item.count}
                        </p>
                    </div>
                    {item.item_name && item.item_name !== item.name && <p className={'mcpd:text-xs'}>Base name: {item.item_name}</p>}
                    {item.enchantments.length > 0 && (
                        <ul aria-label={'Enchantments'} className={'mcpd:flex mcpd:flex-col mcpd:gap-0.5'}>
                            {item.enchantments.map((line) => (
                                <li key={line.id} className={'mcpd:text-primary'}>
                                    {line.name} {roman(line.level)}
                                </li>
                            ))}
                        </ul>
                    )}
                    {item.stored_enchantments.length > 0 && (
                        <ul aria-label={'Stored enchantments'} className={'mcpd:flex mcpd:flex-col mcpd:gap-0.5'}>
                            {item.stored_enchantments.map((line) => (
                                <li key={line.id} className={'mcpd:text-primary'}>
                                    Stored: {line.name} {roman(line.level)}
                                </li>
                            ))}
                        </ul>
                    )}
                    {durability(item) && <p>{durability(item)}</p>}
                    {item.unbreakable && <p>Unbreakable</p>}
                    {item.lore.length > 0 && (
                        <ul aria-label={'Lore'} className={'mcpd:text-xs mcpd:text-muted-foreground'}>
                            {item.lore.map((line, index) => (
                                <li key={index}>{line}</li>
                            ))}
                        </ul>
                    )}
                    {item.contents.length > 0 && (
                        <div>
                            <p className={'mcpd:text-xs mcpd:font-medium mcpd:text-muted-foreground'}>Contains</p>
                            <ul className={'mcpd:text-xs'}>
                                {item.contents.map((line, index) => (
                                    <li key={index}>
                                        {line.count} × {line.name}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                    {canEdit && (
                        <div className={'mcpd:flex mcpd:flex-wrap mcpd:gap-1.5 mcpd:pt-1'}>
                            {item.count > 1 && (
                                <Button size={'xsmall'} isSecondary disabled={busy} onClick={() => onRemove(1)}>
                                    Remove 1
                                </Button>
                            )}
                            <Button.Danger size={'xsmall'} isSecondary disabled={busy} onClick={() => onRemove(null)}>
                                {item.count > 1 ? 'Remove stack' : 'Remove item'}
                            </Button.Danger>
                        </div>
                    )}
                </div>
            )}
        </TitledGreyBox>
    );
}
