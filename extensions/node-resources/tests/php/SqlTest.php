<?php

namespace NodeResources\Tests;

use NodeResources\Support\Sql;
use PHPUnit\Framework\TestCase;

final class SqlTest extends TestCase
{
    public function testBucketRoundsDownOnMysqlAndMariadb(): void
    {
        $this->assertSame('(FLOOR(taken_at / 300) * 300)', Sql::bucket('taken_at', 300, 'mysql'));
        $this->assertSame('(FLOOR(bucket / 3600) * 3600)', Sql::bucket('bucket', 3600, 'mariadb'));
    }

    public function testBucketUsesWholeNumberDivisionElsewhere(): void
    {
        $this->assertSame('((taken_at / 300) * 300)', Sql::bucket('taken_at', 300, 'sqlite'));
        $this->assertSame('((taken_at / 300) * 300)', Sql::bucket('taken_at', 300, 'pgsql'));
    }

    public function testWeightedAverageSkipsRowsWithoutAValue(): void
    {
        $sql = Sql::weighted('mem_avg');

        $this->assertStringContainsString('SUM(mem_avg * samples)', $sql);
        $this->assertStringContainsString('CASE WHEN mem_avg IS NOT NULL THEN samples END', $sql);
        $this->assertStringContainsString('NULLIF(', $sql);
    }

    private function pdo(): \PDO
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE s (node_id INT, taken_at INT, partial INT, state TEXT, mem INT, sv_mem INT, mem_total INT)');
        $pdo->exec('CREATE TABLE r (node_id INT, bucket INT, samples INT, running INT, mem_avg REAL, mem_max REAL, mem_total INT)');

        return $pdo;
    }

    public function testRawSamplesAreSummedIntoBucketsAndPartialMinutesAreLeftOutOfSums(): void
    {
        $pdo = $this->pdo();
        // Bucket 300..599: three minutes, the middle one partial. Bucket 600: one minute.
        $pdo->exec("INSERT INTO s VALUES (1, 300, 0, 'running', 100, 1000, 8), (1, 360, 1, 'offline', 200, 10, 8), (1, 420, 0, 'running', 300, 3000, 16), (1, 600, 0, 'running', 50, 500, 8)");

        $select = implode(', ', Sql::fromSamples(['mem', 'sv_mem'], ['mem_total'], ['sv_mem'], true));
        $bucket = Sql::bucket('taken_at', 300, 'sqlite');
        $rows = $pdo->query("SELECT {$bucket} AS bucket, {$select} FROM s GROUP BY node_id, {$bucket} ORDER BY {$bucket}")->fetchAll(\PDO::FETCH_ASSOC);

        $this->assertCount(2, $rows);
        $this->assertSame(300, (int) $rows[0]['bucket']);
        $this->assertSame(3, (int) $rows[0]['samples']);
        $this->assertSame(2, (int) $rows[0]['running']);
        $this->assertEquals(200, $rows[0]['mem_avg']);
        $this->assertEquals(300, $rows[0]['mem_max']);
        // The partial minute (sv_mem 10) is not in the server sum.
        $this->assertEquals(2000, $rows[0]['sv_mem_avg']);
        $this->assertEquals(3000, $rows[0]['sv_mem_max']);
        $this->assertSame(16, (int) $rows[0]['mem_total']);
        $this->assertSame(600, (int) $rows[1]['bucket']);
    }

    public function testBucketsMergeWithAWeightedAverageAndSkipEmptyOnes(): void
    {
        $pdo = $this->pdo();
        $pdo->exec('INSERT INTO r VALUES (1, 0, 5, 5, 10, 20, 8), (1, 300, 15, 10, 20, 90, 8), (1, 600, 5, 0, NULL, NULL, NULL), (1, 3600, 5, 5, 7, 7, 8)');

        $select = implode(', ', Sql::fromBuckets(['mem'], ['mem_total'], true));
        $bucket = Sql::bucket('bucket', 3600, 'sqlite');
        $rows = $pdo->query("SELECT {$bucket} AS bucket, {$select} FROM r GROUP BY node_id, {$bucket} ORDER BY {$bucket}")->fetchAll(\PDO::FETCH_ASSOC);

        $this->assertCount(2, $rows);
        $this->assertSame(25, (int) $rows[0]['samples']);
        $this->assertSame(15, (int) $rows[0]['running']);
        // (10 * 5 + 20 * 15) / 20: the bucket without a value does not count.
        $this->assertEquals(17.5, $rows[0]['mem_avg']);
        $this->assertEquals(90, $rows[0]['mem_max']);
        $this->assertEquals(7, $rows[1]['mem_avg']);
    }
}
