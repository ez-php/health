<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Health\HealthResult;
use EzPhp\Health\HealthStatus;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(HealthResult::class)]
final class HealthResultTest extends TestCase
{
    public function testOkFactoryCreatesCorrectResult(): void
    {
        $result = HealthResult::ok('database', 'connected', 2.5);

        self::assertSame('database', $result->name);
        self::assertSame(HealthStatus::OK, $result->status);
        self::assertSame('connected', $result->message);
        self::assertSame(2.5, $result->latencyMs);
    }

    public function testDegradedFactoryCreatesCorrectResult(): void
    {
        $result = HealthResult::degraded('redis', 'slow response', 150.0);

        self::assertSame('redis', $result->name);
        self::assertSame(HealthStatus::DEGRADED, $result->status);
        self::assertSame('slow response', $result->message);
        self::assertSame(150.0, $result->latencyMs);
    }

    public function testUnhealthyFactoryCreatesCorrectResult(): void
    {
        $result = HealthResult::unhealthy('queue', 'connection refused');

        self::assertSame('queue', $result->name);
        self::assertSame(HealthStatus::UNHEALTHY, $result->status);
        self::assertSame('connection refused', $result->message);
        self::assertSame(0.0, $result->latencyMs);
    }

    public function testUnhealthyFactoryAcceptsExplicitLatency(): void
    {
        $result = HealthResult::unhealthy('queue', 'timeout', 5000.0);

        self::assertSame(5000.0, $result->latencyMs);
    }

    public function testToArrayReturnsExpectedStructure(): void
    {
        $result = HealthResult::ok('database', 'connected', 2.567);

        self::assertSame([
            'status' => 'ok',
            'message' => 'connected',
            'latency_ms' => 2.57,
        ], $result->toArray());
    }

    public function testToArrayRoundsLatencyToTwoDecimals(): void
    {
        $result = HealthResult::degraded('redis', 'slow', 99.999);

        self::assertSame(100.0, $result->toArray()['latency_ms']);
    }
}
