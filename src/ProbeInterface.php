<?php

declare(strict_types=1);

namespace EzPhp\Health;

/**
 * Contract for a single health probe.
 *
 * Each probe checks one external dependency (database, cache, queue, …)
 * and returns a {@see HealthResult} describing its status.
 */
interface ProbeInterface
{
    /**
     * Unique probe identifier used as the key in the health response.
     */
    public function name(): string;

    /**
     * Execute the probe and return its result.
     *
     * Implementations must never throw — all exceptions must be caught and
     * converted into an UNHEALTHY {@see HealthResult}.
     */
    public function check(): HealthResult;
}
