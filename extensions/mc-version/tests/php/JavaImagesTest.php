<?php

namespace McVersion\Tests;

use McVersion\Support\JavaImages;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JavaImagesTest extends TestCase
{
    /** The images the Paper egg offers. */
    private const array PAPER_EGG = [
        'Java 25' => 'ghcr.io/pterodactyl/yolks:java_25',
        'Java 21' => 'ghcr.io/pterodactyl/yolks:java_21',
        'Java 17' => 'ghcr.io/pterodactyl/yolks:java_17',
        'Java 16' => 'ghcr.io/pterodactyl/yolks:java_16',
        'Java 11' => 'ghcr.io/pterodactyl/yolks:java_11',
        'Java 8' => 'ghcr.io/pterodactyl/yolks:java_8',
    ];

    /** @return iterable<string, array{string, ?int}> */
    public static function images(): iterable
    {
        yield 'yolks' => ['ghcr.io/pterodactyl/yolks:java_21', 21];
        yield 'single digit' => ['ghcr.io/pterodactyl/yolks:java_8', 8];
        yield 'dash' => ['ghcr.io/example/runtime:java-17', 17];
        yield 'openjdk' => ['openjdk:17-jdk-slim', 17];
        yield 'temurin' => ['eclipse-temurin:21-jre', 21];
        yield 'suffix' => ['ghcr.io/parkervcp/yolks:java_21_graalvm', 21];
        yield 'no java' => ['ghcr.io/pterodactyl/yolks:nodejs_20', null];
        yield 'custom' => ['registry.example.com/minecraft:latest', null];
        yield 'digest' => ['ghcr.io/x/y:java_1234', null];
    }

    #[DataProvider('images')]
    public function test_major_of_an_image(string $image, ?int $expected): void
    {
        $this->assertSame($expected, JavaImages::major($image));
    }

    /** @return iterable<string, array{string, int}> */
    public static function recommendations(): iterable
    {
        yield '1.16.5 prefers 11' => ['1.16.5', 11];
        yield '1.12.2 prefers 11' => ['1.12.2', 11];
        yield '1.17.1' => ['1.17.1', 16];
        yield '1.18.2' => ['1.18.2', 17];
        yield '1.20.4' => ['1.20.4', 17];
        yield '1.20.6' => ['1.20.6', 21];
        yield '1.21.11' => ['1.21.11', 21];
        yield '26.1.2' => ['26.1.2', 25];
        yield '26.4 snapshot' => ['26.4-snapshot-4', 25];
    }

    #[DataProvider('recommendations')]
    public function test_recommends_the_mapped_image(string $version, int $java): void
    {
        $recommended = JavaImages::recommend(self::PAPER_EGG, $version);
        $this->assertNotNull($recommended);
        $this->assertSame($java, $recommended['java']);
        $this->assertSame('ghcr.io/pterodactyl/yolks:java_'.$java, $recommended['image']);
    }

    public function test_old_versions_fall_back_to_java_8_without_11(): void
    {
        $images = ['Java 17' => 'ghcr.io/pterodactyl/yolks:java_17', 'Java 8' => 'ghcr.io/pterodactyl/yolks:java_8'];
        $this->assertSame(8, JavaImages::recommend($images, '1.16.5')['java'] ?? null);
    }

    public function test_falls_back_to_the_oldest_image_that_is_new_enough(): void
    {
        $images = ['Java 21' => 'a:java_21', 'Java 25' => 'a:java_25', 'Java 17' => 'a:java_17'];
        $this->assertSame(17, JavaImages::recommend($images, '1.17.1')['java'] ?? null, '1.17 needs 16 or newer');
        $this->assertSame(21, JavaImages::recommend($images, '1.20.6')['java'] ?? null);
    }

    public function test_never_recommends_an_image_that_is_too_old(): void
    {
        $this->assertNull(JavaImages::recommend(['Java 8' => 'a:java_8', 'Java 11' => 'a:java_11'], '1.21.4'));
        $this->assertNull(JavaImages::recommend(['Java 21' => 'a:java_21'], '26.1'));
    }

    public function test_ignores_images_without_a_java_release(): void
    {
        $images = ['Custom' => 'registry.example.com/minecraft:latest', 'Java 21' => 'a:java_21'];
        $this->assertSame(21, JavaImages::recommend($images, '1.21')['java'] ?? null);
        $this->assertNull(JavaImages::recommend(['Custom' => 'registry.example.com/minecraft:latest'], '1.21'));
    }

    public function test_unknown_versions_get_no_recommendation(): void
    {
        $this->assertNull(JavaImages::recommend(self::PAPER_EGG, 'latest'));
    }

    public function test_offered_images_are_sorted_oldest_first(): void
    {
        $this->assertSame([8, 11, 16, 17, 21, 25], array_column(JavaImages::offered(self::PAPER_EGG), 'java'));
    }

    public function test_status_of_the_current_image(): void
    {
        $this->assertSame('ok', JavaImages::status(21, '1.21.4', 21));
        $this->assertSame('works', JavaImages::status(25, '1.21.4', 21));
        $this->assertSame('works', JavaImages::status(16, '1.16.5', 11), 'newer than needed still runs 1.16.5');
        $this->assertSame('too_old', JavaImages::status(16, '1.20.6', 21));
        $this->assertSame('too_old', JavaImages::status(8, '1.18.2', 17));
        $this->assertSame('unknown', JavaImages::status(null, '1.21.4', 21));
        $this->assertSame('unknown', JavaImages::status(21, 'latest', null));
    }
}
