<?php

declare(strict_types=1);

namespace Tests\Probe;

use EzPhp\Health\HealthResult;
use EzPhp\Health\HealthStatus;
use EzPhp\Health\Probe\RedisProbe;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Redis;
use Tests\TestCase;

#[CoversClass(RedisProbe::class)]
#[UsesClass(HealthResult::class)]
#[UsesClass(HealthStatus::class)]
final class RedisProbeTest extends TestCase
{
    public function testCheckReturnsOkWhenPingReturnsTrue(): void
    {
        $redis = $this->createStub(Redis::class);
        $redis->method('ping')->willReturn(true);

        $probe = new RedisProbe($redis);
        $result = $probe->check();

        self::assertSame(HealthStatus::OK, $result->status);
        self::assertSame('redis', $result->name);
        self::assertSame('connected', $result->message);
    }

    public function testCheckReturnsOkWhenPingReturnsPong(): void
    {
        $redis = $this->createStub(Redis::class);
        $redis->method('ping')->willReturn('+PONG');

        $probe = new RedisProbe($redis);
        $result = $probe->check();

        self::assertSame(HealthStatus::OK, $result->status);
    }

    public function testCheckReturnsOkWhenPingReturnsPongUppercase(): void
    {
        $redis = $this->createStub(Redis::class);
        $redis->method('ping')->willReturn('PONG');

        $probe = new RedisProbe($redis);
        $result = $probe->check();

        self::assertSame(HealthStatus::OK, $result->status);
    }

    public function testCheckReturnsUnhealthyWhenPingThrows(): void
    {
        $redis = $this->createStub(Redis::class);
        $redis->method('ping')->willThrowException(new \RedisException('connection refused'));

        $probe = new RedisProbe($redis);
        $result = $probe->check();

        self::assertSame(HealthStatus::UNHEALTHY, $result->status);
        self::assertStringContainsString('connection refused', $result->message);
    }

    public function testCheckReturnsUnhealthyOnUnexpectedPingResponse(): void
    {
        $redis = $this->createStub(Redis::class);
        $redis->method('ping')->willReturn(false);

        $probe = new RedisProbe($redis);
        $result = $probe->check();

        self::assertSame(HealthStatus::UNHEALTHY, $result->status);
        self::assertSame('unexpected ping response', $result->message);
    }

    public function testDefaultNameIsRedis(): void
    {
        $redis = $this->createStub(Redis::class);
        $probe = new RedisProbe($redis);

        self::assertSame('redis', $probe->name());
    }

    public function testCustomNameIsUsed(): void
    {
        $redis = $this->createStub(Redis::class);
        $redis->method('ping')->willReturn(true);
        $probe = new RedisProbe($redis, 'cache_redis');

        self::assertSame('cache_redis', $probe->name());
        self::assertSame('cache_redis', $probe->check()->name);
    }
}
