<?php

namespace McVersion\Support;

/**
 * Docker images that carry a Java release, such as ghcr.io/pterodactyl/yolks:java_21,
 * and the pick of the right one for a Minecraft version.
 */
final class JavaImages
{
    /** The Java release an image tag names: java_21, java-17, temurin-21, openjdk:17. Null when none. */
    public static function major(string $image): ?int
    {
        if (preg_match('/(?:java|jdk|jre|temurin|openjdk|corretto|zulu)[_:\-]?(\d{1,2})(?!\d)/i', $image, $match) === 1) {
            return (int) $match[1];
        }

        return null;
    }

    /**
     * The offered images with a known Java release, oldest release first.
     *
     * @param  array<string, string>  $images  label => image, as the egg lists them
     * @return list<array{label: string, image: string, java: int}>
     */
    public static function offered(array $images): array
    {
        $list = [];
        foreach ($images as $label => $image) {
            $java = is_string($image) ? self::major($image) : null;
            if ($java !== null) {
                $list[] = ['label' => (string) $label, 'image' => $image, 'java' => $java];
            }
        }
        usort($list, fn (array $a, array $b): int => $a['java'] <=> $b['java']);

        return $list;
    }

    /**
     * The best image the egg offers for a Minecraft version: the preferred Java release
     * if offered, otherwise the oldest offered release that is still new enough.
     *
     * @param  array<string, string>  $images
     * @return array{label: string, image: string, java: int}|null
     */
    public static function recommend(array $images, string $minecraft): ?array
    {
        $requirement = Versions::javaRequirement($minecraft);
        if ($requirement === null) {
            return null;
        }

        $offered = self::offered($images);
        foreach ($requirement['preferred'] as $java) {
            foreach ($offered as $entry) {
                if ($entry['java'] === $java) {
                    return $entry;
                }
            }
        }

        foreach ($offered as $entry) {
            if ($entry['java'] >= $requirement['minimum']) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * How the server's current image fits a Minecraft version: "ok" when it is the
     * recommended release, "works" when it is newer than needed, "too_old" when the
     * server will not start on it, "unknown" when either side cannot be told.
     */
    public static function status(?int $current, string $minecraft, ?int $recommended): string
    {
        $requirement = Versions::javaRequirement($minecraft);
        if ($current === null || $requirement === null) {
            return 'unknown';
        }
        if ($current < $requirement['minimum']) {
            return 'too_old';
        }

        return $recommended !== null && $current === $recommended ? 'ok' : 'works';
    }
}
