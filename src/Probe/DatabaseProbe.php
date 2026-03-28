<?php

declare(strict_types=1);

namespace EzPhp\Health\Probe;

use EzPhp\Health\HealthResult;
use EzPhp\Health\ProbeInterface;
use PDO;
use Throwable;

/**
 * Health probe that verifies database connectivity by executing SELECT 1.
 */
final class DatabaseProbe implements ProbeInterface
{
    /**
     * @param PDO    $pdo  Database connection to probe
     * @param string $name Probe identifier (default: 'database')
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $name = 'database',
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
            $this->pdo->query('SELECT 1');
            $latency = (microtime(true) - $start) * 1000;

            return HealthResult::ok($this->name, 'connected', $latency);
        } catch (Throwable $e) {
            $latency = (microtime(true) - $start) * 1000;

            return HealthResult::unhealthy($this->name, $e->getMessage(), $latency);
        }
    }
}
