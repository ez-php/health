<?php

declare(strict_types=1);

namespace EzPhp\Health;

use EzPhp\Http\Request;
use EzPhp\Http\Response;

/**
 * Handles GET /health — runs all registered probes and returns a JSON report.
 *
 * HTTP 200 when all probes are OK; HTTP 503 when any probe is DEGRADED or UNHEALTHY.
 *
 * The endpoint is public by default, and a failing probe's message is the raw
 * exception text (PDO errors with user and host, cURL errors with internal
 * URLs). With `$exposeMessages` false the messages of DEGRADED/UNHEALTHY probes
 * are replaced by a generic text; status and latency stay. The provider sets it
 * from `health.expose_messages` (default: `app.debug`).
 *
 * Response body:
 * {
 *   "status": "ok|degraded|unhealthy",
 *   "probes": {
 *     "<name>": { "status": "...", "message": "...", "latency_ms": 0.0 },
 *     ...
 *   }
 * }
 */
final class HealthController
{
    /**
     * @param HealthRegistry $registry       Injected by the container
     * @param bool           $exposeMessages Show the probes' own messages for DEGRADED/UNHEALTHY results.
     */
    public function __construct(
        private readonly HealthRegistry $registry,
        private readonly bool $exposeMessages = true,
    ) {
    }

    /**
     * Execute the health check and emit the JSON response.
     */
    public function __invoke(Request $request): Response
    {
        $results = $this->registry->run();
        $status = $this->registry->aggregate($results);

        $probes = [];
        foreach ($results as $name => $result) {
            $probe = $result->toArray();

            if (!$this->exposeMessages && $result->status !== HealthStatus::OK) {
                $probe['message'] = $result->status === HealthStatus::DEGRADED ? 'Degraded' : 'Check failed';
            }

            $probes[$name] = $probe;
        }

        $body = json_encode(
            ['status' => $status->value, 'probes' => $probes],
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT
        );

        $httpStatus = $status === HealthStatus::OK ? 200 : 503;

        return (new Response($body, $httpStatus))->withHeader('Content-Type', 'application/json');
    }
}
