# Changelog

All notable changes to `ez-php/health` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

---

## [v1.1.0] — 2026-03-28

### Added
- `ProbeInterface` — contract for health probes: `name()` and `check()` returning `HealthResult`
- `HealthResult` — value object with status (`ok`, `degraded`, `unhealthy`), message, and latency in milliseconds; factory methods `ok()`, `degraded()`, `unhealthy()`
- `HealthStatus` — backed enum: `OK`, `DEGRADED`, `UNHEALTHY`
- `HealthRegistry` — holds a list of `ProbeInterface` instances; `run()` returns all results; `status()` returns the aggregate `HealthStatus`
- `DatabaseProbe` — runs `SELECT 1` against the bound `DatabaseInterface`; auto-skipped if not bound
- `RedisProbe` — sends `PING` to the configured Redis host; requires `health.redis.host` in config; auto-skipped if not configured
- `QueueProbe` — queries `SELECT COUNT(*) FROM jobs`; auto-skipped if `DatabaseInterface` is not bound
- `HealthController` — serves `GET /health`; returns JSON with per-probe results; HTTP 200 on `ok`, HTTP 503 on `degraded` or `unhealthy`
- `Health` — static façade: `Health::check()` and `Health::status()`
- `HealthServiceProvider` — registers route, controller, registry, and default probes; probes that cannot be set up are silently skipped
