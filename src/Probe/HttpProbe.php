<?php

declare(strict_types=1);

namespace EzPhp\Health\Probe;

use EzPhp\Health\HealthResult;
use EzPhp\Health\ProbeInterface;
use EzPhp\HttpClient\HttpClient;
use InvalidArgumentException;
use Throwable;

/**
 * Class HttpProbe
 *
 * Health-checks an external HTTP dependency (payment API, Meilisearch, an
 * internal service) with one GET through ez-php/http-client:
 *
 * - transport failure or a status outside the expected range → UNHEALTHY
 * - expected status, but slower than `$degradedAfterMs` → DEGRADED
 * - otherwise → OK
 *
 * The request timeout bounds how long /health can hang on a dead endpoint.
 * Needs ez-php/http-client (a suggest).
 *
 * @package EzPhp\Health\Probe
 */
final class HttpProbe implements ProbeInterface
{
    /**
     * @param HttpClient $client          Client the GET goes through (inject a FakeTransport-backed one in tests).
     * @param string     $url             Endpoint to check, e.g. 'https://api.example.com/status'.
     * @param string     $name            Probe name in the /health response.
     * @param int        $timeoutSeconds  Total request timeout.
     * @param int        $degradedAfterMs Latency above which an OK answer is reported as DEGRADED; 0 = never.
     * @param int        $minStatus       Lowest healthy status code.
     * @param int        $maxStatus       Highest healthy status code.
     *
     * @throws InvalidArgumentException On an empty URL, a timeout below 1 s or an inverted status range.
     */
    public function __construct(
        private readonly HttpClient $client,
        private readonly string $url,
        private readonly string $name = 'http',
        private readonly int $timeoutSeconds = 5,
        private readonly int $degradedAfterMs = 0,
        private readonly int $minStatus = 200,
        private readonly int $maxStatus = 299,
    ) {
        if ($url === '') {
            throw new InvalidArgumentException('HttpProbe needs a URL.');
        }

        if ($timeoutSeconds < 1) {
            throw new InvalidArgumentException('HttpProbe timeout must be at least 1 second.');
        }

        if ($minStatus > $maxStatus) {
            throw new InvalidArgumentException('HttpProbe status range is inverted.');
        }
    }

    /**
     * {@inheritDoc}
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * {@inheritDoc}
     */
    public function check(): HealthResult
    {
        $start = microtime(true);

        try {
            $status = $this->client->get($this->url)->withTimeout($this->timeoutSeconds)->send()->status();
        } catch (Throwable $e) {
            return HealthResult::unhealthy($this->name, $e->getMessage(), (microtime(true) - $start) * 1000);
        }

        $latency = (microtime(true) - $start) * 1000;

        if ($status < $this->minStatus || $status > $this->maxStatus) {
            return HealthResult::unhealthy($this->name, sprintf('HTTP %d', $status), $latency);
        }

        if ($this->degradedAfterMs > 0 && $latency > $this->degradedAfterMs) {
            return HealthResult::degraded($this->name, sprintf('HTTP %d, slow (%.0f ms)', $status, $latency), $latency);
        }

        return HealthResult::ok($this->name, sprintf('HTTP %d', $status), $latency);
    }
}
