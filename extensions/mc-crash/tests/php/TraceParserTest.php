<?php

namespace McCrash\Tests;

use McCrash\Support\Frames;
use McCrash\Support\TraceParser;
use PHPUnit\Framework\TestCase;

final class TraceParserTest extends TestCase
{
    public function test_a_crash_report_trace_with_paper_jar_names(): void
    {
        $chains = TraceParser::parse(Jars::fixture('paper-crash-npe.txt'));

        $this->assertCount(1, $chains);
        $this->assertSame('java.lang.NullPointerException', $chains[0]['exception']);
        $this->assertStringStartsWith('Cannot invoke', $chains[0]['message']);
        $this->assertSame('com.chestshoplite.listener.SignListener', $chains[0]['frames'][0]['class']);
        $this->assertSame('onInteract', $chains[0]['frames'][0]['method']);
        $this->assertSame('SignListener.java', $chains[0]['frames'][0]['file']);
        $this->assertSame(88, $chains[0]['frames'][0]['line']);
        $this->assertSame('ChestShopLite-2.1.0.jar', $chains[0]['frames'][0]['jar']);
        $this->assertNull($chains[0]['frames'][1]['jar']);
        $this->assertSame('java.lang.Thread', $chains[0]['frames'][10]['class']);
    }

    public function test_caused_by_chains_and_the_log_prefix(): void
    {
        $chains = TraceParser::parse(Jars::fixture('latest.log'));

        $this->assertSame('org.bukkit.event.EventException', $chains[0]['exception']);
        $this->assertCount(1, $chains[0]['causes']);
        $root = TraceParser::root($chains[0]);
        $this->assertSame('java.lang.NullPointerException', $root['exception']);
        $this->assertSame('ChestShopLite-2.1.0.jar', $root['frames'][0]['jar']);

        $order = array_column(TraceParser::rootFirst($chains[0]), 'exception');
        $this->assertSame(['java.lang.NullPointerException', 'org.bukkit.event.EventException'], $order);
    }

    public function test_log4j_jar_tags_with_forge_decorations(): void
    {
        $chains = TraceParser::parse(Jars::fixture('ticking-entity-forge.txt'));
        $frames = $chains[0]['frames'];

        $this->assertSame('create-1.20.1-0.5.1.f.jar', $frames[0]['jar']);
        $this->assertSame('server-1.20.1-20230612.114412-srg.jar', $frames[1]['jar']);
        $this->assertSame('com.simibubi.create.content.contraptions.AbstractContraptionEntity', $frames[0]['class']);
    }

    public function test_suppressed_traces_do_not_leak_into_the_main_one(): void
    {
        $text = "java.lang.IllegalStateException: boom\n\tat com.a.A.run(A.java:1)\n\tSuppressed: java.io.IOException: closing\n\t\tat com.b.B.close(B.java:2)\nCaused by: java.lang.Error: root\n\tat com.c.C.go(C.java:3)\n";
        $chains = TraceParser::parse($text);

        $this->assertCount(1, $chains);
        $this->assertSame(['com.a.A'], array_column($chains[0]['frames'], 'class'));
        $this->assertSame(['com.c.C'], array_column($chains[0]['causes'][0]['frames'], 'class'));
    }

    public function test_hs_err_java_frames(): void
    {
        $chains = TraceParser::parse(Jars::fixture('hs_err_pid4242.log'));

        $this->assertSame('JVM crash', $chains[0]['exception']);
        $classes = array_column($chains[0]['frames'], 'class');
        $this->assertContains('com.example.regioncompress.io.ZstdRegionStorage', $classes);
    }

    public function test_thread_dump_without_at(): void
    {
        $chains = TraceParser::parse(Jars::fixture('watchdog.log'));

        $this->assertSame('Thread', $chains[0]['exception']);
        $this->assertSame('com.chestshoplite.util.PriceCache', $chains[0]['frames'][2]['class']);
    }

    public function test_prose_with_parentheses_is_not_a_frame_and_exception_words_alone_are_not_a_trace(): void
    {
        $this->assertSame([], TraceParser::parse("Loading world (this may take a while)\nsome.thing.Else(here)\nSteve joined the game\n"));
        $this->assertSame([], TraceParser::parse("java.net.BindException: Address already in use\n"));
    }

    public function test_a_huge_trace_is_capped(): void
    {
        $text = "java.lang.StackOverflowError\n".str_repeat("\tat com.a.A.loop(A.java:1)\n", 5000);
        $chains = TraceParser::parse($text);

        $this->assertLessThanOrEqual(150, count($chains[0]['frames']));
    }

    public function test_many_chains_are_capped(): void
    {
        $text = str_repeat("java.lang.Error: x\n\tat com.a.A.run(A.java:1)\n\n", 1000);

        $this->assertLessThanOrEqual(300, count(TraceParser::parse($text)));
    }

    public function test_frame_classification(): void
    {
        $this->assertTrue(Frames::isLibrary('java.util.HashMap'));
        $this->assertTrue(Frames::isLibrary('kotlin.collections.ArraysKt'));
        $this->assertTrue(Frames::isPlatform('net.minecraft.server.MinecraftServer'));
        $this->assertTrue(Frames::isPlatform('org.bukkit.plugin.RegisteredListener'));
        $this->assertTrue(Frames::isPlatform('net.minecraftforge.fml.loading.ModSorter'));
        $this->assertFalse(Frames::isPlatform('net.fabricmc.fabric.api.event.Event'), 'Fabric API is a mod');
        $this->assertTrue(Frames::isInteresting('net.fabricmc.fabric.impl.Thing'));
        $this->assertTrue(Frames::isInteresting('com.simibubi.create.Create'));
        $this->assertFalse(Frames::isInteresting('io.netty.channel.AbstractChannel'));
        $this->assertSame('com.foo', Frames::package('com.foo.Bar$1'));
        $this->assertSame('', Frames::package('Bar'));
    }
}
