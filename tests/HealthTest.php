<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Health\Health;
use EzPhp\Health\HealthRegistry;
use EzPhp\Health\HealthResult;
use EzPhp\Health\HealthStatus;
use EzPhp\Health\ProbeInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;

#[CoversClass(Health::class)]
final class HealthTest extends TestCase
{
    protected function tearDown(): void
    {
        Health::resetRegistry();
    }

    public function testCheckDelegatesToRegistry(): void
    {
        $probe = $this->makeProbe('db', HealthResult::ok('db', 'connected', 1.0));
        Health::setRegistry(new HealthRegistry([$probe]));

        $results = Health::check();

        self::assertArrayHasKey('db', $results);
        self::assertSame(HealthStatus::OK, $results['db']->status);
    }

    public function testStatusReturnsAggregateStatus(): void
    {
        $probe = $this->makeProbe('db', HealthResult::unhealthy('db', 'down'));
        Health::setRegistry(new HealthRegistry([$probe]));

        self::assertSame(HealthStatus::UNHEALTHY, Health::status());
    }

    public function testCheckThrowsWhenNotInitialised(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Health facade is not initialised');

        Health::check();
    }

    public function testStatusThrowsWhenNotInitialised(): void
    {
        $this->expectException(RuntimeException::class);

        Health::status();
    }

    private function makeProbe(string $name, HealthResult $result): ProbeInterface
    {
        return new class ($name, $result) implements ProbeInterface {
            public function __construct(
                private readonly string $probeName,
                private readonly HealthResult $probeResult,
            ) {
            }

            public function name(): string
            {
                return $this->probeName;
            }

            public function check(): HealthResult
            {
                return $this->probeResult;
            }
        };
    }
}
