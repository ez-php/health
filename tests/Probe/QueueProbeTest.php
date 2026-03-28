<?php

declare(strict_types=1);

namespace Tests\Probe;

use EzPhp\Health\HealthResult;
use EzPhp\Health\HealthStatus;
use EzPhp\Health\Probe\QueueProbe;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\TestCase;

#[CoversClass(QueueProbe::class)]
#[UsesClass(HealthResult::class)]
#[UsesClass(HealthStatus::class)]
final class QueueProbeTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function testCheckReturnsOkWhenJobsTableExistsAndIsEmpty(): void
    {
        $this->createJobsTable();

        $probe = new QueueProbe($this->pdo);
        $result = $probe->check();

        self::assertSame(HealthStatus::OK, $result->status);
        self::assertSame('queue', $result->name);
        self::assertSame('0 pending job(s)', $result->message);
    }

    public function testCheckReturnsOkWithPendingJobCount(): void
    {
        $this->createJobsTable();
        $this->pdo->exec("INSERT INTO jobs (payload, queue, attempts, reserved_at, available_at, created_at) VALUES ('{}', 'default', 0, NULL, 0, 0)");
        $this->pdo->exec("INSERT INTO jobs (payload, queue, attempts, reserved_at, available_at, created_at) VALUES ('{}', 'default', 0, NULL, 0, 0)");

        $probe = new QueueProbe($this->pdo);
        $result = $probe->check();

        self::assertSame(HealthStatus::OK, $result->status);
        self::assertSame('2 pending job(s)', $result->message);
    }

    public function testCheckReturnsDegradedWhenJobsTableDoesNotExist(): void
    {
        // No jobs table created — queue module not installed
        $probe = new QueueProbe($this->pdo);
        $result = $probe->check();

        self::assertSame(HealthStatus::DEGRADED, $result->status);
    }

    public function testDefaultNameIsQueue(): void
    {
        $probe = new QueueProbe($this->pdo);

        self::assertSame('queue', $probe->name());
    }

    public function testCustomNameIsUsed(): void
    {
        $this->createJobsTable();
        $probe = new QueueProbe($this->pdo, 'background_jobs');

        self::assertSame('background_jobs', $probe->name());
        self::assertSame('background_jobs', $probe->check()->name);
    }

    public function testCheckRecordsLatency(): void
    {
        $this->createJobsTable();
        $probe = new QueueProbe($this->pdo);
        $result = $probe->check();

        self::assertGreaterThanOrEqual(0.0, $result->latencyMs);
    }

    private function createJobsTable(): void
    {
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                queue TEXT NOT NULL,
                payload TEXT NOT NULL,
                attempts INTEGER NOT NULL DEFAULT 0,
                reserved_at INTEGER,
                available_at INTEGER NOT NULL,
                created_at INTEGER NOT NULL
            )
        ');
    }
}
