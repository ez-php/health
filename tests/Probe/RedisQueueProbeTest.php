<?php

declare(strict_types=1);

namespace Tests\Probe;

use EzPhp\Health\HealthResult;
use EzPhp\Health\HealthStatus;
use EzPhp\Health\Probe\RedisQueueProbe;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Redis;
use Tests\TestCase;

#[CoversClass(RedisQueueProbe::class)]
#[UsesClass(HealthResult::class)]
#[UsesClass(HealthStatus::class)]
final class RedisQueueProbeTest extends TestCase
{
    public function testCheckReturnsOkWithPendingJobCount(): void
    {
        $redis = $this->createStub(Redis::class);
        $redis->method('lLen')->willReturn(3);

        $probe = new RedisQueueProbe($redis);
        $result = $probe->check();

        self::assertSame(HealthStatus::OK, $result->status);
        self::assertSame('queue', $result->name);
        self::assertSame('3 pending job(s)', $result->message);
    }

    public function testCheckUsesQueuesPrefixedKeyForDefaultQueue(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->expects(self::once())->method('lLen')->with('queues:default')->willReturn(0);

        $probe = new RedisQueueProbe($redis);
        $probe->check();
    }

    public function testCheckUsesConfiguredQueueName(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->expects(self::once())->method('lLen')->with('queues:emails')->willReturn(0);

        $probe = new RedisQueueProbe($redis, 'emails');
        $probe->check();
    }

    public function testCheckReturnsUnhealthyWhenLlenThrows(): void
    {
        $redis = $this->createStub(Redis::class);
        $redis->method('lLen')->willThrowException(new \RedisException('connection refused'));

        $probe = new RedisQueueProbe($redis);
        $result = $probe->check();

        self::assertSame(HealthStatus::UNHEALTHY, $result->status);
        self::assertStringContainsString('connection refused', $result->message);
    }

    public function testDefaultNameIsQueue(): void
    {
        $redis = $this->createStub(Redis::class);
        $probe = new RedisQueueProbe($redis);

        self::assertSame('queue', $probe->name());
    }

    public function testCustomNameIsUsed(): void
    {
        $redis = $this->createStub(Redis::class);
        $redis->method('lLen')->willReturn(0);
        $probe = new RedisQueueProbe($redis, 'default', 'redis_queue');

        self::assertSame('redis_queue', $probe->name());
        self::assertSame('redis_queue', $probe->check()->name);
    }

    public function testDueDelayedJobsCountAsPendingLikeRedisDriverSize(): void
    {
        $redis = $this->createStub(Redis::class);
        $redis->method('lLen')->willReturn(3);
        // first zCount: delayed jobs already due; second: delayed jobs still waiting
        $redis->method('zCount')->willReturnOnConsecutiveCalls(2, 0);

        $result = (new RedisQueueProbe($redis))->check();

        self::assertSame(HealthStatus::OK, $result->status);
        self::assertSame('5 pending job(s)', $result->message);
    }

    public function testDelayedJobsNotYetDueAreReportedSeparately(): void
    {
        $redis = $this->createStub(Redis::class);
        $redis->method('lLen')->willReturn(1);
        $redis->method('zCount')->willReturnOnConsecutiveCalls(0, 4);

        $result = (new RedisQueueProbe($redis))->check();

        self::assertSame('1 pending job(s), 4 delayed', $result->message);
    }

    public function testDelayedSetUsesTheQueueDriversKeyConvention(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->method('lLen')->willReturn(0);
        $redis->expects(self::exactly(2))->method('zCount')
            ->with('queues:delayed:emails', self::isString(), self::isString())
            ->willReturn(0);

        (new RedisQueueProbe($redis, 'emails'))->check();
    }
}
