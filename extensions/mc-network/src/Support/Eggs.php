<?php

namespace McNetwork\Support;

/**
 * What an egg is, judged from its tags and features.
 */
final class Eggs
{
    public const array PROXY_TAGS = ['proxy', 'bungeecord', 'waterfall', 'velocity'];

    /**
     * @param  list<string>  $tags
     */
    public static function isProxy(array $tags): bool
    {
        return array_intersect(self::lower($tags), self::PROXY_TAGS) !== [];
    }

    /**
     * A Java server a proxy can send players to: tagged "minecraft", or with the
     * "eula" feature every Java egg has. Proxies and Bedrock are not.
     *
     * @param  list<string>  $tags
     * @param  list<string>  $features
     */
    public static function isJavaBackend(array $tags, array $features): bool
    {
        $tags = self::lower($tags);
        $java = in_array('minecraft', $tags, true) || in_array('eula', self::lower($features), true);

        return $java && ! self::isProxy($tags) && ! in_array('bedrock', $tags, true);
    }

    /**
     * @param  list<mixed>  $values
     * @return list<string>
     */
    private static function lower(array $values): array
    {
        return array_values(array_map(fn (mixed $value): string => strtolower((string) $value), $values));
    }
}
