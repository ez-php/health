<?php

declare(strict_types=1);

namespace Tests\Probe;

use EzPhp\Health\HealthResult;
use EzPhp\Health\HealthStatus;
use EzPhp\Health\Probe\DatabaseProbe;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\TestCase;

#[CoversClass(DatabaseProbe::class)]
#[UsesClass(HealthResult::class)]
#[UsesClass(HealthStatus::class)]
final class DatabaseProbeTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function testCheckReturnsOkForHealthyConnection(): void
    {
        $probe = new DatabaseProbe($this->pdo);
        $result = $probe->check();

        self::assertSame(HealthStatus::OK, $result->status);
        self::assertSame('database', $result->name);
        self::assertSame('connected', $result->message);
        self::assertGreaterThanOrEqual(0.0, $result->latencyMs);
    }

    public function testDefaultNameIsDatabase(): void
    {
        $probe = new DatabaseProbe($this->pdo);

        self::assertSame('database', $probe->name());
    }

    public function testCustomNameIsUsed(): void
    {
        $probe = new DatabaseProbe($this->pdo, 'primary_db');

        self::assertSame('primary_db', $probe->name());
        self::assertSame('primary_db', $probe->check()->name);
    }

    public function testCheckReturnsUnhealthyWhenQueryFails(): void
    {
        // Force the PDO into a state where queries will fail by closing the connection
        $badPdo = new class () extends PDO {
            public function __construct()
            {
                // Do not call parent — intentionally broken
            }

            public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
            {
                throw new \PDOException('connection lost');
            }
        };

        $probe = new DatabaseProbe($badPdo);
        $result = $probe->check();

        self::assertSame(HealthStatus::UNHEALTHY, $result->status);
        self::assertStringContainsString('connection lost', $result->message);
    }

    public function testCheckRecordsLatency(): void
    {
        $probe = new DatabaseProbe($this->pdo);
        $result = $probe->check();

        self::assertGreaterThanOrEqual(0.0, $result->latencyMs);
    }

    public function testFactoryIsCalledOnCheckAndRetriedAfterAFailure(): void
    {
        $calls = 0;
        $probe = new DatabaseProbe(function () use (&$calls): PDO {
            if (++$calls === 1) {
                throw new \PDOException('Connection refused');
            }

            return $this->pdo;
        });

        self::assertSame(0, $calls);

        $failed = $probe->check();
        self::assertSame(HealthStatus::UNHEALTHY, $failed->status);
        self::assertStringContainsString('Connection refused', $failed->message);

        $statuses = [$probe->check()->status, $probe->check()->status];

        self::assertSame([HealthStatus::OK, HealthStatus::OK], $statuses);
        self::assertSame(2, $calls);
    }
}
