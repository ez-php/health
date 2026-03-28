<?php

declare(strict_types=1);

namespace EzPhp\Health\Probe;

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
     * @param Redis  $redis An already-connected Redis instance
     * @param string $name  Probe identifier (default: 'redis')
     */
    public function __construct(
        private readonly Redis $redis,
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
            $response = $this->redis->ping();
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
}
