<?php

namespace McCrash\Support;

/** Tells apart stack frames that belong to Java, the game and the loaders from frames that may be a plugin or mod. */
final class Frames
{
    /** Java itself and libraries nearly every plugin bundles: never the cause on their own. */
    private const LIBRARY = [
        'java.', 'javax.', 'jdk.', 'sun.', 'com.sun.', 'org.apache.', 'com.google.', 'io.netty.', 'it.unimi.', 'org.slf4j.',
        'org.yaml.', 'org.objectweb.asm.', 'kotlin.', 'kotlinx.', 'scala.', 'org.jetbrains.', 'org.joml.', 'oshi.', 'org.lwjgl.',
        'com.mojang.', 'org.sqlite.', 'com.zaxxer.', 'org.mariadb.', 'com.mysql.', 'org.postgresql.', 'org.h2.', 'org.w3c.', 'org.xml.',
    ];

    /** The game, the server software and the loaders. */
    private const PLATFORM = [
        'net.minecraft.', 'org.bukkit.', 'org.spigotmc.', 'io.papermc.', 'ca.spottedleaf.', 'com.destroystokyo.', 'co.aikar.',
        'net.minecraftforge.', 'net.neoforged.', 'cpw.mods.', 'net.fabricmc.loader.', 'net.fabricmc.api.', 'net.fabricmc.mapping',
        'org.quiltmc.loader.', 'org.spongepowered.asm.', 'org.spongepowered.api.', 'org.spongepowered.common.', 'net.md_5.', 'com.velocitypowered.',
    ];

    /**
     * Fabric API and Quilt Standard Libraries are mods of their own and can be the cause.
     */
    private const MODS_UNDER_PLATFORM = ['net.fabricmc.fabric.', 'org.quiltmc.qsl.'];

    public static function isLibrary(string $class): bool
    {
        return self::startsWith($class, self::LIBRARY);
    }

    public static function isPlatform(string $class): bool
    {
        if (self::startsWith($class, self::MODS_UNDER_PLATFORM)) {
            return false;
        }

        return self::startsWith($class, self::PLATFORM);
    }

    /** Neither Java nor the game: worth looking up in the jar index. */
    public static function isInteresting(string $class): bool
    {
        return ! self::isLibrary($class) && ! self::isPlatform($class);
    }

    /** "com.foo.bar.Baz$1" gives "com.foo.bar". */
    public static function package(string $class): string
    {
        $pos = strrpos($class, '.');

        return $pos === false ? '' : substr($class, 0, $pos);
    }

    /**
     * @param  list<string>  $prefixes
     */
    private static function startsWith(string $class, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($class, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
