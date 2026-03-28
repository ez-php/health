<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Health\HealthRegistry;
use EzPhp\Health\HealthResult;
use EzPhp\Health\HealthStatus;
use EzPhp\Health\ProbeInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(HealthRegistry::class)]
#[UsesClass(HealthResult::class)]
#[UsesClass(HealthStatus::class)]
final class HealthRegistryTest extends TestCase
{
    public function testRunReturnsResultsKeyedByProbeName(): void
    {
        $probeA = $this->makeProbe('db', HealthResult::ok('db', 'connected', 1.0));
        $probeB = $this->makeProbe('redis', HealthResult::ok('redis', 'connected', 0.5));

        $registry = new HealthRegistry([$probeA, $probeB]);
        $results = $registry->run();

        self::assertArrayHasKey('db', $results);
        self::assertArrayHasKey('redis', $results);
        self::assertSame(HealthStatus::OK, $results['db']->status);
        self::assertSame(HealthStatus::OK, $results['redis']->status);
    }

    public function testRunReturnsEmptyArrayWhenNoProbes(): void
    {
        $registry = new HealthRegistry([]);

        self::assertSame([], $registry->run());
    }

    public function testAggregateReturnsOkWhenAllPassed(): void
    {
        $registry = new HealthRegistry([]);
        $results = [
            'db' => HealthResult::ok('db', 'connected', 1.0),
        ];

        self::assertSame(HealthStatus::OK, $registry->aggregate($results));
    }

    public function testAggregateReturnsDegradedWhenOneDegraded(): void
    {
        $registry = new HealthRegistry([]);
        $results = [
            'db' => HealthResult::ok('db', 'connected', 1.0),
            'redis' => HealthResult::degraded('redis', 'slow', 200.0),
        ];

        self::assertSame(HealthStatus::DEGRADED, $registry->aggregate($results));
    }

    public function testAggregateReturnsUnhealthyWhenOneFailed(): void
    {
        $registry = new HealthRegistry([]);
        $results = [
            'db' => HealthResult::unhealthy('db', 'connection refused'),
        ];

        self::assertSame(HealthStatus::UNHEALTHY, $registry->aggregate($results));
    }

    public function testRunCallsEachProbeExactlyOnce(): void
    {
        $callCount = 0;
        $probe = $this->makeProbe('test', HealthResult::ok('test', 'ok', 0.0), $callCount);

        $registry = new HealthRegistry([$probe]);
        $registry->run();

        self::assertSame(1, $callCount);
    }

    private function makeProbe(string $name, HealthResult $result, int &$callCount = 0): ProbeInterface
    {
        return new class ($name, $result, $callCount) implements ProbeInterface {
            public function __construct(
                private readonly string $probeName,
                private readonly HealthResult $probeResult,
                private int &$count,
            ) {
            }

            public function name(): string
            {
                return $this->probeName;
            }

            public function check(): HealthResult
            {
                $this->count++;
                return $this->probeResult;
            }
        };
    }
}
