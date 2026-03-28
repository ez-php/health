<?php

declare(strict_types=1);

namespace EzPhp\Health;

/**
 * Immutable value object representing the outcome of a single health probe.
 */
final readonly class HealthResult
{
    /**
     * @param string       $name      Probe identifier (e.g. 'database', 'redis')
     * @param HealthStatus $status    Result status
     * @param string       $message   Human-readable description
     * @param float        $latencyMs Probe execution time in milliseconds
     */
    public function __construct(
        public string $name,
        public HealthStatus $status,
        public string $message,
        public float $latencyMs,
    ) {
    }

    /**
     * Create an OK result.
     */
    public static function ok(string $name, string $message, float $latencyMs): self
    {
        return new self($name, HealthStatus::OK, $message, $latencyMs);
    }

    /**
     * Create a DEGRADED result (non-critical impairment).
     */
    public static function degraded(string $name, string $message, float $latencyMs): self
    {
        return new self($name, HealthStatus::DEGRADED, $message, $latencyMs);
    }

    /**
     * Create an UNHEALTHY result (probe failed).
     */
    public static function unhealthy(string $name, string $message, float $latencyMs = 0.0): self
    {
        return new self($name, HealthStatus::UNHEALTHY, $message, $latencyMs);
    }

    /**
     * Serialise the result for inclusion in the JSON response body.
     *
     * @return array{status: string, message: string, latency_ms: float}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'message' => $this->message,
            'latency_ms' => round($this->latencyMs, 2),
        ];
    }
}
