<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Health\HealthController;
use EzPhp\Health\HealthRegistry;
use EzPhp\Health\HealthResult;
use EzPhp\Health\HealthStatus;
use EzPhp\Health\ProbeInterface;
use EzPhp\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(HealthController::class)]
#[UsesClass(HealthRegistry::class)]
#[UsesClass(HealthResult::class)]
#[UsesClass(HealthStatus::class)]
final class HealthControllerTest extends TestCase
{
    public function testReturns200WhenAllProbesOk(): void
    {
        $probe = $this->makeProbe('db', HealthResult::ok('db', 'connected', 1.0));
        $registry = new HealthRegistry([$probe]);
        $controller = new HealthController($registry);

        $response = $controller($this->makeRequest());

        self::assertSame(200, $response->status());
    }

    public function testReturns503WhenAnyProbeUnhealthy(): void
    {
        $probe = $this->makeProbe('db', HealthResult::unhealthy('db', 'connection refused'));
        $registry = new HealthRegistry([$probe]);
        $controller = new HealthController($registry);

        $response = $controller($this->makeRequest());

        self::assertSame(503, $response->status());
    }

    public function testReturns503WhenAnyProbeDegraded(): void
    {
        $probe = $this->makeProbe('redis', HealthResult::degraded('redis', 'slow', 200.0));
        $registry = new HealthRegistry([$probe]);
        $controller = new HealthController($registry);

        $response = $controller($this->makeRequest());

        self::assertSame(503, $response->status());
    }

    public function testResponseBodyContainsStatusAndProbes(): void
    {
        $probe = $this->makeProbe('db', HealthResult::ok('db', 'connected', 2.5));
        $registry = new HealthRegistry([$probe]);
        $controller = new HealthController($registry);

        $response = $controller($this->makeRequest());
        /** @var array{status: string, probes: array<string, array{status: string, message: string, latency_ms: float}>} $body */
        $body = json_decode($response->body(), true);

        self::assertSame('ok', $body['status']);
        self::assertArrayHasKey('db', $body['probes']);
        self::assertSame('ok', $body['probes']['db']['status']);
        self::assertSame('connected', $body['probes']['db']['message']);
    }

    public function testResponseBodyAggregatesMultipleProbes(): void
    {
        $probes = [
            $this->makeProbe('db', HealthResult::ok('db', 'connected', 1.0)),
            $this->makeProbe('redis', HealthResult::degraded('redis', 'slow', 150.0)),
        ];
        $registry = new HealthRegistry($probes);
        $controller = new HealthController($registry);

        $response = $controller($this->makeRequest());
        /** @var array{status: string, probes: array<string, array{status: string, message: string, latency_ms: float}>} $body */
        $body = json_decode($response->body(), true);

        self::assertSame('degraded', $body['status']);
        self::assertArrayHasKey('db', $body['probes']);
        self::assertArrayHasKey('redis', $body['probes']);
    }

    public function testContentTypeIsJson(): void
    {
        $registry = new HealthRegistry([]);
        $controller = new HealthController($registry);

        $response = $controller($this->makeRequest());

        self::assertSame('application/json', $response->headers()['Content-Type']);
    }

    public function testEmptyRegistryReturns200WithOkStatus(): void
    {
        $registry = new HealthRegistry([]);
        $controller = new HealthController($registry);

        $response = $controller($this->makeRequest());
        /** @var array{status: string, probes: array<string, mixed>} $body */
        $body = json_decode($response->body(), true);

        self::assertSame(200, $response->status());
        self::assertSame('ok', $body['status']);
        self::assertSame([], $body['probes']);
    }

    private function makeRequest(): Request
    {
        return new Request('GET', '/health');
    }

    private function makeProbe(string $name, HealthResult $result): ProbeInterface
    {
        return new class ($name, $result) implements ProbeInterface {
            public function __construct(
                private readonly string $probeName,
                private readonly HealthResult $probeResult,
            ) {
            }

            public function name(): string
            {
                return $this->probeName;
            }

            public function check(): HealthResult
            {
                return $this->probeResult;
            }
        };
    }
}
