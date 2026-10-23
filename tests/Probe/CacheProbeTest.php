<?php

declare(strict_types=1);

namespace Tests\Probe;

use Closure;
use EzPhp\Cache\ArrayDriver;
use EzPhp\Cache\CacheInterface;
use EzPhp\Cache\CacheStats;
use EzPhp\Cache\LockInterface;
use EzPhp\Cache\TaggedCache;
use EzPhp\Health\HealthResult;
use EzPhp\Health\HealthStatus;
use EzPhp\Health\Probe\CacheProbe;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use RuntimeException;
use Tests\TestCase;

/**
 * CacheProbe: healthy round-trip on an ArrayDriver, unhealthy on a store that
 * drops writes or throws.
 *
 * @package Tests\Probe
 */
#[CoversClass(CacheProbe::class)]
#[UsesClass(HealthResult::class)]
#[UsesClass(HealthStatus::class)]
final class CacheProbeTest extends TestCase
{
    public function test_round_trip_is_ok_and_leaves_no_key_behind(): void
    {
        $cache = new ArrayDriver();
        $result = (new CacheProbe($cache, 'memcached'))->check();

        $this->assertSame(HealthStatus::OK, $result->status);
        $this->assertSame('memcached', $result->name);
        $this->assertFalse($cache->has('__health_probe'));
    }

    public function test_a_store_that_loses_writes_is_unhealthy(): void
    {
        $result = (new CacheProbe($this->store(get: fn (): mixed => null)))->check();

        $this->assertSame(HealthStatus::UNHEALTHY, $result->status);
        $this->assertSame('written value could not be read back', $result->message);
    }

    public function test_an_exception_is_unhealthy(): void
    {
        $result = (new CacheProbe($this->store(get: fn (): mixed => throw new RuntimeException('connection refused'))))->check();

        $this->assertSame(HealthStatus::UNHEALTHY, $result->status);
        $this->assertSame('connection refused', $result->message);
        $this->assertSame('cache', (new CacheProbe(new ArrayDriver()))->name());
    }

    /**
     * A CacheInterface whose get() is the given closure; everything else is inert.
     *
     * @param Closure(): mixed $get
     */
    private function store(Closure $get): CacheInterface
    {
        return new class ($get) implements CacheInterface {
            /**
             * @param Closure(): mixed $get
             */
            public function __construct(private readonly Closure $get)
            {
            }

            public function lock(string $key, int $ttl = 0): LockInterface
            {
                throw new RuntimeException('unused');
            }

            public function tags(string|array $tags): TaggedCache
            {
                throw new RuntimeException('unused');
            }

            public function stats(): CacheStats
            {
                return new CacheStats(0, 0);
            }

            public function get(string $key, mixed $default = null): mixed
            {
                return ($this->get)();
            }

            public function set(string $key, mixed $value, int $ttl = 0): void
            {
            }

            public function forget(string $key): void
            {
            }

            public function has(string $key): bool
            {
                return false;
            }

            public function remember(string $key, int $ttl, Closure $callback): mixed
            {
                return $callback();
            }

            public function flush(): void
            {
            }

            public function increment(string $key, int $amount = 1): int
            {
                return 0;
            }

            public function decrement(string $key, int $amount = 1): int
            {
                return 0;
            }
        };
    }
}
