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
     * @param HealthRegistry $registry Injected by the container
     */
    public function __construct(private readonly HealthRegistry $registry)
    {
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
            $probes[$name] = $result->toArray();
        }

        $body = json_encode(
            ['status' => $status->value, 'probes' => $probes],
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT
        );

        $httpStatus = $status === HealthStatus::OK ? 200 : 503;

        return (new Response($body, $httpStatus))->withHeader('Content-Type', 'application/json');
    }
}
