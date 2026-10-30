<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Application\Application;
use EzPhp\Contracts\DatabaseInterface;
use EzPhp\Contracts\RouterInterface;
use EzPhp\Contracts\TaggedContainerInterface;
use EzPhp\Health\Health;
use EzPhp\Health\HealthController;
use EzPhp\Health\HealthRegistry;
use EzPhp\Health\HealthResult;
use EzPhp\Health\HealthServiceProvider;
use EzPhp\Health\HealthStatus;
use EzPhp\Health\Probe\DatabaseProbe;
use EzPhp\Health\Probe\DiskSpaceProbe;
use EzPhp\Health\Probe\OpcacheProbe;
use EzPhp\Health\Probe\QueueProbe;
use EzPhp\Health\Probe\RedisProbe;
use EzPhp\Health\Probe\RedisQueueProbe;
use EzPhp\Health\ProbeInterface;
use EzPhp\Http\Request;
use PDOException;
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
#[UsesClass(HealthController::class)]
#[UsesClass(HealthStatus::class)]
#[UsesClass(HealthResult::class)]
#[UsesClass(Health::class)]
#[UsesClass(OpcacheProbe::class)]
#[UsesClass(DiskSpaceProbe::class)]
#[UsesClass(DatabaseProbe::class)]
#[UsesClass(QueueProbe::class)]
#[UsesClass(RedisProbe::class)]
#[UsesClass(RedisQueueProbe::class)]
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

        // The facade is usable after boot; with no DB bound and no Redis host configured it reports no probes.
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

    public function test_disk_probes_are_registered_per_configured_path(): void
    {
        $container = new FakeContainer(new FakeConfig(['health.disk.paths' => [sys_get_temp_dir(), '/']]));
        (new HealthServiceProvider($container))->register();

        $names = array_keys($container->make(HealthRegistry::class)->run());

        $this->assertSame(['disk:' . sys_get_temp_dir(), 'disk:/'], $names);
    }

    public function test_a_single_disk_path_is_named_disk(): void
    {
        $container = new FakeContainer(new FakeConfig(['health.disk.paths' => [sys_get_temp_dir()]]));
        (new HealthServiceProvider($container))->register();

        $this->assertSame(['disk'], array_keys($container->make(HealthRegistry::class)->run()));
    }

    public function test_custom_probes_registered_via_tag_are_included(): void
    {
        $app = $this->bootedApplication();
        $container = $app->make(TaggedContainerInterface::class);
        $container->tag(HealthServiceProviderFakeProbe::class, 'health.probe');

        $provider = new HealthServiceProvider($app);
        $provider->register();

        $registry = $app->make(HealthRegistry::class);
        $names = array_map(static fn ($result) => $result->name, $registry->run());

        $this->assertContains('fake', $names);
    }

    public function test_boot_registers_health_route_on_the_bound_router_interface(): void
    {
        $app = $this->bootedApplication();

        $provider = new HealthServiceProvider($app);
        $provider->register();
        $provider->boot();

        $paths = array_map(
            static fn (array $route): string => $route['method'] . ' ' . $route['path'],
            $app->make(RouterInterface::class)->toCache(),
        );

        $this->assertContains('GET /health', $paths);
    }

    /**
     * @return array<string, mixed>
     */
    private static function failingReport(FakeContainer $container): array
    {
        $container->instance(HealthRegistry::class, new HealthRegistry([new class () implements ProbeInterface {
            public function name(): string
            {
                return 'db';
            }

            public function check(): HealthResult
            {
                return HealthResult::unhealthy('db', 'SQLSTATE[HY000] secret-host');
            }
        }]));

        $controller = $container->make(HealthController::class);
        /** @var array<string, mixed> $body */
        $body = json_decode($controller(new Request('GET', '/health'))->body(), true);

        return $body;
    }

    public function test_failing_probe_messages_follow_expose_messages_then_app_debug(): void
    {
        foreach ([
            [[], 'Check failed'],
            [['app.debug' => true], 'SQLSTATE[HY000] secret-host'],
            [['app.debug' => true, 'health.expose_messages' => false], 'Check failed'],
            [['health.expose_messages' => 'true'], 'SQLSTATE[HY000] secret-host'],
        ] as [$config, $expected]) {
            $container = new FakeContainer(new FakeConfig($config));
            (new HealthServiceProvider($container))->register();

            /** @var array{probes: array{db: array{status: string, message: string}}} $body */
            $body = self::failingReport($container);
            $this->assertSame('unhealthy', $body['probes']['db']['status']);
            $this->assertSame($expected, $body['probes']['db']['message']);
        }
    }

    /**
     * A bound but unreachable database used to vanish from /health (its connection
     * error was taken for "not configured"), so the aggregate read ok.
     */
    public function test_an_unreachable_database_is_reported_unhealthy_not_skipped(): void
    {
        $container = new FakeContainer(new FakeConfig([]));
        $container->bind(DatabaseInterface::class, static function (): DatabaseInterface {
            throw new PDOException('SQLSTATE[HY000] [2002] Connection refused');
        });
        (new HealthServiceProvider($container))->register();

        $registry = $container->make(HealthRegistry::class);
        $results = $registry->run();

        $this->assertSame(HealthStatus::UNHEALTHY, $results['database']->status);
        $this->assertSame(HealthStatus::DEGRADED, $results['queue']->status);
        $this->assertSame(HealthStatus::UNHEALTHY, $registry->aggregate($results));
    }

    public function test_resolving_the_registry_does_not_connect(): void
    {
        $connections = 0;
        $container = new FakeContainer(new FakeConfig([]));
        $container->bind(DatabaseInterface::class, static function () use (&$connections): DatabaseInterface {
            $connections++;

            throw new PDOException('unreachable');
        });
        $provider = new HealthServiceProvider($container);
        $provider->register();
        $provider->boot();

        $this->assertSame(0, $connections);
    }

    public function test_a_configured_but_unreachable_redis_is_reported_unhealthy(): void
    {
        if (!extension_loaded('redis')) {
            $this->markTestSkipped('ext-redis is not loaded.');
        }

        // Port 1 on loopback refuses the connection immediately.
        $container = new FakeContainer(new FakeConfig(['health.redis.host' => '127.0.0.1', 'health.redis.port' => '1']));
        (new HealthServiceProvider($container))->register();

        $results = $container->make(HealthRegistry::class)->run();

        $this->assertSame(HealthStatus::UNHEALTHY, $results['redis']->status);
        $this->assertStringContainsString('127.0.0.1:1', $results['redis']->message);
    }

    public function test_no_redis_probe_without_a_configured_host(): void
    {
        foreach ([[], ['health.redis.host' => '']] as $config) {
            $container = new FakeContainer(new FakeConfig($config));
            (new HealthServiceProvider($container))->register();

            $this->assertArrayNotHasKey('redis', $container->make(HealthRegistry::class)->run());
        }
    }

    public function test_redis_queue_driver_yields_an_unhealthy_queue_probe_when_unreachable(): void
    {
        if (!extension_loaded('redis')) {
            $this->markTestSkipped('ext-redis is not loaded.');
        }

        $container = new FakeContainer(new FakeConfig([
            'queue.driver' => 'redis',
            'queue.redis.host' => '127.0.0.1',
            'queue.redis.port' => 1,
        ]));
        (new HealthServiceProvider($container))->register();

        $results = $container->make(HealthRegistry::class)->run();

        $this->assertSame(HealthStatus::UNHEALTHY, $results['queue']->status);
    }
}
