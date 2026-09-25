# Coding Guidelines

Applies to the entire ez-php project — framework core, all modules, and the application template.

---

## Environment

- PHP **8.5**, Composer for dependency management
- All project based commands run **inside Docker** — never directly on the host

```
docker compose exec app <command>
```

Container name: `ez-php-app`, service name: `app`.

---

## Quality Suite

Run after every change:

```
docker compose exec app composer full
```

Executes in order:
1. `sync_guidelines.php --check` — fails if any `CLAUDE.md` has drifted from this file
2. `check_test_classes.php` — fails on a duplicate test class name (all packages share the `Tests\` namespace, so a collision is a fatal error in the aggregated run, not a test failure)
3. `phpstan analyse` — static analysis, level 9, config: `phpstan.neon`
4. `php-cs-fixer fix` — auto-fixes style (`@PSR12` + `@PHP83Migration` + strict rules)
   *(Note: `@PHP85Migration` does not exist yet in php-cs-fixer; `@PHP83Migration` is the highest available and is used intentionally even though the project targets PHP 8.5)*
5. `phpunit` — all tests with coverage

Individual commands when needed:
```
composer analyse             # PHPStan only
composer cs                  # CS Fixer only
composer test                # PHPUnit only
composer guidelines:check    # CLAUDE.md drift only
composer test-classes:check  # duplicate test class names only
```

**PHPStan:** never suppress with `@phpstan-ignore-line` — always fix the root cause.

---

## Coding Standards

- `declare(strict_types=1)` at the top of every PHP file
- Typed properties, parameters, and return values — avoid `mixed`
- PHPDoc on every class and public method
- One responsibility per class — keep classes small and focused
- Constructor injection — no service locator pattern
- No global state unless intentional and documented
- Concrete classes are `final` — extend behavior through composition, not inheritance. Exception-hierarchy base classes (e.g. `EzPhpException`, `HttpException`, `CacheException`) are one carve-out, since they exist specifically to be extended. A documented template-method-style base class (e.g. `Mailable`, meant to be configured via constructor-time subclassing) is the other — the owning module's `CLAUDE.md` must record it under Design Decisions.

**Naming:**

| Thing | Convention |
|---|---|
| Classes / Interfaces | `PascalCase` |
| Methods / variables | `camelCase` |
| Constants | `UPPER_CASE` |
| Files | Match class name exactly |

**Principles:** SOLID · KISS · DRY · YAGNI

---

## Workflow & Behavior

- Write tests **before or alongside** production code (test-first)
- Read and understand the relevant code before making any changes
- Modify the minimal number of files necessary
- Keep implementations small — if it feels big, it likely belongs in a separate module
- No hidden magic — everything must be explicit and traceable
- No large abstractions without clear necessity
- No heavy dependencies — check if PHP stdlib suffices first
- Respect module boundaries — don't reach across packages
- Keep the framework core small — what belongs in a module stays there
- Document architectural reasoning for non-obvious design decisions
- Do not change public APIs unless necessary
- Prefer composition over inheritance — no premature abstractions

---

## New Modules & CLAUDE.md Files

### 1 — Required files

Every module under `modules/<name>/` must have:

| File | Purpose |
|---|---|
| `composer.json` | package definition, deps, autoload |
| `phpstan.neon` | static analysis config, level 9 |
| `phpunit.xml` | test suite config |
| `.php-cs-fixer.php` | code style config |
| `.gitignore` | ignore `vendor/`, `.env`, cache |
| `.env.example` | environment variable defaults (copy to `.env` on first run) |
| `docker-compose.yml` | Docker Compose service definition (always `container_name: ez-php-<name>-app`) |
| `docker/app/Dockerfile` | module Docker image (`FROM au9500/php:8.5`) |
| `docker/app/container-start.sh` | container entrypoint: `composer install` → `sleep infinity` |
| `docker/app/php.ini` | PHP ini overrides (`memory_limit`, `display_errors`, `xdebug.mode`) |
| `.github/workflows/ci.yml` | standalone CI pipeline |
| `README.md` | public documentation |
| `tests/TestCase.php` | base test case for the module |
| `start.sh` | convenience script: copy `.env`, bring up Docker, wait for services, exec shell |
| `CLAUDE.md` | see section 2 below |

### 2 — CLAUDE.md structure

Every module `CLAUDE.md` must follow this exact structure:

1. **Full content of `CODING_GUIDELINES.md`, verbatim** — copy it as-is, do not summarize or shorten
2. A `---` separator
3. `# Package: ez-php/<name>` (or `# Directory: <name>` for non-package directories)
4. Module-specific section covering:
   - Source structure — file tree with one-line description per file
   - Key classes and their responsibilities
   - Design decisions and constraints
   - Testing approach and infrastructure requirements (MySQL, Redis, etc.)
   - What does **not** belong in this module

**Do not edit part 1 by hand.** It is generated from `CODING_GUIDELINES.md` by
`sync_guidelines.php` at the project root:

```
php sync_guidelines.php            # rewrite every out-of-sync CLAUDE.md
php sync_guidelines.php --check    # report drift, exit 1 if any (CI / pre-commit)
```

Edit `CODING_GUIDELINES.md`, then run the script — it replaces everything before the
`# Package:` / `# Directory:` / `# Project:` heading and preserves the hand-written
section below it byte-for-byte. Editing a single copy only creates drift; before this
script existed, all 40 copies had diverged.

### 3 — Scaffolding a new module

`make_module.php` at the project root writes the required-file set and the monorepo
wiring in one step, wrapping `docker-init` for the Docker subset:

```
composer module:make <name> -- --description="..."
php make_module.php <name> --description="..." --services=mysql,redis
```

`<name>` is the kebab-case package name; the namespace is derived as
`EzPhp\<PascalCase>` (each `-`-separated word upper-cased) unless `--namespace=`
overrides it. Existing exceptions the guess gets wrong: `bignum` → `BigNum`,
`dataloader` → `DataLoader`, `dotenv` → `Env`, `graphql` → `GraphQL`, `oauth` → `OAuth`,
`opcache` → `OPCache`, `swagger-ui` → `SwaggerUI`, `webauthn` → `WebAuthn` and
`websocket` → `WebSocket`; `websocket-client` → `WebsocketClient`, `websocket-tls` → `WebsocketTls`,
`webauthn-metadata` → `WebauthnMetadata` and `metrics-statsd` → `MetricsStatsd` are
intentional lower-case-word namespaces, and `testing-application` shares `EzPhp\Testing\`
with `testing`).

To bring in a module whose code already lives in its own repository instead of
generating a fresh skeleton, pass `--repo=` with a git URL:

```
php make_module.php <name> --repo=<git-url> [--namespace=Foo]
```

This runs `git submodule add <url> modules/<name>` instead of writing package
files, then applies the same monorepo wiring below. It is mutually exclusive
with `--services` and `--description` — a submodule brings its own Docker
scaffold (if any) and its own `composer.json` description. A minimal `CLAUDE.md`
stub is written only if the submodule doesn't already ship one, so
`composer guidelines:sync` has a `# Package:` heading to anchor part 1 against.

It writes `modules/<name>/` and registers the module in the four places the monorepo
needs it — root `composer.json` (`autoload.psr-4` **and** the shared
`autoload-dev` `Tests\` directory list), `phpstan.neon`, `phpunit.xml` (test suite
**and** coverage source), and `packages.sh` (alphabetical position) — in both
generated and `--repo` mode.

Two things stay manual on purpose:

- **`CLAUDE.md` part 1** — only the `# Package:` section is generated. Run
  `composer guidelines:sync` afterwards; baking a guidelines copy into the generator
  would recreate the drift the sync script exists to prevent.
- **The host-port table below** (`--services` only) — claim the "next free" row by
  editing the table in `CODING_GUIDELINES.md` (never in a `CLAUDE.md` copy) and run
  `composer guidelines:sync` in the same change. Editing it drifts every `CLAUDE.md`
  until the sync runs, which is why the generator only reminds you instead of doing
  it. Skipping the edit leaves "next free" stale, so the next module collides.

### 4 — Docker scaffold

Run from the new module root (requires `"ez-php/docker": "^2.0"` in `require-dev`):

```
vendor/bin/docker-init
```

This copies `Dockerfile`, `docker-compose.yml`, `.env.example`, `start.sh`, and `docker/` into the module, replacing `{{MODULE_NAME}}` placeholders. Existing files are never overwritten.

Pass `--services` to merge MySQL/Redis/Meilisearch service definitions directly into `docker-compose.yml` and uncomment the matching sections in `.env.example`, instead of adapting them by hand afterward:

```
vendor/bin/docker-init --services=mysql
vendor/bin/docker-init --services=redis
vendor/bin/docker-init --services=meilisearch
vendor/bin/docker-init --services=mysql,redis
```

Pass `--extensions` to merge PHP extension install blocks (apt packages plus `docker-php-ext-install`/`pecl` lines) directly into `docker/app/Dockerfile`, instead of hand-editing it afterward — supported extensions: `bcmath`, `gmp`, `gd`, `imagick`:

```
vendor/bin/docker-init --extensions=gmp,bcmath
vendor/bin/docker-init --extensions=gd,imagick
```

When run from a module directory inside this monorepo, any requested extension not already present is also merged into the shared root `docker/app/Dockerfile` — the container `composer full` at the root actually runs against, distinct from the module's own standalone image.

After scaffolding:

1. Adapt `docker-compose.yml` — add or remove services (MySQL, Redis, Meilisearch) as needed
2. Adapt `.env.example` — fill in connection defaults matching the services above
3. Assign a unique host port for each exposed service (see table below)

**Allocated host ports:**

| Package | `DB_HOST_PORT` (MySQL) | Redis host port | `MEILISEARCH_PORT` |
|---|---|---|---|
| root (`ez-php-project`) | 3306 | 6379 (`REDIS_PORT`) | 7700 |
| `ez-php/framework` | 3307 | — | — |
| `ez-php/` (application template) | 3308 | 6383 (`REDIS_PORT`) | — |
| `ez-php/orm` | 3309 | — | — |
| `ez-php/cache` | — | 6380 (`REDIS_HOST_PORT`) | — |
| `ez-php/queue` | 3310 | 6381 (`REDIS_HOST_PORT`) | — |
| `ez-php/rate-limiter` | — | 6382 (`REDIS_HOST_PORT`) | — |
| `ez-php/search` | — | — | 7701 |
| `ez-php/event-store` | 3311 | — | — |
| **next free** | **3312** | **6384** | **7702** |

Only set a port for services the module actually uses. Modules without external services need no port config.

> The `MEILISEARCH_PORT` column is the **host** port. Inside a Compose network the service is always reachable at `http://meilisearch:7700` regardless of the host mapping — only publish-side ports need to be unique.

> The "Redis host port" column is likewise the **host**-published port. `ez-php/cache`, `ez-php/queue`, and `ez-php/rate-limiter` map it through a separate `REDIS_HOST_PORT` env var in `docker-compose.yml`, keeping `REDIS_PORT` fixed at `6379` for in-container connections (the app container always reaches Redis at `redis:6379` over the Compose network, regardless of the host mapping) — the root project and the `ez-php/` application template are the two exceptions, since both have no host/container split and use `REDIS_PORT` for both (the template's other in-container Redis settings — `CACHE_REDIS_PORT`, `QUEUE_REDIS_PORT`, `RATE_LIMITER_REDIS_PORT` — stay fixed at `6379` regardless, same as every other module).

> This table tracks only MySQL, Redis, and Meilisearch ports — the three services shared across multiple modules where a collision is otherwise easy to introduce. Mailpit is the one other service with published host ports: SMTP `1025` and web UI `8025`. `ez-php/mail` maps them through `MAILPIT_SMTP_HOST_PORT`/`MAILPIT_API_HOST_PORT` in `modules/mail/docker-compose.yml` (mirroring the `*_HOST_PORT` pattern above, documented in `modules/mail/.env.example`); the root project and the `ez-php/` template each run their own Mailpit on the same defaults (`MAIL_PORT`/`MAIL_WEB_PORT`), so **these three stacks cannot run at the same time** without overriding those variables. It isn't a table column because no module beyond those three runs Mailpit — but a new module adding its own single-use service's ports should likewise parameterize them and document the defaults in its own `.env.example` rather than adding a column here.

### 5 — Monorepo scripts

`packages.sh` at the project root is the **central package registry**. Both `push_all.sh` and `update_all.sh` source it — the package list lives in exactly one place.

When adding a new module, add `"$ROOT/modules/<name>"` to the `PACKAGES` array in `packages.sh` in **alphabetical order** among the other `modules/*` entries (before `framework`, `ez-php`, and the root entry at the end).

---

# Package: ez-php/health

## Source structure

```
src/
├── HealthStatus.php              — enum: OK, DEGRADED, UNHEALTHY; fromResults() aggregation
├── HealthResult.php              — readonly value object: name, status, message, latencyMs
├── ProbeInterface.php            — contract: name(): string, check(): HealthResult
├── HealthRegistry.php            — collects probes; run() and aggregate()
├── Health.php                    — static facade backed by HealthRegistry singleton
├── HealthController.php          — handles GET /health; returns JSON Response
├── HealthServiceProvider.php     — registers probes, HealthRegistry, route and facade
└── Probe/
    ├── DatabaseProbe.php         — SELECT 1 via PDO
    ├── RedisProbe.php            — PING via ext-redis
    ├── QueueProbe.php            — SELECT COUNT(*) FROM jobs via PDO (database queue driver)
    ├── RedisQueueProbe.php       — LLEN queues:{name} via ext-redis (Redis queue driver)
    └── OpcacheProbe.php          — opcache_get_status() memory usage + hit rate

tests/
├── TestCase.php
├── HealthStatusTest.php
├── HealthResultTest.php
├── HealthRegistryTest.php
├── HealthTest.php                — facade tests
├── HealthControllerTest.php
└── Probe/
    ├── DatabaseProbeTest.php     — SQLite :memory:
    ├── RedisProbeTest.php        — mocked Redis
    ├── QueueProbeTest.php        — SQLite :memory:
    ├── RedisQueueProbeTest.php   — mocked Redis
    └── OpcacheProbeTest.php      — injected status-provider closure
```

---

## Key classes and responsibilities

### HealthStatus (`src/HealthStatus.php`)

Backed string enum (`ok`, `degraded`, `unhealthy`). `fromResults()` derives the worst-case status from a set of probe results. Precedence: `UNHEALTHY` > `DEGRADED` > `OK`.

---

### HealthResult (`src/HealthResult.php`)

Immutable readonly value object. Three static factories — `ok()`, `degraded()`, `unhealthy()` — produce named constructors for the common cases. `toArray()` serialises the result for JSON output (rounds `latencyMs` to 2 decimal places).

---

### ProbeInterface (`src/ProbeInterface.php`)

Single-responsibility contract: `name(): string` (probe identifier used as JSON key) and `check(): HealthResult` (must never throw — catch internally and return UNHEALTHY).

---

### HealthRegistry (`src/HealthRegistry.php`)

Collects a `list<ProbeInterface>` at construction. `run()` iterates and calls `check()` on each; `aggregate()` delegates to `HealthStatus::fromResults()`. Stateless between calls — every `run()` re-executes all probes.

---

### HealthController (`src/HealthController.php`)

Invokable controller resolved from the container. Calls `$registry->run()`, then `$registry->aggregate()`. Encodes the result as JSON, sets `Content-Type: application/json`. Returns HTTP 200 when `OK`, HTTP 503 for `DEGRADED` or `UNHEALTHY`.

---

### Health (`src/Health.php`)

Static facade following the same pattern as `Mail`, `Broadcast`, and `Notification`. Holds `private static ?HealthRegistry $registry`. Initialised by `HealthServiceProvider::boot()`. Throws `RuntimeException` when called before initialisation.

---

### HealthServiceProvider (`src/HealthServiceProvider.php`)

`register()` binds `HealthRegistry` lazily. Probes added conditionally:

| Probe             | Condition                                                                  |
|-------------------|-----------------------------------------------------------------------------|
| `DatabaseProbe`   | `DatabaseInterface` bound in container                                    |
| `RedisProbe`      | `health.redis.host` config present + ext-redis                            |
| `QueueProbe`      | `queue.driver` config is not `'redis'` (defaults to it) + `DatabaseInterface` bound |
| `RedisQueueProbe` | `queue.driver` config is `'redis'` + a connection succeeds via `queue.redis.host`/`queue.redis.port` |
| `OpcacheProbe`     | `health.opcache.enabled` config is `true` (opt-in)                        |
| Custom probes      | Any `ProbeInterface` registered via `TaggedContainerInterface::tag($class, 'health.probe')`; resolved via `tagged('health.probe')` |

All probe setup is wrapped in `try/catch` — unavailable probes are silently skipped.

**Custom probes:** `register()` resolves `EzPhp\Contracts\TaggedContainerInterface` (bound by `Application::foundation()` to the framework `Container`, which implements it) and calls `tagged('health.probe')` on it — the module depends on the contract, not on the concrete `EzPhp\Container\Container`. Register a probe before the health registry is resolved:

```php
$container = $app->make(\EzPhp\Contracts\TaggedContainerInterface::class);
$container->tag(MyCustomProbe::class, 'health.probe');
```

Resolving `TaggedContainerInterface` is wrapped in `try/catch` — a minimal `ContainerInterface` stub (as used in some tests) doesn't bind it, and that's a supported degrade-to-no-custom-probes case, not an error.

`boot()` calls `Health::setRegistry()` and registers `GET /health` on the `Router`. The route registration is also wrapped in `try/catch` to handle CLI and test contexts where the Router is not bound.

---

### DatabaseProbe (`src/Probe/DatabaseProbe.php`)

Issues `SELECT 1` on the injected `PDO`. Returns `OK` on success, `UNHEALTHY` on any exception. Records wall-clock latency in milliseconds. Accepts a custom name for disambiguation (e.g. `'primary_db'`).

---

### RedisProbe (`src/Probe/RedisProbe.php`)

Issues `$redis->ping()` on the injected `\Redis` instance. Accepts `true`, `'+PONG'`, or `'PONG'` (case-insensitive) as success responses. Returns `UNHEALTHY` on any other response or exception. Accepts a custom name.

---

### QueueProbe (`src/Probe/QueueProbe.php`)

Issues `SELECT COUNT(*) FROM jobs` on the injected `PDO`. Returns `OK` with the pending job count on success. Returns `DEGRADED` (not `UNHEALTHY`) when the table does not exist — this means the queue module is not installed, which is a non-critical impairment rather than a failure.

---

### RedisQueueProbe (`src/Probe/RedisQueueProbe.php`)

Issues `LLEN queues:{queueName}` on the injected `\Redis` instance (`queueName` defaults to `'default'`), matching `EzPhp\Queue\Driver\RedisDriver`'s key convention exactly. Returns `OK` with the pending job count on success, `UNHEALTHY` on exception. Counterpart to `QueueProbe` for applications on the Redis queue driver — `QueueProbe` would otherwise report a permanent `DEGRADED` (the `jobs` table never exists on that driver).

---

### OpcacheProbe (`src/Probe/OpcacheProbe.php`)

Calls `opcache_get_status(false)` (via an injectable `Closure` for testing) and reports memory-usage percentage and hit rate. Returns `DEGRADED` when OPcache is disabled/unavailable (e.g. `opcache.enable_cli=0`, or `ext-opcache` not loaded), `OK` with the stats otherwise, `UNHEALTHY` only if the status provider itself throws. Opt-in via config (see below) because a disabled OPcache is the common case on CLI and would otherwise be permanent noise.

---

## Design decisions and constraints

- **`HealthServiceProvider` depends on `ez-php/framework`.** The module registers a route via the framework's `Router`. This is an intentional coupling: the health endpoint exists specifically to service the framework's HTTP layer. Unlike other modules which depend only on `ez-php/contracts`, health is tied to the router lifecycle. A second, narrower coupling is the concrete `EzPhp\Container\Container` (`HealthServiceProvider.php:7`): custom probes are registered with `Container::tag()` and read with `Container::tagged()`, which the `ContainerInterface` in `ez-php/contracts` deliberately does not expose. That lookup is wrapped in `try/catch`, so a container without the concrete class (a minimal stub) simply yields no custom probes; if `tag()`/`tagged()` ever move into the contract, drop this import and the note.
- **Probes are registered only when their dependencies are available.** `try/catch` around each probe setup allows the endpoint to work in minimal configurations (e.g., no database, no Redis). An empty registry still responds with HTTP 200 / `ok`.
- **`QueueProbe` returns DEGRADED (not UNHEALTHY) when the jobs table is missing.** The queue module is optional. A missing jobs table indicates the queue is not installed, not that it has failed. Operators can use this signal to add the queue module without triggering a hard failure alert.
- **`OpcacheProbe` is opt-in via `health.opcache.enabled`, unlike the other probes.** The other probes are gated on a real dependency being available (a bound `DatabaseInterface`, a reachable Redis). OPcache is different: `ext-opcache` is compiled into most PHP builds but commonly *disabled* for CLI (`opcache.enable_cli=0`), including in this project's own test container — an unconditionally-registered probe would permanently report DEGRADED there. Requiring explicit config opt-in keeps the default registry free of that noise while still making the probe available to applications that run OPcache under php-fpm/CLI.
- **Probes must never throw.** The `ProbeInterface` contract requires implementors to catch all exceptions internally. The registry does not wrap `check()` in a try/catch — probes are responsible for their own safety.
- **Latency is wall-clock time only.** `microtime(true)` before and after the probe call. No percentile tracking — this module is intentionally minimal.

---
- **Depends on the concrete framework `Router`** — `ez-php/contracts` has no routing contract, so this module's service provider imports `EzPhp\Routing\Router` (and hence requires `ez-php/framework`) to register `/health`. Deliberate exception to the "depend on contracts only" boundary; it goes away if a `RouterInterface` is ever added to `ez-php/contracts`. `HealthServiceProvider` additionally resolves the concrete `Container` to read `Container::tagged('health.probe')` (custom probes), because tagging is not part of `ContainerInterface`.

## Testing approach

No external infrastructure required. All tests run with SQLite `:memory:` (database and queue probes) and mocked `\Redis` instances.

- `DatabaseProbeTest` — uses a real SQLite `:memory:` PDO; simulates failure with an anonymous subclass that throws
- `RedisProbeTest` — uses `createMock(Redis::class)` to control `ping()` return values
- `QueueProbeTest` — uses a real SQLite `:memory:` PDO; creates/omits the jobs table to test both paths
- `RedisQueueProbeTest` — uses `createStub`/`createMock(Redis::class)` to control `lLen()` return values and assert the `queues:{name}` key
- `OpcacheProbeTest` — injects a `statusProvider` closure returning a controlled status array/`false`/throw, rather than depending on the real `ext-opcache` state
- `HealthControllerTest` — uses anonymous `ProbeInterface` implementations; no HTTP client required
- `HealthStatusTest`, `HealthResultTest`, `HealthRegistryTest`, `HealthTest` — pure unit tests, no infrastructure

---

## What does not belong in this module

- **Metrics or time-series data** — latency is returned per-request only; no aggregation, no Prometheus export. `ez-php/metrics`' `HealthMetricsListener` bridges `HealthRegistry::run()` results into gauges without any change here.
- **Authentication on the /health endpoint** — if the endpoint must be protected, apply middleware in the application's route definition or global middleware
- **Alerting or notification** — use `ez-php/notification` or an external monitoring tool
- **Redis probe configuration** — Redis connection details belong in `config/health.php`, not hardcoded in this module
- **Queue driver implementation** — `QueueProbe`/`RedisQueueProbe` query the jobs table / Redis list directly; neither uses or depends on `ez-php/queue`, they only mirror its storage conventions (`jobs` table, `queues:{name}` key)
