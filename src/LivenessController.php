<?php

declare(strict_types=1);

namespace EzPhp\Health;

use EzPhp\Http\Request;
use EzPhp\Http\Response;

/**
 * Handles the optional liveness route (`health.liveness_path`): answers 200 as
 * long as PHP can serve a request, without running a probe. An orchestrator's
 * liveness check points here, so a database outage does not restart the
 * application; readiness keeps using GET /health.
 */
final class LivenessController
{
    /**
     * Answer that the process is alive.
     */
    public function __invoke(Request $request): Response
    {
        return (new Response('{"status":"ok"}', 200))->withHeader('Content-Type', 'application/json');
    }
}
