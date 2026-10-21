<?php

namespace McPlayerData\Support;

use McPlayerData\Support\Nbt\Tag;

/**
 * Describes one item stack for display. Both layouts are understood:
 *  - before 1.20.5: Count (byte) and a "tag" compound (Enchantments, display.Name, Damage...)
 *  - 1.20.5 and later: count (int) and a "components" compound (minecraft:enchantments,
 *    minecraft:custom_name, minecraft:damage...)
 */
final class Items
{
    private const int MAX_CONTENTS = 27;

    /**
     * @return array<string, mixed>|null null for an empty or unreadable stack
     */
    public static function describe(Tag $item, bool $withContents = true): ?array
    {
        if (! $item->is(Tag::COMPOUND)) {
            return null;
        }

        $idTag = $item->get('id');
        $id = $idTag?->text();
        if ($id === null && $idTag?->asInt() !== null) {
            $id = 'unknown:item_'.$idTag->asInt();
        }
        if ($id === null || $id === '' || $id === 'minecraft:air' || $id === 'air') {
            return null;
        }
        if (! str_contains($id, ':')) {
            $id = 'minecraft:'.$id;
        }

        $count = $item->get('count')?->asInt() ?? $item->get('Count')?->asInt() ?? 1;
        if ($item->get('count') === null && $count < 0) {
            // A legacy byte count above 127 is stored as a negative number.
            $count += 256;
        }

        $components = $item->get('components');
        $legacy = $item->get('tag');

        $result = [
            'id' => $id,
            'name' => Names::readable($id),
            'modded' => ! str_starts_with($id, 'minecraft:'),
            'count' => max(0, $count),
            'custom_name' => null,
            'item_name' => null,
            'damage' => null,
            'max_damage' => null,
            'unbreakable' => false,
            'enchantments' => [],
            'stored_enchantments' => [],
            'lore' => [],
            'contents' => [],
            'format' => $components !== null ? 'components' : ($legacy !== null ? 'legacy' : 'plain'),
        ];

        if ($components?->is(Tag::COMPOUND)) {
            $result['custom_name'] = TextComponent::fromTag($components->get('minecraft:custom_name'));
            $result['item_name'] = TextComponent::fromTag($components->get('minecraft:item_name'));
            $result['damage'] = $components->get('minecraft:damage')?->asInt();
            $result['max_damage'] = $components->get('minecraft:max_damage')?->asInt();
            $result['unbreakable'] = $components->has('minecraft:unbreakable');
            $result['enchantments'] = self::componentEnchantments($components->get('minecraft:enchantments'));
            $result['stored_enchantments'] = self::componentEnchantments($components->get('minecraft:stored_enchantments'));
            $result['lore'] = self::lore($components->get('minecraft:lore'));
            if ($withContents) {
                $result['contents'] = self::componentContents($components);
            }
        }

        if ($legacy?->is(Tag::COMPOUND)) {
            $display = $legacy->get('display');
            $result['custom_name'] ??= TextComponent::fromTag($display?->get('Name'));
            $result['damage'] ??= $legacy->get('Damage')?->asInt();
            $result['unbreakable'] = $result['unbreakable'] || ($legacy->get('Unbreakable')?->asInt() ?? 0) !== 0;
            $result['enchantments'] = [...$result['enchantments'], ...self::legacyEnchantments($legacy->get('Enchantments'))];
            $result['stored_enchantments'] = [...$result['stored_enchantments'], ...self::legacyEnchantments($legacy->get('StoredEnchantments'))];
            $result['lore'] = [...$result['lore'], ...self::lore($display?->get('Lore'))];
            if ($withContents) {
                $result['contents'] = [...$result['contents'], ...self::legacyContents($legacy)];
            }
        }

        return $result;
    }

    /**
     * 1.21.5 and later: {sharpness:3}. 1.20.5 to 1.21.4: {levels:{sharpness:3}}.
     *
     * @return list<array{id: string, name: string, level: int}>
     */
    private static function componentEnchantments(?Tag $tag): array
    {
        if (! $tag?->is(Tag::COMPOUND)) {
            return [];
        }

        $map = $tag->get('levels')?->is(Tag::COMPOUND) ? $tag->get('levels') : $tag;
        $out = [];
        foreach ($map->value as $key => $level) {
            $level = $level->asInt();
            if ($level === null || ($map === $tag && in_array((string) $key, ['show_in_tooltip'], true))) {
                continue;
            }
            $out[] = self::enchantment((string) $key, $level);
        }

        return $out;
    }

    /** @return list<array{id: string, name: string, level: int}> */
    private static function legacyEnchantments(?Tag $tag): array
    {
        $out = [];
        foreach ($tag?->tags() ?? [] as $entry) {
            $id = $entry->get('id');
            $level = $entry->get('lvl')?->asInt();
            if ($id === null || $level === null) {
                continue;
            }
            $out[] = self::enchantment($id->text() ?? 'minecraft:enchantment_'.$id->asInt(), $level);
        }

        return $out;
    }

    /** @return array{id: string, name: string, level: int} */
    private static function enchantment(string $id, int $level): array
    {
        $id = str_contains($id, ':') ? $id : 'minecraft:'.$id;

        return ['id' => $id, 'name' => Names::readable($id), 'level' => $level];
    }

    /** @return list<string> */
    private static function lore(?Tag $tag): array
    {
        if (! $tag?->is(Tag::LIST)) {
            return [];
        }

        $lines = [];
        foreach (array_slice($tag->value, 0, 20) as $line) {
            $text = match (true) {
                is_string($line) => TextComponent::clean(TextComponent::fromString(Nbt\Mutf8::decode($line))),
                $line instanceof Tag => TextComponent::fromTag($line),
                default => null,
            };
            if ($text !== null) {
                $lines[] = $text;
            }
        }

        return $lines;
    }

    /** @return list<array{name: string, id: string, count: int}> */
    private static function componentContents(Tag $components): array
    {
        $out = [];
        foreach ($components->get('minecraft:container')?->tags() ?? [] as $slot) {
            $out[] = $slot->get('item');
        }
        foreach ($components->get('minecraft:bundle_contents')?->tags() ?? [] as $item) {
            $out[] = $item;
        }

        return self::summaries($out);
    }

    /** @return list<array{name: string, id: string, count: int}> */
    private static function legacyContents(Tag $legacy): array
    {
        return self::summaries($legacy->get('BlockEntityTag')?->get('Items')?->tags() ?? []);
    }

    /**
     * @param  list<?Tag>  $items
     * @return list<array{name: string, id: string, count: int}>
     */
    private static function summaries(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $described = $item === null ? null : self::describe($item, false);
            if ($described !== null) {
                $out[] = ['name' => $described['custom_name'] ?? $described['name'], 'id' => $described['id'], 'count' => $described['count']];
            }
            if (count($out) >= self::MAX_CONTENTS) {
                break;
            }
        }

        return $out;
    }
}
