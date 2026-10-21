<?php

use McPlayerData\Support\Backups;
use PHPUnit\Framework\TestCase;

final class BackupsTest extends TestCase
{
    private const string UUID = '069a79f4-44e9-4726-a5be-fca90e38aaf5';

    /** @return list<string> newest first, one a day back from $now */
    private function daily(DateTimeImmutable $now, int $count): array
    {
        $names = [];
        for ($day = 0; $day < $count; $day++) {
            $names[] = self::UUID.'.dat.mcpd-bak-'.$now->modify('-'.($day).' days')->format('Ymd-His');
        }

        return $names;
    }

    public function test_the_newest_ten_are_always_kept(): void
    {
        $now = new DateTimeImmutable('2026-10-21 12:00:00', new DateTimeZone('UTC'));
        $old = [];
        for ($i = 0; $i < 12; $i++) {
            $old[] = self::UUID.'.dat.mcpd-bak-'.$now->modify('-'.(100 + $i).' days')->format('Ymd-His');
        }

        $this->assertSame(array_slice($old, 10), Backups::expired($old, $now));
        $this->assertSame([], Backups::expired(array_slice($old, 0, 10), $now));
    }

    public function test_recent_backups_are_kept_beyond_ten(): void
    {
        $now = new DateTimeImmutable('2026-10-21 12:00:00', new DateTimeZone('UTC'));
        // Fourteen backups in two weeks: the first ten stay by count, 10..13 are 10-13 days old.
        $names = $this->daily($now, 14);
        $this->assertSame(array_slice($names, 10), Backups::expired($names, $now));

        // Twenty edits in the last hour: all stay.
        $burst = [];
        for ($i = 0; $i < 20; $i++) {
            $burst[] = self::UUID.'.dat.mcpd-bak-'.$now->modify('-'.$i.' minutes')->format('Ymd-His');
        }
        $this->assertSame([], Backups::expired($burst, $now));
    }

    public function test_a_name_without_a_readable_time_is_kept(): void
    {
        $now = new DateTimeImmutable('2026-10-21 12:00:00', new DateTimeZone('UTC'));
        $names = array_fill(0, 10, self::UUID.'.dat.mcpd-bak-20200101-000000');
        $names[] = self::UUID.'.dat.mcpd-bak-20201340-990000';

        $this->assertSame([], Backups::expired($names, $now));
    }

    public function test_backup_times_are_read_from_the_name(): void
    {
        $this->assertSame('2026-01-02 03:04:05', Backups::time(self::UUID.'.dat.mcpd-bak-20260102-030405')->format('Y-m-d H:i:s'));
        $this->assertSame('2026-01-02 03:04:05', Backups::time(self::UUID.'.dat.mcpd-bak-20260102-030405-3')->format('Y-m-d H:i:s'));
        $this->assertNull(Backups::time(self::UUID.'.dat'));
    }
}
