<?php

declare(strict_types=1);

namespace EzPhp\Health\Probe;

use Closure;
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
     * @param PDO|Closure(): PDO $pdo Database connection to probe, or a factory that connects on the first check()
     * @param string $name Probe identifier (default: 'database')
     */
    public function __construct(
        private PDO|Closure $pdo,
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
            $this->pdo()->query('SELECT 1');
            $latency = (microtime(true) - $start) * 1000;

            return HealthResult::ok($this->name, 'connected', $latency);
        } catch (Throwable $e) {
            $latency = (microtime(true) - $start) * 1000;

            return HealthResult::unhealthy($this->name, $e->getMessage(), $latency);
        }
    }

    /**
     * The connection, created by the factory on first use. A factory that throws
     * is kept, so the next check() tries again.
     *
     * @return PDO
     */
    private function pdo(): PDO
    {
        if ($this->pdo instanceof Closure) {
            /** @var PDO $connection */
            $connection = ($this->pdo)();
            $this->pdo = $connection;
        }

        return $this->pdo;
    }
}
