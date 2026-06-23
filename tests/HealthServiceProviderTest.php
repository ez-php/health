<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Health\Health;
use EzPhp\Health\HealthRegistry;
use EzPhp\Health\HealthServiceProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\Support\FakeConfig;
use Tests\Support\FakeContainer;

/**
 * Smoke test: HealthServiceProvider registers and boots its bindings in a
 * minimal container context without error.
 *
 * @uses \Tests\Support\FakeConfig
 * @uses \Tests\Support\FakeContainer
 */
#[CoversClass(HealthServiceProvider::class)]
#[UsesClass(HealthRegistry::class)]
#[UsesClass(Health::class)]
final class HealthServiceProviderTest extends TestCase
{
    protected function tearDown(): void
    {
        Health::resetRegistry();
        parent::tearDown();
    }

    public function test_register_binds_health_registry(): void
    {
        $container = new FakeContainer(new FakeConfig([]));
        $provider = new HealthServiceProvider($container);

        $provider->register();

        $this->assertTrue($container->wasBound(HealthRegistry::class));
        $this->assertInstanceOf(HealthRegistry::class, $container->make(HealthRegistry::class));
    }

    public function test_boot_initialises_health_facade(): void
    {
        $container = new FakeContainer(new FakeConfig([]));
        $provider = new HealthServiceProvider($container);

        $provider->register();
        $provider->boot();

        // The facade is usable after boot; with no DB/Redis bound it reports no probes.
        $this->assertSame([], Health::check());
    }
}
