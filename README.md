# ez-php/health

Lightweight health-check endpoint for the ez-php framework.

Registers a `GET /health` route that runs configurable probes (database, Redis, queue) and returns a JSON report. Independently registrable via `HealthServiceProvider` — no additional route configuration required.

---

## Installation

```bash
composer require ez-php/health
```

Add the provider to `provider/modules.php`:

```php
\EzPhp\Health\HealthServiceProvider::class,
```

The `/health` endpoint is now live.

---

## Response format

**HTTP 200** — all probes healthy:

```json
{
  "status": "ok",
  "probes": {
    "database": { "status": "ok", "message": "connected", "latency_ms": 1.23 },
    "redis":    { "status": "ok", "message": "connected", "latency_ms": 0.45 },
    "queue":    { "status": "ok", "message": "3 pending job(s)", "latency_ms": 0.89 }
  }
}
```

**HTTP 503** — one or more probes degraded or unhealthy:

```json
{
  "status": "degraded",
  "probes": {
    "database": { "status": "ok",       "message": "connected",       "latency_ms": 1.10 },
    "redis":    { "status": "degraded", "message": "slow response",    "latency_ms": 310.00 },
    "queue":    { "status": "ok",       "message": "0 pending job(s)", "latency_ms": 0.70 }
  }
}
```

### Status levels

| Status      | Meaning                                         | HTTP |
|-------------|-------------------------------------------------|------|
| `ok`        | All probes passed                               | 200  |
| `degraded`  | At least one probe is impaired but not critical | 503  |
| `unhealthy` | At least one probe failed completely            | 503  |

### Error messages

`/health` is unauthenticated unless you protect the route, and a failing probe's message is the
raw exception text — PDO errors name the database user and host, HTTP probe errors internal URLs.
Outside debug mode the messages of `degraded`/`unhealthy` probes are therefore replaced by
`"Degraded"` / `"Check failed"`; status and latency stay. Set `health.expose_messages`
(`HEALTH_EXPOSE_MESSAGES`) to `true` to always show them, or `false` to hide them even when
`app.debug` is on.

---

## Built-in probes

| Probe             | Trigger condition                                          | What it checks                    |
|-------------------|-------------------------------------------------------------|------------------------------------|
| `DatabaseProbe`   | `DatabaseInterface` bound in container                     | `SELECT 1` on the configured PDO   |
| `RedisProbe`      | `health.redis.host` config key present                     | `PING` on the Redis server         |
| `QueueProbe`      | `queue.driver` config is not `'redis'` (the default)         | `SELECT COUNT(*) FROM jobs`        |
| `RedisQueueProbe` | `queue.driver` config is `'redis'`                          | ready + due delayed jobs (same as `RedisDriver::size()`); not-yet-due delayed jobs reported as `, N delayed` |
| `OpcacheProbe`    | `health.opcache.enabled` config is `true` (opt-in)          | `opcache_get_status()` memory usage + hit rate |
| `DiskSpaceProbe`  | one per path in `health.disk.paths` (opt-in)                | free share of the path's filesystem: DEGRADED below `degraded_percent` (10), UNHEALTHY below `unhealthy_percent` (5) |

Probes that cannot be set up (missing binding, missing extension) are silently skipped — the endpoint still works with whatever probes are available.

---

## Configuration

Add to `config/health.php` (only needed for the Redis, OPcache and disk-space probes, or to override `expose_messages`):

```php
<?php
return [
    'redis' => [
        // Own variables, not REDIS_PORT: in a template app REDIS_PORT is the
        // host-published port, while the probe connects from inside the container.
        'host' => getenv('HEALTH_REDIS_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('HEALTH_REDIS_PORT') ?: 6379),
    ],
    'opcache' => [
        'enabled' => filter_var(getenv('HEALTH_OPCACHE_ENABLED'), FILTER_VALIDATE_BOOLEAN),
    ],
    // Local disk used by storage, file cache, file sessions or file logs.
    // One probe per path ('disk' for a single path, 'disk:<path>' for several).
    'disk' => [
        'paths' => [dirname(__DIR__) . '/storage'],
        'degraded_percent' => 10,
        'unhealthy_percent' => 5,
    ],
    // Raw messages of failing probes in the response; unset = follow app.debug.
    'expose_messages' => getenv('HEALTH_EXPOSE_MESSAGES') === false || getenv('HEALTH_EXPOSE_MESSAGES') === ''
        ? null
        : filter_var(getenv('HEALTH_EXPOSE_MESSAGES'), FILTER_VALIDATE_BOOLEAN),
];
```

