<?php

declare(strict_types=1);

namespace EzPhp\Health\Probe;

use Closure;
use EzPhp\Health\HealthResult;
use EzPhp\Health\ProbeInterface;
use Redis;
use Throwable;

/**
 * Health probe that verifies Redis connectivity by issuing a PING command.
 *
 * Requires the PHP redis extension (ext-redis).
 */
final class RedisProbe implements ProbeInterface
{
    /**
     * @param Redis|Closure(): Redis $redis An already-connected Redis instance, or a factory that connects on the first check()
     * @param string $name  Probe identifier (default: 'redis')
     */
    public function __construct(
        private Redis|Closure $redis,
        private readonly string $name = 'redis',
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
            $response = $this->redis()->ping();
            $latency = (microtime(true) - $start) * 1000;

            // PhpRedis returns true (boolean) or '+PONG' depending on version
            $ok = $response === true
                || strtoupper((string) $response) === 'PONG'
                || $response === '+PONG';

            if ($ok) {
                return HealthResult::ok($this->name, 'connected', $latency);
            }

            return HealthResult::unhealthy($this->name, 'unexpected ping response', $latency);
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
