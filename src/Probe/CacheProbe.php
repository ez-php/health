<?php

declare(strict_types=1);

namespace EzPhp\Health\Probe;

use EzPhp\Cache\CacheInterface;
use EzPhp\Health\HealthResult;
use EzPhp\Health\ProbeInterface;
use Throwable;

/**
 * Class CacheProbe
 *
 * Health-checks any ez-php/cache store — File, Memcached, Database, APCu or
 * Redis behind CacheInterface — with a set / get / forget round-trip on a
 * probe-owned key. A value that does not come back (a full disk, an evicting or
 * unreachable Memcached) is unhealthy; an exception is unhealthy too.
 *
 * Needs ez-php/cache (a suggest); construct it with the bound CacheInterface and
 * tag it `health.probe`.
 *
 * @package EzPhp\Health\Probe
 */
final class CacheProbe implements ProbeInterface
{
    /**
     * @param CacheInterface $cache The store to check.
     * @param string         $name  Probe name in the /health response.
     * @param string         $key   Key written and removed again by every check.
     */
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly string $name = 'cache',
        private readonly string $key = '__health_probe',
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * {@inheritDoc}
     */
    public function check(): HealthResult
    {
        $start = microtime(true);
        $token = bin2hex(random_bytes(8));

        try {
            $this->cache->set($this->key, $token, 60);
            $read = $this->cache->get($this->key);
            $this->cache->forget($this->key);
            $latency = (microtime(true) - $start) * 1000;

            if ($read !== $token) {
                return HealthResult::unhealthy($this->name, 'written value could not be read back', $latency);
            }

            return HealthResult::ok($this->name, 'read/write ok', $latency);
        } catch (Throwable $e) {
            return HealthResult::unhealthy($this->name, $e->getMessage(), (microtime(true) - $start) * 1000);
        }
    }
}
