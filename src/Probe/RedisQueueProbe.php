<?php

declare(strict_types=1);

namespace EzPhp\Health\Probe;

use EzPhp\Health\HealthResult;
use EzPhp\Health\ProbeInterface;
use Redis;
use Throwable;

/**
 * Health probe for the Redis-backed queue driver from ez-php/queue.
 *
 * Reports the pending job count for a single queue via LLEN against the same
 * `queues:{name}` key convention used by EzPhp\Queue\Driver\RedisDriver.
 * Counterpart to QueueProbe, which only supports the database driver.
 */
final class RedisQueueProbe implements ProbeInterface
{
    /**
     * @param Redis  $redis     An already-connected Redis instance.
     * @param string $queueName Queue name to inspect (default: 'default').
     * @param string $name      Probe identifier (default: 'queue').
     */
    public function __construct(
        private readonly Redis $redis,
        private readonly string $queueName = 'default',
        private readonly string $name = 'queue',
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * {@inheritdoc}
     */
    public function check(): HealthResult
    {
        $start = microtime(true);

        try {
            $count = $this->redis->lLen('queues:' . $this->queueName);
            $latency = (microtime(true) - $start) * 1000;

            return HealthResult::ok($this->name, sprintf('%d pending job(s)', $count), $latency);
        } catch (Throwable $e) {
            $latency = (microtime(true) - $start) * 1000;

            return HealthResult::unhealthy($this->name, $e->getMessage(), $latency);
        }
    }
}
