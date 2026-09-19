<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Application\Application;
use EzPhp\Container\Container;
use EzPhp\Health\Health;
use EzPhp\Health\HealthRegistry;
use EzPhp\Health\HealthResult;
use EzPhp\Health\HealthServiceProvider;
use EzPhp\Health\Probe\OpcacheProbe;
use EzPhp\Health\ProbeInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\Support\FakeConfig;
use Tests\Support\FakeContainer;

/**
 * Fake probe used to verify Container::tagged('health.probe') registration.
 */
final class HealthServiceProviderFakeProbe implements ProbeInterface
{
    public function name(): string
    {
        return 'fake';
    }

    public function check(): HealthResult
    {
        return HealthResult::ok('fake', 'ok', 0.0);
    }
}

/**
 * Smoke test: HealthServiceProvider registers and boots its bindings in a
 * minimal container context without error.
 *
 * @uses \Tests\Support\FakeConfig
 * @uses \Tests\Support\FakeContainer
 */
#[CoversClass(HealthServiceProvider::class)]
#[UsesClass(HealthRegistry::class)]
#[UsesClass(HealthResult::class)]
#[UsesClass(Health::class)]
#[UsesClass(OpcacheProbe::class)]
final class HealthServiceProviderTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        Health::resetRegistry();

        foreach ($this->tempDirs as $dir) {
            @rmdir($dir . '/config');
            @rmdir($dir);
        }

        parent::tearDown();
    }

    private function bootedApplication(): Application
    {
        $basePath = sys_get_temp_dir() . '/ez-php-health-test-' . uniqid('', true);
        mkdir($basePath . '/config', 0o777, true);
        $this->tempDirs[] = $basePath;

        $app = new Application($basePath);
        $app->bootstrap();

        return $app;
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

    public function test_opcache_probe_is_not_registered_by_default(): void
    {
        $container = new FakeContainer(new FakeConfig([]));
        $provider = new HealthServiceProvider($container);

        $provider->register();

        $registry = $container->make(HealthRegistry::class);
        $this->assertSame([], $registry->run());
    }

    public function test_opcache_probe_is_registered_when_enabled_in_config(): void
    {
        $container = new FakeContainer(new FakeConfig(['health.opcache.enabled' => true]));
        $provider = new HealthServiceProvider($container);

        $provider->register();

        $registry = $container->make(HealthRegistry::class);
        $names = array_map(static fn ($result) => $result->name, $registry->run());
        $this->assertContains('opcache', $names);
    }

    public function test_custom_probes_registered_via_tag_are_included(): void
    {
        $app = $this->bootedApplication();
        $container = $app->make(Container::class);
        $container->tag(HealthServiceProviderFakeProbe::class, 'health.probe');

        $provider = new HealthServiceProvider($app);
        $provider->register();

        $registry = $app->make(HealthRegistry::class);
        $names = array_map(static fn ($result) => $result->name, $registry->run());

        $this->assertContains('fake', $names);
    }
}
