<?php

declare(strict_types=1);

namespace EzPhp\Health\Probe;

use Closure;
use EzPhp\Health\HealthResult;
use EzPhp\Health\ProbeInterface;
use Throwable;

/**
 * Health probe reporting OPcache memory usage and hit rate.
 *
 * Reads `opcache_get_status()` by default. `ez-php/opcache` states this
 * belongs in a diagnostics tool or ez-php/health, not in the OPcache module
 * itself — this probe is that diagnostics surface; it does not use or depend
 * on `ez-php/opcache` (preloading is unrelated to runtime status reporting).
 */
final class OpcacheProbe implements ProbeInterface
{
    /**
     * @param string                                    $name           Probe identifier (default: 'opcache').
     * @param (Closure(): (array<string, mixed>|false))|null $statusProvider Overrides opcache_get_status() for testing.
     */
    public function __construct(
        private readonly string $name = 'opcache',
        private readonly ?Closure $statusProvider = null,
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
            $status = $this->readStatus();
            $latency = (microtime(true) - $start) * 1000;

            if ($status === false || ($status['opcache_enabled'] ?? false) !== true) {
                return HealthResult::degraded($this->name, 'OPcache is disabled or unavailable', $latency);
            }

            /** @var array{used_memory?: int|float, free_memory?: int|float} $memory */
            $memory = is_array($status['memory_usage'] ?? null) ? $status['memory_usage'] : [];
            $used = (float) ($memory['used_memory'] ?? 0);
            $free = (float) ($memory['free_memory'] ?? 0);
            $total = $used + $free;
            $usedPct = $total > 0.0 ? round($used / $total * 100, 1) : 0.0;

            /** @var array{opcache_hit_rate?: int|float} $stats */
            $stats = is_array($status['opcache_statistics'] ?? null) ? $status['opcache_statistics'] : [];
            $hitRate = round((float) ($stats['opcache_hit_rate'] ?? 0.0), 1);

            return HealthResult::ok(
                $this->name,
                sprintf('memory %.1f%% used, hit rate %.1f%%', $usedPct, $hitRate),
                $latency,
            );
        } catch (Throwable $e) {
            $latency = (microtime(true) - $start) * 1000;

            return HealthResult::unhealthy($this->name, $e->getMessage(), $latency);
        }
    }

    /**
     * @return array<string, mixed>|false
     */
    private function readStatus(): array|false
    {
        if ($this->statusProvider !== null) {
            return ($this->statusProvider)();
        }

        return function_exists('opcache_get_status') ? opcache_get_status(false) : false;
    }
}
