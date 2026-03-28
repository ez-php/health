<?php

declare(strict_types=1);

namespace EzPhp\Health;

/**
 * Represents the aggregated status of all health probes.
 *
 * OK       — all probes passed
 * DEGRADED — at least one probe is degraded (non-critical but impaired)
 * UNHEALTHY — at least one probe failed
 */
enum HealthStatus: string
{
    case OK = 'ok';
    case DEGRADED = 'degraded';
    case UNHEALTHY = 'unhealthy';

    /**
     * Derive the worst-case status from a set of probe results.
     *
     * Precedence: UNHEALTHY > DEGRADED > OK
     *
     * @param array<string, HealthResult> $results
     */
    public static function fromResults(array $results): self
    {
        $worst = self::OK;

        foreach ($results as $result) {
            if ($result->status === self::UNHEALTHY) {
                return self::UNHEALTHY;
            }
            if ($result->status === self::DEGRADED) {
                $worst = self::DEGRADED;
            }
        }

        return $worst;
    }
}
