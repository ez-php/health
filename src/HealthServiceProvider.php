<?php

declare(strict_types=1);

namespace EzPhp\Health;

use Closure;
use EzPhp\Contracts\ConfigInterface;
use EzPhp\Contracts\ContainerInterface;
use EzPhp\Contracts\DatabaseInterface;
use EzPhp\Contracts\RouterInterface;
use EzPhp\Contracts\ServiceProvider;
use EzPhp\Contracts\TaggedContainerInterface;
use EzPhp\Health\Probe\DatabaseProbe;
use EzPhp\Health\Probe\DiskSpaceProbe;
use EzPhp\Health\Probe\OpcacheProbe;
use EzPhp\Health\Probe\QueueProbe;
use EzPhp\Health\Probe\RedisProbe;
use EzPhp\Health\Probe\RedisQueueProbe;
use PDO;
use Redis;
use RedisException;
use RuntimeException;

/**
 * Registers the health-check endpoint and all available probes.
 *
 * Probes registered automatically from what is configured — never from whether a
 * connection succeeds at registration time (the connection probes connect lazily in
 * check() and report an outage as UNHEALTHY):
 *   - DatabaseProbe    — when DatabaseInterface is bound
 *   - RedisProbe       — when config key 'health.redis.host' is a non-empty string and ext-redis is loaded
 *   - QueueProbe       — when 'queue.driver' is not 'redis' and DatabaseInterface is bound (queries the jobs table)
 *   - RedisQueueProbe  — when 'queue.driver' is 'redis' and ext-redis is loaded ('queue.redis.host'/'queue.redis.port'
 *   - OpcacheProbe     — when config key 'health.opcache.enabled' is truthy (opt-in; a disabled/absent OPcache would
 *                        otherwise permanently report DEGRADED, which is noise on CLI-only or opcache.enable_cli=0 setups)
 *   - DiskSpaceProbe   — one per path in config key 'health.disk.paths' (thresholds: health.disk.degraded_percent /
 *                        health.disk.unhealthy_percent); none when the list is empty
 *   - Custom probes    — any ProbeInterface registered under TaggedContainerInterface::tag($class, 'health.probe'); resolved via
 *                        TaggedContainerInterface::tagged() when the container binds it (Application does)
 *
 * Route registered in boot():
 *   GET /health → HealthController (failing probes' messages shown only with health.expose_messages, default app.debug)
 *   GET <health.liveness_path> → LivenessController (no probes; only when the path is a non-empty string)
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
            $config = $app->has(ConfigInterface::class) ? $app->make(ConfigInterface::class) : null;

            // Probes are added from what is configured, never from whether a connection
            // succeeds now: each one connects lazily in check(), so an outage is reported
            // as UNHEALTHY instead of silently dropping the probe.
            $database = $app->has(DatabaseInterface::class)
                ? static fn (): PDO => $app->make(DatabaseInterface::class)->getPdo()
                : null;

            if ($database !== null) {
                $probes[] = new DatabaseProbe($database);
            }

            // Redis probe — requires ext-redis and a non-empty health.redis.host
            $redisHost = $config?->get('health.redis.host');
            $redisPort = self::port($config?->get('health.redis.port'));

            if (extension_loaded('redis') && is_string($redisHost) && $redisHost !== '') {
                $probes[] = new RedisProbe(self::redis($redisHost, $redisPort));
            }

            // Queue probe — driver-aware: 'queue.driver' selects database (jobs table) or Redis (LLEN)
            if ($config !== null && $config->get('queue.driver', 'database') === 'redis') {
                if (extension_loaded('redis')) {
                    $queueHost = $config->get('queue.redis.host', '127.0.0.1');
                    $probes[] = new RedisQueueProbe(self::redis(
                        is_string($queueHost) && $queueHost !== '' ? $queueHost : '127.0.0.1',
                        self::port($config->get('queue.redis.port')),
                    ));
                }
            } elseif ($database !== null) {
                $probes[] = new QueueProbe($database);
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

            // Disk space probes — one per configured path (health.disk.paths); none by default
            try {
                /** @var ConfigInterface $config */
                $config = $app->make(ConfigInterface::class);
                $paths = $config->get('health.disk.paths', []);
                $degraded = $config->get('health.disk.degraded_percent', 10);
                $unhealthy = $config->get('health.disk.unhealthy_percent', 5);
                $paths = is_array($paths) ? array_values(array_filter($paths, 'is_string')) : [];

                foreach ($paths as $path) {
                    $probes[] = new DiskSpaceProbe(
                        $path,
                        is_numeric($degraded) ? (float) $degraded : 10.0,
                        is_numeric($unhealthy) ? (float) $unhealthy : 5.0,
                        count($paths) === 1 ? 'disk' : 'disk:' . $path,
                    );
                }
            } catch (\Throwable) {
                // ConfigInterface not registered — disk probes unavailable.
            }

            // Custom probes — application-registered via TaggedContainerInterface::tag($class, 'health.probe')
            try {
                $container = $app->make(TaggedContainerInterface::class);
            } catch (\Throwable) {
                // Not bound (e.g. a minimal ContainerInterface stub in tests) — no tagged probes.
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

        // Failing probes' raw messages only when asked for (or in debug): /health is public by default.
        $this->app->bind(HealthController::class, function (ContainerInterface $app): HealthController {
            $expose = false;

            try {
                /** @var ConfigInterface $config */
                $config = $app->make(ConfigInterface::class);
                // null (the template's value when HEALTH_EXPOSE_MESSAGES is unset) also falls back to app.debug.
                $value = $config->get('health.expose_messages') ?? $config->get('app.debug', false);
                $expose = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            } catch (\Throwable) {
                // No config bound — keep messages hidden.
            }

            return new HealthController($app->make(HealthRegistry::class), $expose);
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
            $router = $this->app->make(RouterInterface::class);
            $router->get('/health', [HealthController::class, '__invoke']);

            $livenessPath = $this->app->has(ConfigInterface::class)
                ? $this->app->make(ConfigInterface::class)->get('health.liveness_path')
                : null;

            if (is_string($livenessPath) && $livenessPath !== '') {
                $router->get($livenessPath, [LivenessController::class, '__invoke']);
            }
        } catch (\Throwable) {
            // Router not bound (e.g. CLI context or isolated tests) — route skipped.
        }
    }

    /**
     * A factory connecting to Redis on first use; a refused connection throws,
     * which the probe reports as UNHEALTHY.
     *
     * @return Closure(): Redis
     */
    private static function redis(string $host, int $port): Closure
    {
        return static function () use ($host, $port): Redis {
            $redis = new Redis();
            $error = 'connection failed';

            try {
                $connected = @$redis->connect($host, $port, 2.0);
            } catch (RedisException $e) {
                $connected = false;
                $error = $e->getMessage();
            }

            if (!$connected) {
                throw new RuntimeException(sprintf('Could not connect to Redis at %s:%d: %s', $host, $port, $error));
            }

            return $redis;
        };
    }

    /**
     * A configured port, accepting numeric strings; 6379 otherwise.
     */
    private static function port(mixed $value): int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : 6379;
    }
}
