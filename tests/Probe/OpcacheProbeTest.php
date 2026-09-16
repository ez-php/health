<?php

declare(strict_types=1);

namespace Tests\Probe;

use EzPhp\Health\HealthResult;
use EzPhp\Health\HealthStatus;
use EzPhp\Health\Probe\OpcacheProbe;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\TestCase;

#[CoversClass(OpcacheProbe::class)]
#[UsesClass(HealthResult::class)]
#[UsesClass(HealthStatus::class)]
final class OpcacheProbeTest extends TestCase
{
    public function testCheckReturnsOkWithMemoryAndHitRateWhenEnabled(): void
    {
        $probe = new OpcacheProbe(statusProvider: fn (): array => [
            'opcache_enabled' => true,
            'memory_usage' => ['used_memory' => 75, 'free_memory' => 25],
            'opcache_statistics' => ['opcache_hit_rate' => 98.5],
        ]);

        $result = $probe->check();

        self::assertSame(HealthStatus::OK, $result->status);
        self::assertSame('opcache', $result->name);
        self::assertStringContainsString('75.0%', $result->message);
        self::assertStringContainsString('98.5%', $result->message);
    }

    public function testCheckReturnsDegradedWhenDisabled(): void
    {
        $probe = new OpcacheProbe(statusProvider: fn (): array => ['opcache_enabled' => false]);

        $result = $probe->check();

        self::assertSame(HealthStatus::DEGRADED, $result->status);
        self::assertStringContainsString('disabled', $result->message);
    }

    public function testCheckReturnsDegradedWhenStatusUnavailable(): void
    {
        $probe = new OpcacheProbe(statusProvider: fn (): false => false);

        $result = $probe->check();

        self::assertSame(HealthStatus::DEGRADED, $result->status);
    }

    public function testCheckReturnsUnhealthyWhenStatusProviderThrows(): void
    {
        $probe = new OpcacheProbe(statusProvider: function (): array {
            throw new \RuntimeException('boom');
        });

        $result = $probe->check();

        self::assertSame(HealthStatus::UNHEALTHY, $result->status);
        self::assertStringContainsString('boom', $result->message);
    }

    public function testDefaultNameIsOpcache(): void
    {
        $probe = new OpcacheProbe(statusProvider: fn (): false => false);

        self::assertSame('opcache', $probe->name());
    }

    public function testCustomNameIsUsed(): void
    {
        $probe = new OpcacheProbe(name: 'php_opcache', statusProvider: fn (): array => ['opcache_enabled' => true]);

        self::assertSame('php_opcache', $probe->name());
        self::assertSame('php_opcache', $probe->check()->name);
    }
}
