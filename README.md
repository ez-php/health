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

---

## Built-in probes

| Probe             | Trigger condition                                          | What it checks                    |
|-------------------|-------------------------------------------------------------|------------------------------------|
| `DatabaseProbe`   | `DatabaseInterface` bound in container                     | `SELECT 1` on the configured PDO   |
| `RedisProbe`      | `health.redis.host` config key present                     | `PING` on the Redis server         |
| `QueueProbe`      | `queue.driver` config is not `'redis'` (the default)         | `SELECT COUNT(*) FROM jobs`        |
| `RedisQueueProbe` | `queue.driver` config is `'redis'`                          | `LLEN queues:{name}` on Redis      |
| `OpcacheProbe`    | `health.opcache.enabled` config is `true` (opt-in)          | `opcache_get_status()` memory usage + hit rate |

Probes that cannot be set up (missing binding, missing extension) are silently skipped — the endpoint still works with whatever probes are available.

---

## Configuration

Add to `config/health.php` (only needed for the Redis and OPcache probes):

```php
<?php
return [
    'redis' => [
        'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('REDIS_PORT') ?: 6379),
    ],
    'opcache' => [
        'enabled' => filter_var(getenv('HEALTH_OPCACHE_ENABLED'), FILTER_VALIDATE_BOOLEAN),
    ],
];
```

The queue probe needs no `config/health.php` entry — it reads the queue module's own
`queue.driver`/`queue.redis.*` config keys to pick `QueueProbe` (database) or
`RedisQueueProbe` (Redis) automatically.

---

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
opt-in OPcache), tag it instead — `HealthServiceProvider::register()` collects every
`ProbeInterface` registered under the `'health.probe'` tag:

```php
use EzPhp\Contracts\TaggedContainerInterface;

// In your ServiceProvider::register(), before HealthServiceProvider has registered
// (provider order in provider/modules.php controls this):
$container = $this->app->make(TaggedContainerInterface::class);
$container->tag(StorageProbe::class, 'health.probe');
```

`StorageProbe` is resolved through the container like any other tagged service — give it a
constructor if it needs dependencies, the container will autowire it.

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
