<?php

declare(strict_types=1);

namespace Tests\Probe;

use EzPhp\Health\HealthResult;
use EzPhp\Health\HealthStatus;
use EzPhp\Health\Probe\HttpProbe;
use EzPhp\HttpClient\FakeTransport;
use EzPhp\HttpClient\HttpClient;
use EzPhp\HttpClient\HttpClientException;
use EzPhp\HttpClient\HttpResponse;
use EzPhp\HttpClient\TransportInterface;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\TestCase;

/**
 * HttpProbe over ez-php/http-client's FakeTransport — no network.
 *
 * @package Tests\Probe
 */
#[CoversClass(HttpProbe::class)]
#[UsesClass(HealthResult::class)]
#[UsesClass(HealthStatus::class)]
final class HttpProbeTest extends TestCase
{
    private const string URL = 'https://api.example.test/status';

    private function probe(HttpResponse|HttpClientException $answer, int $degradedAfterMs = 0): HttpProbe
    {
        return new HttpProbe(new HttpClient(new FakeTransport([self::URL => $answer])), self::URL, 'payments', 3, $degradedAfterMs);
    }

    public function test_2xx_is_ok_and_the_timeout_reaches_the_transport(): void
    {
        $transport = new FakeTransport([self::URL => HttpResponse::fake('ok', 204)]);
        $result = (new HttpProbe(new HttpClient($transport), self::URL, 'payments', 3))->check();

        $this->assertSame(HealthStatus::OK, $result->status);
        $this->assertSame('HTTP 204', $result->message);
        $this->assertSame('payments', $result->name);
        $this->assertSame('GET', $transport->getRecorded()[0]['method']);
        $this->assertSame(3, $transport->getRecorded()[0]['timeoutSeconds']);
    }

    public function test_status_outside_the_range_is_unhealthy(): void
    {
        $result = $this->probe(HttpResponse::fake('down', 503))->check();

        $this->assertSame(HealthStatus::UNHEALTHY, $result->status);
        $this->assertSame('HTTP 503', $result->message);
    }

    public function test_transport_failure_is_unhealthy(): void
    {
        $result = $this->probe(new HttpClientException('Could not resolve host'))->check();

        $this->assertSame(HealthStatus::UNHEALTHY, $result->status);
        $this->assertSame('Could not resolve host', $result->message);
    }

    public function test_slow_answer_is_degraded(): void
    {
        $slow = new class () implements TransportInterface {
            public function send(string $method, string $url, array $headers, string $body, ?int $timeoutSeconds = null): HttpResponse
            {
                usleep(20_000);

                return HttpResponse::fake('ok');
            }
        };

        $result = (new HttpProbe(new HttpClient($slow), self::URL, degradedAfterMs: 5))->check();

        $this->assertSame(HealthStatus::DEGRADED, $result->status);
        $this->assertStringStartsWith('HTTP 200, slow', $result->message);
    }

    public function test_custom_status_range(): void
    {
        $probe = new HttpProbe(new HttpClient(new FakeTransport([self::URL => HttpResponse::fake('', 401)])), self::URL, minStatus: 200, maxStatus: 499);

        $this->assertSame(HealthStatus::OK, $probe->check()->status);
    }

    public function test_invalid_configuration_is_rejected(): void
    {
        $client = new HttpClient(new FakeTransport());

        foreach ([
            static fn (): HttpProbe => new HttpProbe($client, ''),
            static fn (): HttpProbe => new HttpProbe($client, self::URL, timeoutSeconds: 0),
            static fn (): HttpProbe => new HttpProbe($client, self::URL, minStatus: 300, maxStatus: 200),
        ] as $make) {
            try {
                $make();
                $this->fail('Expected InvalidArgumentException.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
