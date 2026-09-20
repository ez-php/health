<?php

declare(strict_types=1);

namespace EzPhp\Health;

use EzPhp\Container\Container;
use EzPhp\Contracts\ConfigInterface;
use EzPhp\Contracts\ContainerInterface;
use EzPhp\Contracts\DatabaseInterface;
use EzPhp\Contracts\ServiceProvider;
use EzPhp\Health\Probe\DatabaseProbe;
use EzPhp\Health\Probe\OpcacheProbe;
use EzPhp\Health\Probe\QueueProbe;
use EzPhp\Health\Probe\RedisProbe;
use EzPhp\Health\Probe\RedisQueueProbe;
use EzPhp\Routing\Router;
use Redis;

/**
 * Registers the health-check endpoint and all available probes.
 *
 * Probes registered automatically when their dependencies are bound:
 *   - DatabaseProbe    — when DatabaseInterface is bound
 *   - RedisProbe       — when config key 'health.redis.host' resolves and ext-redis is loaded
 *   - QueueProbe       — when 'queue.driver' is not 'redis' and DatabaseInterface is bound (queries the jobs table)
 *   - RedisQueueProbe  — when 'queue.driver' is 'redis' and a connection succeeds via 'queue.redis.host'/'queue.redis.port'
 *   - OpcacheProbe     — when config key 'health.opcache.enabled' is truthy (opt-in; a disabled/absent OPcache would
 *                        otherwise permanently report DEGRADED, which is noise on CLI-only or opcache.enable_cli=0 setups)
 *   - Custom probes    — any ProbeInterface registered under Container::tag($class, 'health.probe'); resolved via
 *                        Container::tagged() when the container binds Container::class (Application does)
 *
 * Route registered in boot():
 *   GET /health → HealthController
 */
final class HealthServiceProvider extends ServiceProvider
{
    /**
     * @return void
     */
    public function register(): void
    {
        $this->app->bind(HealthRegistry::class, function (ContainerInterface $app): HealthRegistry {
            $probes = [];

            // Database probe — requires DatabaseInterface
            try {
                $pdo = $app->make(DatabaseInterface::class)->getPdo();
                $probes[] = new DatabaseProbe($pdo);
            } catch (\Throwable) {
                // DatabaseInterface not registered — database probe unavailable.
            }

            // Redis probe — requires ext-redis and health.redis.host config
            try {
                /** @var ConfigInterface $config */
                $config = $app->make(ConfigInterface::class);
                $hostValue = $config->get('health.redis.host', '127.0.0.1');
                $portValue = $config->get('health.redis.port', 6379);
                $host = is_string($hostValue) ? $hostValue : '127.0.0.1';
                $port = is_int($portValue) ? $portValue : 6379;

                $redis = new Redis();
                $connected = @$redis->connect($host, $port, 2.0);

                if ($connected) {
                    $probes[] = new RedisProbe($redis);
                }
            } catch (\Throwable) {
                // Redis not available or not configured — probe skipped.
            }

            // Queue probe — driver-aware: 'queue.driver' selects database (jobs table) or Redis (LLEN)
            try {
                /** @var ConfigInterface $config */
                $config = $app->make(ConfigInterface::class);
                $queueDriver = $config->get('queue.driver', 'database');

                if ($queueDriver === 'redis') {
                    $qHostValue = $config->get('queue.redis.host', '127.0.0.1');
                    $qPortValue = $config->get('queue.redis.port', 6379);
                    $qHost = is_string($qHostValue) ? $qHostValue : '127.0.0.1';
                    $qPort = is_int($qPortValue) ? $qPortValue : 6379;

                    $queueRedis = new Redis();
                    $queueConnected = @$queueRedis->connect($qHost, $qPort, 2.0);

                    if ($queueConnected) {
                        $probes[] = new RedisQueueProbe($queueRedis);
                    }
                } else {
                    $pdo = $app->make(DatabaseInterface::class)->getPdo();
                    $probes[] = new QueueProbe($pdo);
                }
            } catch (\Throwable) {
                // DatabaseInterface not registered, or Redis unavailable — queue probe unavailable.
            }

            // Opcache probe — opt-in via config, since a disabled/CLI-only OPcache would otherwise
            // permanently report DEGRADED noise
            try {
                /** @var ConfigInterface $config */
                $config = $app->make(ConfigInterface::class);

                if ($config->get('health.opcache.enabled', false) === true) {
                    $probes[] = new OpcacheProbe();
                }
            } catch (\Throwable) {
                // ConfigInterface not registered — opcache probe unavailable.
            }

            // Custom probes — application-registered via Container::tag($class, 'health.probe')
            try {
                $container = $app->make(Container::class);
            } catch (\Throwable) {
                // Container::class not resolvable (e.g. a minimal ContainerInterface stub in tests) — no tagged probes.
                $container = null;
            }

            // Not inside the try: a failing tagged probe constructor must surface, not vanish from /health.
            foreach ($container?->tagged('health.probe') ?? [] as $probe) {
                if ($probe instanceof ProbeInterface) {
                    $probes[] = $probe;
                }
            }

            return new HealthRegistry($probes);
        });
    }

    /**
     * @return void
     */
    public function boot(): void
    {
        Health::setRegistry($this->app->make(HealthRegistry::class));

        // Register the /health route when the Router is available.
        try {
            $router = $this->app->make(Router::class);
            $router->get('/health', [HealthController::class, '__invoke']);
        } catch (\Throwable) {
            // Router not bound (e.g. CLI context or isolated tests) — route skipped.
        }
    }
}
