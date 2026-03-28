<?php

declare(strict_types=1);

namespace EzPhp\Health\Probe;

use EzPhp\Health\HealthResult;
use EzPhp\Health\ProbeInterface;
use PDO;
use Throwable;

/**
 * Health probe that checks the database-backed queue by querying the jobs table.
 *
 * Returns OK with the pending job count when the table is accessible.
 * Returns DEGRADED when the jobs table does not exist (queue module not installed).
 * Returns UNHEALTHY when the database query fails unexpectedly.
 */
final class QueueProbe implements ProbeInterface
{
    /**
     * @param PDO    $pdo  Database connection used by the queue driver
     * @param string $name Probe identifier (default: 'queue')
     */
    public function __construct(
        private readonly PDO $pdo,
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
            $stmt = $this->pdo->query('SELECT COUNT(*) FROM jobs');
            $latency = (microtime(true) - $start) * 1000;

            if ($stmt === false) {
                return HealthResult::degraded($this->name, 'jobs table not accessible', $latency);
            }

            $count = (int) $stmt->fetchColumn();

            return HealthResult::ok($this->name, sprintf('%d pending job(s)', $count), $latency);
        } catch (Throwable $e) {
            $latency = (microtime(true) - $start) * 1000;

            // Table not found → queue module likely not installed; treat as degraded
            return HealthResult::degraded($this->name, $e->getMessage(), $latency);
        }
    }
}
