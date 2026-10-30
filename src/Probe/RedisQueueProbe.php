<?php

declare(strict_types=1);

namespace EzPhp\Health\Probe;

use Closure;
use EzPhp\Health\HealthResult;
use EzPhp\Health\ProbeInterface;
use Redis;
use Throwable;

/**
 * Health probe for the Redis-backed queue driver from ez-php/queue.
 *
 * Reports the pending job count for a single queue using the key convention of
 * EzPhp\Queue\Driver\RedisDriver: ready jobs in the `queues:{name}` list plus
 * delayed jobs in the `queues:delayed:{name}` sorted set (scored by the time
 * they become available). "Pending" counts ready jobs and delayed jobs that are
 * already due — the same number as RedisDriver::size(); delayed jobs still
 * waiting are reported separately. Counterpart to QueueProbe, which only
 * supports the database driver.
 */
final class RedisQueueProbe implements ProbeInterface
{
    /**
     * @param Redis|Closure(): Redis $redis An already-connected Redis instance, or a factory that connects on the first check()
     * @param string $queueName Queue name to inspect (default: 'default').
     * @param string $name      Probe identifier (default: 'queue').
     */
    public function __construct(
        private Redis|Closure $redis,
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
            $now = (string) time();
            $delayedKey = 'queues:delayed:' . $this->queueName;

            $ready = (int) $this->redis()->lLen('queues:' . $this->queueName);
            $due = (int) $this->redis()->zCount($delayedKey, '-inf', $now);
            $waiting = (int) $this->redis()->zCount($delayedKey, '(' . $now, '+inf');
            $latency = (microtime(true) - $start) * 1000;

            $message = sprintf('%d pending job(s)', $ready + $due);

            if ($waiting > 0) {
                $message .= sprintf(', %d delayed', $waiting);
            }

            return HealthResult::ok($this->name, $message, $latency);
        } catch (Throwable $e) {
            $latency = (microtime(true) - $start) * 1000;

            return HealthResult::unhealthy($this->name, $e->getMessage(), $latency);
        }
    }

    /**
     * The connection, created by the factory on first use. A factory that throws
     * is kept, so the next check() tries again.
     *
     * @return Redis
     */
    private function redis(): Redis
    {
        if ($this->redis instanceof Closure) {
            /** @var Redis $connection */
            $connection = ($this->redis)();
            $this->redis = $connection;
        }

        return $this->redis;
    }
}
