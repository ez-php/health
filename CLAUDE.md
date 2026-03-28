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
1. `phpstan analyse` — static analysis, level 9, config: `phpstan.neon`
2. `php-cs-fixer fix` — auto-fixes style (`@PSR12` + `@PHP83Migration` + strict rules)
3. `phpunit` — all tests with coverage

Individual commands when needed:
```
composer analyse   # PHPStan only
composer cs        # CS Fixer only
composer test      # PHPUnit only
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

### 3 — Docker scaffold

Run from the new module root (requires `"ez-php/docker": "0.*"` in `require-dev`):

```
vendor/bin/docker-init
```

This copies `Dockerfile`, `docker-compose.yml`, `.env.example`, `start.sh`, and `docker/` into the module, replacing `{{MODULE_NAME}}` placeholders. Existing files are never overwritten.

After scaffolding:

1. Adapt `docker-compose.yml` — add or remove services (MySQL, Redis) as needed
2. Adapt `.env.example` — fill in connection defaults matching the services above
3. Assign a unique host port for each exposed service (see table below)

**Allocated host ports:**

| Package | `DB_HOST_PORT` (MySQL) | `REDIS_PORT` |
|---|---|---|
| root (`ez-php-project`) | 3306 | 6379 |
| `ez-php/framework` | 3307 | — |
| `ez-php/orm` | 3309 | — |
| `ez-php/cache` | — | 6380 |
| **next free** | **3310** | **6381** |

Only set a port for services the module actually uses. Modules without external services need no port config.

### 4 — Monorepo scripts

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
    └── QueueProbe.php            — SELECT COUNT(*) FROM jobs via PDO

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
    └── QueueProbeTest.php        — SQLite :memory:
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

| Probe          | Condition                                     |
|----------------|-----------------------------------------------|
| `DatabaseProbe`| `DatabaseInterface` bound in container        |
| `RedisProbe`   | `health.redis.host` config present + ext-redis|
| `QueueProbe`   | `DatabaseInterface` bound in container        |

All probe setup is wrapped in `try/catch` — unavailable probes are silently skipped.

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

## Design decisions and constraints

- **`HealthServiceProvider` depends on `ez-php/framework`.** The module registers a route via the framework's `Router`. This is an intentional coupling: the health endpoint exists specifically to service the framework's HTTP layer. Unlike other modules which depend only on `ez-php/contracts`, health is tied to the router lifecycle.
- **Probes are registered only when their dependencies are available.** `try/catch` around each probe setup allows the endpoint to work in minimal configurations (e.g., no database, no Redis). An empty registry still responds with HTTP 200 / `ok`.
- **`QueueProbe` returns DEGRADED (not UNHEALTHY) when the jobs table is missing.** The queue module is optional. A missing jobs table indicates the queue is not installed, not that it has failed. Operators can use this signal to add the queue module without triggering a hard failure alert.
- **Probes must never throw.** The `ProbeInterface` contract requires implementors to catch all exceptions internally. The registry does not wrap `check()` in a try/catch — probes are responsible for their own safety.
- **Latency is wall-clock time only.** `microtime(true)` before and after the probe call. No percentile tracking — this module is intentionally minimal.

---

## Testing approach

No external infrastructure required. All tests run with SQLite `:memory:` (database and queue probes) and mocked `\Redis` instances.

- `DatabaseProbeTest` — uses a real SQLite `:memory:` PDO; simulates failure with an anonymous subclass that throws
- `RedisProbeTest` — uses `createMock(Redis::class)` to control `ping()` return values
- `QueueProbeTest` — uses a real SQLite `:memory:` PDO; creates/omits the jobs table to test both paths
- `HealthControllerTest` — uses anonymous `ProbeInterface` implementations; no HTTP client required
- `HealthStatusTest`, `HealthResultTest`, `HealthRegistryTest`, `HealthTest` — pure unit tests, no infrastructure

---

## What does not belong in this module

- **Metrics or time-series data** — latency is returned per-request only; no aggregation, no Prometheus export
- **Authentication on the /health endpoint** — if the endpoint must be protected, apply middleware in the application's route definition or global middleware
- **Alerting or notification** — use `ez-php/notification` or an external monitoring tool
- **Redis probe configuration** — Redis connection details belong in `config/health.php`, not hardcoded in this module
- **Queue driver implementation** — `QueueProbe` queries the jobs table directly via PDO; it does not use or depend on `ez-php/queue`