The queue probe needs no `config/health.php` entry — it reads the queue module's own
`queue.driver`/`queue.redis.*` config keys to pick `QueueProbe` (database) or
`RedisQueueProbe` (Redis) automatically.

---

## Cache and HTTP probes

Two ready-made probes are not wired automatically, because which store or endpoint to check is application-specific. Construct them and tag them `health.probe` in a provider's `boot()`:

```php
use EzPhp\Cache\CacheInterface;
use EzPhp\Health\Probe\CacheProbe;
use EzPhp\Health\Probe\HttpProbe;
use EzPhp\HttpClient\HttpClient;

$this->app->bind(CacheProbe::class, fn ($app) => new CacheProbe($app->make(CacheInterface::class), 'memcached'));
$this->app->bind(HttpProbe::class, fn ($app) => new HttpProbe(
    $app->make(HttpClient::class),
    'https://api.payments.example/status',
    name: 'payments',
    timeoutSeconds: 3,
    degradedAfterMs: 800,   // OK but slower than 800 ms → degraded
));
$this->app->tag([CacheProbe::class, HttpProbe::class], 'health.probe');
```

| Probe | Checks | Result |
|---|---|---|
| `CacheProbe` | set → get → forget on any `ez-php/cache` store (File, Memcached, Database, APCu, Redis) | UNHEALTHY when the value does not come back or the store throws |
| `HttpProbe` | one GET via `ez-php/http-client` with a timeout | UNHEALTHY on a transport error or a status outside `minStatus`–`maxStatus` (default 200–299), DEGRADED above `degradedAfterMs` |

Both need their package installed (`ez-php/cache`, `ez-php/http-client` — suggested, not required).

## Custom probes

Implement `ProbeInterface` and register a custom `HealthRegistry` in a service provider:

```php
use EzPhp\Health\HealthRegistry;
use EzPhp\Health\HealthResult;
use EzPhp\Health\ProbeInterface;

final class StorageProbe implements ProbeInterface
{
    public function name(): string { return 'storage'; }

    public function check(): HealthResult
    {
        $start = microtime(true);
        $ok = is_writable('/var/www/html/storage');
        $latency = (microtime(true) - $start) * 1000;

        return $ok
            ? HealthResult::ok($this->name(), 'writable', $latency)
            : HealthResult::unhealthy($this->name(), 'not writable', $latency);
    }
}

// In your ServiceProvider::register():
$this->app->bind(HealthRegistry::class, fn() => new HealthRegistry([
    new DatabaseProbe($pdo),
    new StorageProbe(),
]));
```

Rebinding `HealthRegistry` replaces the whole registry, including the built-in probes. To *add*
a probe alongside whatever `HealthServiceProvider` already wires up (database, Redis, queue,
opt-in OPcache), tag it instead — `HealthServiceProvider` collects every `ProbeInterface` registered under
the `'health.probe'` tag:

```php
use EzPhp\Contracts\TaggedContainerInterface;

// In any ServiceProvider::register() — provider order does not matter:
$container = $this->app->make(TaggedContainerInterface::class);
$container->tag(StorageProbe::class, 'health.probe');
```

`StorageProbe` is resolved through the container like any other tagged service — give it a
constructor if it needs dependencies, the container will autowire it.

The tag is read lazily: `HealthServiceProvider` collects `tagged('health.probe')` when the
`HealthRegistry` is first resolved, which happens in its `boot()` — after every provider's
`register()` has run. So tag in `register()` (any provider), not in a `boot()` that may run
after `HealthServiceProvider`'s. Resolving `TaggedContainerInterface` inside `register()` is the
one sanctioned exception to "`register()` must not use other services": `Application::foundation()`
binds it before any provider is loaded, so it is always available there.

---

## Static facade

```php
use EzPhp\Health\Health;

$results = Health::check();   // array<string, HealthResult>
$status  = Health::status();  // HealthStatus::OK | DEGRADED | UNHEALTHY
```

---

## License

MIT
