<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Health\HealthResult;
use EzPhp\Health\HealthStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(HealthStatus::class)]
final class HealthStatusTest extends TestCase
{
    public function testFromResultsReturnsOkWhenAllProbesPassed(): void
    {
        $results = [
            'db' => HealthResult::ok('db', 'connected', 1.0),
            'redis' => HealthResult::ok('redis', 'connected', 0.5),
        ];

        self::assertSame(HealthStatus::OK, HealthStatus::fromResults($results));
    }

    public function testFromResultsReturnsDegradedWhenAtLeastOneDegraded(): void
    {
        $results = [
            'db' => HealthResult::ok('db', 'connected', 1.0),
            'redis' => HealthResult::degraded('redis', 'slow', 200.0),
        ];

        self::assertSame(HealthStatus::DEGRADED, HealthStatus::fromResults($results));
    }

    public function testFromResultsReturnsUnhealthyWhenAtLeastOneUnhealthy(): void
    {
        $results = [
            'db' => HealthResult::unhealthy('db', 'connection refused'),
            'redis' => HealthResult::ok('redis', 'connected', 0.5),
        ];

        self::assertSame(HealthStatus::UNHEALTHY, HealthStatus::fromResults($results));
    }

    public function testFromResultsUnhealthyTakesPrecedenceOverDegraded(): void
    {
        $results = [
            'db' => HealthResult::degraded('db', 'slow', 300.0),
            'redis' => HealthResult::unhealthy('redis', 'connection refused'),
            'queue' => HealthResult::ok('queue', '0 pending job(s)', 1.0),
        ];

        self::assertSame(HealthStatus::UNHEALTHY, HealthStatus::fromResults($results));
    }

    public function testFromResultsReturnsOkForEmptyResults(): void
    {
        self::assertSame(HealthStatus::OK, HealthStatus::fromResults([]));
    }

    /**
     * @return array<string, array{HealthStatus, string}>
     */
    public static function statusValueProvider(): array
    {
        return [
            'ok' => [HealthStatus::OK, 'ok'],
            'degraded' => [HealthStatus::DEGRADED, 'degraded'],
            'unhealthy' => [HealthStatus::UNHEALTHY, 'unhealthy'],
        ];
    }

    #[DataProvider('statusValueProvider')]
    public function testEnumValues(HealthStatus $status, string $expected): void
    {
        self::assertSame($expected, $status->value);
    }
}
