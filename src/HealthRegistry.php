<?php

declare(strict_types=1);

namespace EzPhp\Health;

/**
 * Collects health probes and runs them on demand.
 *
 * The registry is intentionally stateless between calls — every call to
 * {@see run()} re-executes all probes so results are always fresh.
 */
final class HealthRegistry
{
    /**
     * @param list<ProbeInterface> $probes Ordered list of probes to execute
     */
    public function __construct(private readonly array $probes)
    {
    }

    /**
     * Execute all registered probes and return their results keyed by probe name.
     *
     * @return array<string, HealthResult>
     */
    public function run(): array
    {
        $results = [];

        foreach ($this->probes as $probe) {
            $results[$probe->name()] = $probe->check();
        }

        return $results;
    }

    /**
     * Derive the aggregate status from a set of already-executed probe results.
     *
     * @param array<string, HealthResult> $results
     */
    public function aggregate(array $results): HealthStatus
    {
        return HealthStatus::fromResults($results);
    }
}
