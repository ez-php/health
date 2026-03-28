<?php

declare(strict_types=1);

namespace EzPhp\Health;

use EzPhp\Contracts\ConfigInterface;
use EzPhp\Contracts\ContainerInterface;
use EzPhp\Contracts\DatabaseInterface;
use EzPhp\Contracts\ServiceProvider;
use EzPhp\Health\Probe\DatabaseProbe;
use EzPhp\Health\Probe\QueueProbe;
use EzPhp\Health\Probe\RedisProbe;
use EzPhp\Routing\Router;
use Redis;

/**
 * Registers the health-check endpoint and all available probes.
 *
 * Probes registered automatically when their dependencies are bound:
 *   - DatabaseProbe — when DatabaseInterface is bound
 *   - RedisProbe    — when config key 'health.redis.host' resolves and ext-redis is loaded
 *   - QueueProbe    — when DatabaseInterface is bound (queries the jobs table)
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

            // Queue probe — requires DatabaseInterface (queries the jobs table)
            try {
                $pdo = $app->make(DatabaseInterface::class)->getPdo();
                $probes[] = new QueueProbe($pdo);
            } catch (\Throwable) {
                // DatabaseInterface not registered — queue probe unavailable.
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
